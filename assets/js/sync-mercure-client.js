/**
 * sync-mercure client SDK
 *
 * Generic browser-side Mercure subscriber. Any consumer plugin that opts into
 * the sync transport pipeline and lands on the mercure transport gets back a
 * clientConfig blob from the server (see MercureTransport::clientConfig) and
 * passes it to this module. The module owns the hub connection,
 * topic-discriminated routing, Last-Event-ID-driven reconnect, terminal-error
 * failover handoff, and (optionally) typing presence with heartbeat plus TTL
 * eviction.
 *
 * The connection is a streaming fetch rather than an EventSource, so the JWT
 * travels in the Authorization header. Mercure 1.x removed the
 * `authorization` query parameter EventSource depended on, and the header
 * works on 0.x hubs too. `config.protocol` (from the server) picks the
 * subscribe parameter: 1 subscribes with `match=` (Mercure 1.x), 0 or
 * missing with `topic=` (0.x). A hub that answers 400 to one is retried
 * with the other, so a consumer that doesn't pass `protocol` still works.
 * Streaming fetch code adapted from @Gleydar's Mercure 1.x fork.
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
 *         protocol?: number,        // 1 = Mercure 1.x, 0 = 0.x
 *         // Consumer-supplied extras (filled in by the caller before init):
 *         typingPostUrl?: string,
 *         heartbeatSeconds?: number
 *       }
 *     handlers: { onUpdate?, onFailover?, onTypingChange? }
 *       Both `handlers` and `config` are passed in by the consumer plugin;
 *       this SDK does not know which plugin it is serving.
 *       onFailover fires when the hub keeps failing, and right away when it
 *       refuses the JWT (401/403, usually an expired token). The consumer
 *       then fetches a fresh config, which mints a fresh JWT, and calls
 *       init() again, or falls back to polling.
 *
 *   sendTyping(action, extras): void
 *     action is 'start' or 'stop'. Throttled to once per heartbeatSeconds for
 *     'start'; 'stop' always sends. Heartbeat re-POSTs while active.
 *     extras is an optional plain object whose keys are merged into the POST
 *     body (e.g. { parent_id: '123' }) — the SDK is agnostic to their meaning
 *     and the server is expected to relay them back in the broadcast payload.
 *
 *   disconnect(): void
 *     Closes the hub connection, clears all timers, and forgets typing state.
 *     Safe to call multiple times.
 */
(function() {
  'use strict';

  // Module-private state. A single subscription per page is supported.
  var stream = null;              // active { abort } handle, or null
  var retryTimer = null;          // pending reconnect timer
  var connectionId = 0;           // bumps on init/disconnect so stale streams stand down
  var lastEventId = '';           // resume point sent as Last-Event-ID on reconnect
  var retryMs = 1000;             // reconnect delay; the hub may set it with `retry:`
  var useMatch = false;           // subscribe parameter: true = match= (1.x), false = topic= (0.x)
  var consecutiveErrors = 0;      // reconnect-failure counter for failover
  var failedOver = false;         // latches once we hand off to polling
  var typingMap = null;           // Map<userId, {ts, meta}> — meta = server-relayed extras
  var typingTickTimer = null;     // 1s eviction tick timer
  var typingHeartbeatTimer = null; // local "I am typing" heartbeat timer
  var lastTypingSendTs = 0;       // throttle guard for sendTyping
  var lastTypingAction = null;    // 'start' | 'stop' | null
  var lastTypingExtras = null;    // extras carried with the active 'start' so heartbeats keep the same scope
  var activeConfig = null;
  var activeHandlers = null;

  function buildHubUrl(config) {
    var url = new URL(config.hubUrl, window.location.href);
    // A token or subscribe parameter baked into the configured hub URL
    // would leak into logs or clash with ours, so strip them.
    ['authorization', 'access_token', 'topic', 'match', 'lastEventID', 'last_event_id', 'Last-Event-ID'].forEach(function(k) {
      url.searchParams.delete(k);
    });
    var param = useMatch ? 'match' : 'topic';
    url.searchParams.append(param, config.topics.main);
    if (config.topics.typing) {
      url.searchParams.append(param, config.topics.typing);
    }
    return url.toString();
  }

  // Server-Sent Events parser over a fetch body. Calls onEvent(data) for
  // every complete event with data and no type (or type "message"); updates
  // with a custom type aren't ours. Remembers the last event id.
  function readEvents(body, id, onEvent) {
    var reader = body.getReader();
    var decoder = new TextDecoder();
    var pending = '';
    var data = [];
    var eventType = '';
    var eventId = null;

    function dispatch() {
      if (eventId !== null) lastEventId = eventId;
      if (data.length && (eventType === '' || eventType === 'message')) {
        onEvent(data.join('\n'));
      }
      data = [];
      eventType = '';
      eventId = null;
    }

    function parseLine(line) {
      if (line.endsWith('\r')) line = line.slice(0, -1);
      if (line === '') { dispatch(); return; }
      if (line[0] === ':') return;
      var colon = line.indexOf(':');
      var field = colon < 0 ? line : line.slice(0, colon);
      var value = colon < 0 ? '' : line.slice(colon + 1);
      if (value[0] === ' ') value = value.slice(1);
      if (field === 'data') data.push(value);
      else if (field === 'event') eventType = value;
      else if (field === 'id' && value.indexOf('\0') < 0) eventId = value;
      else if (field === 'retry' && /^\d+$/.test(value)) retryMs = Number(value);
    }

    function read() {
      return reader.read().then(function(result) {
        if (id !== connectionId) {
          try { reader.cancel(); } catch (e) {}
          return;
        }
        if (result.done) {
          pending += decoder.decode();
          if (pending) parseLine(pending);
          throw new Error('Mercure stream closed');
        }
        pending += decoder.decode(result.value, { stream: true });
        var end;
        while ((end = pending.indexOf('\n')) !== -1) {
          parseLine(pending.slice(0, end));
          pending = pending.slice(end + 1);
        }
        return read();
      });
    }
    return read();
  }

  function connect(config, id, flipped) {
    if (id !== connectionId || failedOver) return;
    var controller = new AbortController();
    stream = controller;
    var headers = { Accept: 'text/event-stream', Authorization: 'Bearer ' + config.jwt };
    // 1.x renamed the reconnect query parameter, but this request header
    // kept its name on both generations.
    if (lastEventId) headers['Last-Event-ID'] = lastEventId;

    fetch(buildHubUrl(config), {
      headers: headers,
      signal: controller.signal,
      credentials: 'omit',
      cache: 'no-store',
      // A redirect would drop the Authorization header (cross-origin) or
      // forward it somewhere else; the hub URL has to be the real endpoint.
      redirect: 'error'
    }).then(function(response) {
      if (id !== connectionId) return;
      if (response.status === 400 && !flipped) {
        // The hub doesn't speak the protocol we guessed: 0.x rejects
        // `match=`, a modern 1.x hub rejects `topic=`. Try the other once.
        useMatch = !useMatch;
        connect(config, id, true);
        return;
      }
      if (response.status === 401 || response.status === 403) {
        // The hub refused the JWT (expired, or not for these topics).
        // Reconnecting with the same token can't help: hand off so the
        // consumer fetches a fresh config (and with it a fresh token).
        console.warn('SyncMercure: hub refused the token (HTTP ' + response.status + ').');
        failOver();
        return;
      }
      if (!response.ok || !response.body) throw new Error('Mercure HTTP ' + response.status);
      consecutiveErrors = 0;
      retryMs = 1000;
      return readEvents(response.body, id, function(data) {
        onMercureMessage({ data: data });
      });
    }).catch(function(error) {
      if (id !== connectionId || controller.signal.aborted || failedOver) return;
      stream = null;
      console.warn('SyncMercure: Mercure stream failed', error);
      onMercureError();
      if (!failedOver && id === connectionId) {
        retryTimer = setTimeout(function() { retryTimer = null; connect(config, id, false); }, retryMs);
        retryMs = Math.min(retryMs * 2, 30000);
      }
    });
  }

  function notifyTypingChange() {
    if (!activeHandlers || typeof activeHandlers.onTypingChange !== 'function') return;
    if (!typingMap) return;
    try {
      // Pass an array of {userId, ...meta}: the SDK keeps userId as the
      // canonical key but lets each consumer plugin attach its own context
      // (e.g. comments-pro reads parent_id and displayName from meta).
      var users = [];
      typingMap.forEach(function(entry, userId) {
        var u = { userId: userId };
        if (entry && entry.meta) {
          Object.keys(entry.meta).forEach(function(k) {
            if (k !== 'userId') u[k] = entry.meta[k];
          });
        }
        users.push(u);
      });
      activeHandlers.onTypingChange(users);
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
      typingMap.forEach(function(entry, k) {
        if (entry.ts < cutoff) {
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

  // Topic routing: an SSE event does not say which topic it arrived
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
        // Stash every server-supplied field other than the wire-protocol keys
        // as `meta`, so consumer plugins can read e.g. parent_id/displayName
        // back out via onTypingChange without this SDK needing to know them.
        var meta = {};
        Object.keys(payload).forEach(function(k) {
          if (k !== 'action' && k !== 'userId' && k !== 'user' && k !== 'ts') {
            meta[k] = payload[k];
          }
        });
        typingMap.set(userId, {
          ts: payload.ts ? (payload.ts * 1000) : Date.now(),
          meta: meta
        });
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
    consecutiveErrors++;
    if (consecutiveErrors >= 2) {
      console.warn('SyncMercure: Mercure connection failing; handing off to polling.');
      failOver();
    }
  }

  function failOver() {
    if (failedOver) return;
    failedOver = true;
    if (retryTimer) clearTimeout(retryTimer);
    retryTimer = null;
    if (stream) {
      try { stream.abort(); } catch (e) {}
    }
    stream = null;
    if (activeHandlers && typeof activeHandlers.onFailover === 'function') {
      try { activeHandlers.onFailover(); } catch (e) {}
    }
  }

  window.SyncMercure = {
    init: function(config, handlers) {
      try {
        if (!config || !config.hubUrl || !config.jwt || !config.topics || !config.topics.main) {
          console.warn('SyncMercure: config incomplete; skipping.');
          return false;
        }
        if (typeof window.fetch !== 'function' || typeof window.ReadableStream === 'undefined'
            || typeof window.AbortController === 'undefined' || typeof window.TextDecoder === 'undefined') {
          console.warn('SyncMercure: streaming fetch not supported; Mercure unavailable.');
          if (handlers && typeof handlers.onFailover === 'function') {
            try { handlers.onFailover(); } catch (e) {}
          }
          return false;
        }
        if (stream || retryTimer) {
          // Already initialized; ignore duplicate init.
          return true;
        }
        activeConfig = config;
        activeHandlers = handlers || {};
        consecutiveErrors = 0;
        failedOver = false;
        if (!typingMap) typingMap = new Map();
        retryMs = 1000;
        useMatch = Number(config.protocol || 0) >= 1;
        // lastEventId survives a failover and re-init on purpose: the new
        // stream (with a fresh token) resumes where the old one stopped.
        connect(config, ++connectionId, false);

        if (config.topics.typing) {
          startTypingTick(config.heartbeatSeconds || 5);
        }
        return true;
      } catch (e) {
        console.warn('SyncMercure: init error', e);
        return false;
      }
    },

    sendTyping: function(action, extras) {
      try {
        if (!activeConfig || !activeConfig.typingPostUrl) return;
        if (action !== 'start' && action !== 'stop') return;

        var heartbeatSeconds = activeConfig.heartbeatSeconds || 5;
        var now = Date.now();
        var safeExtras = (extras && typeof extras === 'object') ? extras : null;

        // Throttle: never POST more than once per heartbeatSeconds.
        if (action === 'start' && (now - lastTypingSendTs) < (heartbeatSeconds * 1000)) {
          // Still ensure the heartbeat is running; just skip this POST. Keep
          // the latest extras so the heartbeat re-sends them, in case the
          // caller switched scope (e.g. focused a different reply form).
          lastTypingExtras = safeExtras;
          if (!typingHeartbeatTimer) {
            scheduleHeartbeat(heartbeatSeconds);
          }
          lastTypingAction = 'start';
          return;
        }

        lastTypingSendTs = now;
        lastTypingAction = action;
        lastTypingExtras = (action === 'start') ? safeExtras : null;

        var body = { action: action, ts: now };
        if (safeExtras) {
          Object.keys(safeExtras).forEach(function(k) {
            if (k !== 'action' && k !== 'ts') body[k] = safeExtras[k];
          });
        }

        fetch(activeConfig.typingPostUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body)
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
        connectionId++;
        if (retryTimer) clearTimeout(retryTimer);
        retryTimer = null;
        if (stream) {
          try { stream.abort(); } catch (e) {}
        }
      } finally {
        stream = null;
        lastEventId = '';
        clearHeartbeat();
        stopTypingTick();
        if (typingMap) typingMap.clear();
        typingMap = null;
        lastTypingAction = null;
        lastTypingExtras = null;
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
      var body = { action: 'start', ts: lastTypingSendTs };
      if (lastTypingExtras) {
        Object.keys(lastTypingExtras).forEach(function(k) {
          if (k !== 'action' && k !== 'ts') body[k] = lastTypingExtras[k];
        });
      }
      fetch(activeConfig.typingPostUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
      }).catch(function(e) {
        console.warn('SyncMercure: typing heartbeat POST failed', e);
      });
    }, heartbeatSeconds * 1000);
  }

  // Best-effort cleanup on unload so the hub sees the connection go away.
  window.addEventListener('beforeunload', function() {
    try {
      if (window.SyncMercure && window.SyncMercure.disconnect) {
        window.SyncMercure.disconnect();
      }
    } catch (e) {}
  });
})();
