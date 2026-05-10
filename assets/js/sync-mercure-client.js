/**
 * sync-mercure client SDK
 *
 * Generic browser-side Mercure subscriber. Any consumer plugin that opts into
 * the sync transport pipeline and lands on the mercure transport gets back a
 * clientConfig blob from the server (see MercureTransport::clientConfig) and
 * passes it to this module. The module owns the EventSource connection,
 * topic-discriminated routing, Last-Event-ID-driven reconnect, terminal-error
 * failover handoff, and (optionally) typing presence with heartbeat plus TTL
 * eviction.
 *
 * Public API (window.SyncMercure):
 *   init(config, handlers): boolean
 *     config: shape returned by MercureTransport::clientConfig, e.g.
 *       {
 *         transport: 'mercure',
 *         hubUrl: string,
 *         jwt: string,
 *         topics: [string, ...] | { main: string, typing?: string },
 *         apiVersion: number,
 *         // Consumer-supplied extras (filled in by the caller before init):
 *         typingPostUrl?: string,
 *         heartbeatSeconds?: number
 *       }
 *     handlers: { onUpdate?, onFailover?, onTypingChange? }
 *       Both `handlers` and `config` are passed in by the consumer plugin;
 *       this SDK does not know which plugin it is serving.
 *
 *   sendTyping(action): void
 *     action is 'start' or 'stop'. Throttled to once per heartbeatSeconds for
 *     'start'; 'stop' always sends. Heartbeat re-POSTs while active.
 *
 *   disconnect(): void
 *     Closes the EventSource, clears all timers, and forgets typing state.
 *     Safe to call multiple times.
 */
(function() {
  'use strict';

  // Module-private state. A single subscription per page is supported.
  var es = null;                  // active EventSource, or null
  var consecutiveErrors = 0;      // reconnect-failure counter for failover
  var failedOver = false;         // latches once we hand off to polling
  var typingMap = null;           // Map<userId, lastSeenTs>
  var typingTickTimer = null;     // 1s eviction tick timer
  var typingHeartbeatTimer = null; // local "I am typing" heartbeat timer
  var lastTypingSendTs = 0;       // throttle guard for sendTyping
  var lastTypingAction = null;    // 'start' | 'stop' | null
  var activeConfig = null;
  var activeHandlers = null;

  function buildHubUrl(config) {
    // EventSource cannot set Authorization headers, so per Mercure spec the
    // JWT is passed as the `authorization` query parameter for cross-origin
    // clients (Mercure also accepts a same-origin `mercureAuthorization`
    // cookie, but query-param auth is more portable).
    var url = new URL(config.hubUrl);
    url.searchParams.append('topic', config.topics.main);
    if (config.topics.typing) {
      url.searchParams.append('topic', config.topics.typing);
    }
    if (config.jwt) {
      url.searchParams.append('authorization', config.jwt);
    }
    return url.toString();
  }

  function notifyTypingChange() {
    if (!activeHandlers || typeof activeHandlers.onTypingChange !== 'function') return;
    if (!typingMap) return;
    try {
      var keys = [];
      typingMap.forEach(function(_v, k) { keys.push(k); });
      activeHandlers.onTypingChange(keys);
    } catch (e) {
      console.warn('SyncMercure: onTypingChange handler threw', e);
    }
  }

  function startTypingTick(heartbeatSeconds) {
    if (typingTickTimer) return;
    var ttlMs = 2 * (heartbeatSeconds || 5) * 1000;
    typingTickTimer = setInterval(function() {
      if (!typingMap) return;
      var cutoff = Date.now() - ttlMs;
      var changed = false;
      typingMap.forEach(function(lastSeenTs, k) {
        if (lastSeenTs < cutoff) {
          typingMap.delete(k);
          changed = true;
        }
      });
      if (changed) notifyTypingChange();
    }, 1000);
  }

  function stopTypingTick() {
    if (typingTickTimer) {
      clearInterval(typingTickTimer);
      typingTickTimer = null;
    }
  }

  function clearHeartbeat() {
    if (typingHeartbeatTimer) {
      clearInterval(typingHeartbeatTimer);
      typingHeartbeatTimer = null;
    }
  }

  // Translate Mercure main-events into the polling-style payload that
  // consumer onUpdate handlers already consume. This keeps the
  // downstream UI handler untouched.
  //
  // Source (server-defined Mercure payload):
  //   { event: 'comment_added' | 'comment_updated' | 'comment_deleted'
  //          | 'comment_vote'  | 'comment_status',
  //     data: { id?: string, ... }, ts: number }
  //
  // Target (what handleUpdate expects):
  //   { hasUpdates: true,
  //     changes: { added: [...], updated: [...], deleted: [...] },
  //     lastModified: ISOString }
  function translateMainEvent(payload) {
    var ev = payload && payload.event;
    var data = (payload && payload.data) || {};
    var id = data.id || data.commentId || null;
    var changes = { added: [], updated: [], deleted: [] };
    if (ev === 'comment_added') {
      if (id) changes.added.push(id);
    } else if (ev === 'comment_updated' || ev === 'comment_status' || ev === 'comment_vote') {
      if (id) changes.updated.push(id);
      // For events without an id (status sweeps), still flag updated so the
      // UI refreshes its list.
      if (!id) changes.updated.push('__bulk__');
    } else if (ev === 'comment_deleted') {
      if (id) changes.deleted.push(id);
    } else {
      return null;
    }
    var tsMs = (typeof payload.ts === 'number') ? (payload.ts * 1000) : Date.now();
    return {
      hasUpdates: true,
      changes: changes,
      lastModified: new Date(tsMs).toISOString()
    };
  }

  // Topic routing: EventSource does not expose which topic an event arrived
  // on, so we discriminate on the payload shape. Per the server contract,
  // main events carry an `event` field while typing events carry an
  // `action` field. Anything else is ignored.
  function onMercureMessage(rawEvent) {
    consecutiveErrors = 0; // a successful message proves the connection is live
    var payload;
    try {
      payload = JSON.parse(rawEvent.data);
    } catch (e) {
      return;
    }
    if (!payload || typeof payload !== 'object') return;

    if (typeof payload.event === 'string') {
      var update = translateMainEvent(payload);
      if (update && activeHandlers && typeof activeHandlers.onUpdate === 'function') {
        try {
          activeHandlers.onUpdate(update);
        } catch (e) {
          console.warn('SyncMercure: onUpdate handler threw', e);
        }
      }
      return;
    }

    if (typeof payload.action === 'string') {
      if (!typingMap) typingMap = new Map();
      var userId = payload.userId || payload.user || null;
      if (!userId) return;
      if (payload.action === 'start') {
        typingMap.set(userId, payload.ts ? (payload.ts * 1000) : Date.now());
        notifyTypingChange();
      } else if (payload.action === 'stop') {
        if (typingMap.delete(userId)) {
          notifyTypingChange();
        }
      }
    }
  }

  function onMercureError() {
    if (failedOver) return;
    // EventSource transitions to CLOSED only on terminal failure (e.g. the
    // hub returns 401). When readyState is CONNECTING the browser is
    // already retrying, and `Last-Event-ID` will be sent automatically.
    if (es && es.readyState === 2 /* CLOSED */) {
      consecutiveErrors++;
    } else {
      consecutiveErrors++;
    }
    if (consecutiveErrors >= 2) {
      failedOver = true;
      try { if (es) es.close(); } catch (e) {}
      es = null;
      console.warn('SyncMercure: Mercure connection failing; handing off to polling.');
      if (activeHandlers && typeof activeHandlers.onFailover === 'function') {
        try { activeHandlers.onFailover(); } catch (e) {}
      }
    }
  }

  window.SyncMercure = {
    init: function(config, handlers) {
      try {
        if (!config || !config.hubUrl || !config.topics || !config.topics.main) {
          console.warn('SyncMercure: config incomplete; skipping.');
          return false;
        }
        if (typeof window.EventSource === 'undefined') {
          console.warn('SyncMercure: EventSource not supported; Mercure unavailable.');
          if (handlers && typeof handlers.onFailover === 'function') {
            try { handlers.onFailover(); } catch (e) {}
          }
          return false;
        }
        if (es) {
          // Already initialized; ignore duplicate init.
          return true;
        }
        activeConfig = config;
        activeHandlers = handlers || {};
        consecutiveErrors = 0;
        failedOver = false;
        typingMap = new Map();

        var url = buildHubUrl(config);
        es = new EventSource(url);
        es.onmessage = onMercureMessage;
        es.onerror = onMercureError;

        if (config.topics.typing) {
          startTypingTick(config.heartbeatSeconds || 5);
        }
        return true;
      } catch (e) {
        console.warn('SyncMercure: init error', e);
        return false;
      }
    },

    sendTyping: function(action) {
      try {
        if (!activeConfig || !activeConfig.typingPostUrl) return;
        if (action !== 'start' && action !== 'stop') return;

        var heartbeatSeconds = activeConfig.heartbeatSeconds || 5;
        var now = Date.now();

        // Throttle: never POST more than once per heartbeatSeconds.
        if (action === 'start' && (now - lastTypingSendTs) < (heartbeatSeconds * 1000)) {
          // Still ensure the heartbeat is running; just skip this POST.
          if (!typingHeartbeatTimer) {
            scheduleHeartbeat(heartbeatSeconds);
          }
          lastTypingAction = 'start';
          return;
        }

        lastTypingSendTs = now;
        lastTypingAction = action;

        fetch(activeConfig.typingPostUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: action, ts: now })
        }).catch(function(e) {
          // Silent fail; typing is best-effort.
          console.warn('SyncMercure: typing POST failed', e);
        });

        if (action === 'start') {
          if (!typingHeartbeatTimer) {
            scheduleHeartbeat(heartbeatSeconds);
          }
        } else {
          // 'stop' clears the heartbeat so no orphan timer survives.
          clearHeartbeat();
        }
      } catch (e) {
        console.warn('SyncMercure: sendTyping error', e);
      }
    },

    disconnect: function() {
      try {
        if (es) {
          try { es.close(); } catch (e) {}
        }
      } finally {
        es = null;
        clearHeartbeat();
        stopTypingTick();
        if (typingMap) typingMap.clear();
        typingMap = null;
        lastTypingAction = null;
        lastTypingSendTs = 0;
        consecutiveErrors = 0;
        // Keep failedOver as-is so we don't accidentally re-init mid-failover.
      }
    }
  };

  function scheduleHeartbeat(heartbeatSeconds) {
    clearHeartbeat();
    typingHeartbeatTimer = setInterval(function() {
      if (lastTypingAction !== 'start') {
        clearHeartbeat();
        return;
      }
      if (!activeConfig || !activeConfig.typingPostUrl) {
        clearHeartbeat();
        return;
      }
      lastTypingSendTs = Date.now();
      fetch(activeConfig.typingPostUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'start', ts: lastTypingSendTs })
      }).catch(function(e) {
        console.warn('SyncMercure: typing heartbeat POST failed', e);
      });
    }, heartbeatSeconds * 1000);
  }

  // Best-effort cleanup on unload so the hub sees the EventSource go away.
  window.addEventListener('beforeunload', function() {
    try {
      if (window.SyncMercure && window.SyncMercure.disconnect) {
        window.SyncMercure.disconnect();
      }
    } catch (e) {}
  });
})();
