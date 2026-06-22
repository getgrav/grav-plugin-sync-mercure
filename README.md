# Sync Mercure (Grav Plugin)

Real-time collaborative editing transport for [grav-plugin-sync] using a
[Mercure] SSE hub. Replaces the polling-based default (1s cadence, ~1s
latency) with live server-sent events (~50–100ms latency) and live remote
cursors.

[grav-plugin-sync]: https://github.com/getgrav/grav-plugin-sync
[Mercure]: https://mercure.rocks

---

## What it does

When the plugin is enabled and a hub URL is configured:

- The Sync API advertises `mercure` in its `/sync/capabilities` response,
  with `preferred: "mercure"`.
- Admin-next clients pick `MercureProvider` over `PollingProvider` and
  open an `EventSource` per page (one channel for Yjs document updates,
  one for awareness deltas).
- Every page push and presence heartbeat that PHP receives is also
  republished to the hub on the side, so subscribers receive within
  milliseconds rather than waiting for the next poll.
- Pull and presence endpoints stay live as a recovery / catch-up path
  when the SSE stream drops or after a tab regains focus.

When the plugin is disabled or the hub is unreachable, sync transparently
falls back to polling — nothing breaks.

---

## Requirements

- Grav 2.0+
- `grav-plugin-sync` 1.0.0+
- `grav-plugin-api` 1.0.0-beta.13+ (the host for the API endpoint)
- PHP 8.3+ (for the plugin itself; the hub binary is a Go executable
  with no PHP runtime requirement)
- A Mercure hub (this plugin can manage one for you locally; production
  installs typically run their own under systemd/docker/etc.)

The plugin works regardless of the web server PHP/Grav runs under
(Apache, nginx, Caddy, FrankenPHP) because the hub is a **separate
process** the plugin only knows about via its URLs.

---

## Quick start (local development)

The fastest path is to let the bundled CLI manage the hub for you.
**[mkcert](https://github.com/FiloSottile/mkcert)** is recommended — it
installs a local CA root in your system trust store so the hub's
HTTPS cert is trusted by browsers without warnings.

```sh
# One-time, system-wide. Skip if you already have it.
brew install mkcert
mkcert -install     # installs the local CA root

# In your Grav site:
bin/plugin sync-mercure install   # downloads hub binary + writes config + cert
bin/plugin sync-mercure start     # https://localhost:3001 in the background
bin/plugin sync-mercure status    # check
```

That's it. Hard-reload admin-next in your browser; the next page-edit
will use Mercure transport. To verify, watch the Network tab for a
long-lived `EventSource` connection to `localhost:3001`.

If you don't install mkcert, the plugin falls back to a self-signed
cert via `openssl`. Visit `https://localhost:3001/healthz` once in
each browser to accept it; the EventSource will then connect.

The bundled CLI also covers:

| Command | What it does |
|---|---|
| `bin/plugin sync-mercure install` | Download hub binary, generate config + TLS cert if missing |
| `bin/plugin sync-mercure start` | Run hub in background (idempotent — also generates anything still missing) |
| `bin/plugin sync-mercure stop` | Send SIGTERM to the hub |
| `bin/plugin sync-mercure status` | Report pid + alive status |
| `bin/plugin sync-mercure logs` | Tail the most recent hub log |
| `bin/plugin sync-mercure enable` | Install an autostart service (systemd/launchd) so the hub starts on boot |
| `bin/plugin sync-mercure disable` | Remove the autostart service |

`start` and `enable` prefer the configured port but step up from it when
it's already taken (so a second Grav site on the same host doesn't collide
on 3001), and write the chosen port back into `hub.public_url`.

The CLI manages everything inside `user/data/sync-mercure/`:

```
user/data/sync-mercure/
├── mercure          # binary
├── mercure.pid
├── mercure.log
├── Caddyfile        # rewritten on each `start` (admin off, explicit transport)
├── mercure.db       # bolt history store (message replay on reconnect)
├── cert.pem         # TLS cert + key
└── key.pem
```

---

## Bring-your-own-hub (production)

For real deployments, the bundled CLI is unnecessary. Run a hub however
suits your infrastructure (systemd unit, Docker container, dedicated
host, or a Caddy install with the Mercure module compiled in) and just
point the plugin at it.

**Edit `user/config/plugins/sync-mercure.yaml`:**

```yaml
enabled: true

hub:
  # URL the BROWSER will hit to subscribe. Must be HTTPS in production
  # if your admin-next is HTTPS (which it should be).
  public_url: 'https://hub.example.com/.well-known/mercure'

  # URL PHP will hit to publish. Often the same as public_url; useful
  # to differ when the hub is on a private network behind a proxy.
  internal_url: 'http://10.0.0.5:3000/.well-known/mercure'

  # HMAC keys signing publisher / subscriber JWTs. Must match the
  # hub's MERCURE_PUBLISHER_JWT_KEY / MERCURE_SUBSCRIBER_JWT_KEY env
  # vars. Use a random 32+ byte key in production. Treat as secrets.
  publisher_secret:  'a-cryptographically-random-64-hex-char-secret'
  subscriber_secret: 'optionally-different-from-publisher'

topics:
  prefix: 'urn:grav:sync:'

# How long subscriber JWTs issued to clients are valid. Clients
# request a fresh one on every connect, so short TTLs are fine.
token_ttl_seconds: 600
```

The plugin generates fresh secrets on first install for local dev.
For production, **regenerate these values** (`openssl rand -hex 32`).

### Autostart on reboot (`enable`)

For a single-host setup (one Grav site owning the hub), the quickest way
to survive reboots is to let the plugin install the service for you:

```bash
bin/plugin sync-mercure install   # if you haven't already
bin/plugin sync-mercure enable     # writes + starts the autostart service
```

`enable` detects the host's service manager and does the right thing:

- **Linux, normal user** — writes a systemd **user** unit to
  `~/.config/systemd/user/grav-mercure.service`, runs
  `systemctl --user enable --now`, and tries `loginctl enable-linger` so
  the hub also starts at boot when no one is logged in. If linger needs
  privileges it prints the one `sudo loginctl enable-linger <user>` to run.
- **Linux, root** — writes a system unit to
  `/etc/systemd/system/grav-mercure.service` (running mercure as the data
  dir's owner when that's a normal user) and `systemctl enable --now`s it.
- **macOS** — writes a launchd agent to
  `~/Library/LaunchAgents/org.getgrav.mercure.plist` with `RunAtLoad` +
  `KeepAlive` and bootstraps it.

The service runs the bundled `mercure` binary directly (no PHP/Grav
bootstrap) with the JWT secrets from your config baked in as env vars, so
the manager owns the process and restarts it on crash. `disable` reverses
all of the above. Because the manager keeps the hub alive, use `disable`
(not `stop`) to take it down once enabled.

For multi-host or container deployments, prefer a hand-managed unit as
below.

### Running the bundled binary as a system service

The download produced by `install` is the official Mercure binary
([dunglas/mercure releases](https://github.com/dunglas/mercure/releases)).
You can move it to `/usr/local/bin/mercure` and run it under systemd:

```ini
# /etc/systemd/system/mercure.service
[Unit]
Description=Mercure hub
After=network.target

[Service]
Type=simple
User=mercure
Environment=MERCURE_PUBLISHER_JWT_KEY=<publisher_secret>
Environment=MERCURE_SUBSCRIBER_JWT_KEY=<subscriber_secret>
ExecStart=/usr/local/bin/mercure run --config /etc/mercure/Caddyfile
Restart=on-failure

[Install]
WantedBy=multi-user.target
```

A minimal `Caddyfile` for that setup with proper TLS via Let's Encrypt:

```caddy
{
    # auto_https on (default) — Caddy handles ACME for you
}

hub.example.com {
    encode zstd gzip

    mercure {
        publisher_jwt {env.MERCURE_PUBLISHER_JWT_KEY}
        subscriber_jwt {env.MERCURE_SUBSCRIBER_JWT_KEY}
        cors_origins https://your-grav-site.example.com
        anonymous
    }

    respond /healthz 200
}
```

### Running behind your existing reverse proxy

If you already terminate TLS at Apache/nginx/HAProxy/etc., point that
at the hub running on `localhost:3000` (HTTP — TLS is handled upstream).

Apache with `mod_proxy` + `mod_proxy_http`:

```apache
<VirtualHost *:443>
    ServerName your-grav-site.example.com
    # ... your existing Grav config ...

    # Mercure hub fronted on the same origin avoids browser
    # mixed-content / CORS friction entirely.
    ProxyPreserveHost On
    ProxyPass        /.well-known/mercure http://127.0.0.1:3000/.well-known/mercure
    ProxyPassReverse /.well-known/mercure http://127.0.0.1:3000/.well-known/mercure

    # SSE streams need the proxy to NOT buffer responses.
    SetEnvIf Request_URI "^/.well-known/mercure" no-gzip dont-vary
    ProxyTimeout 300
</VirtualHost>
```

nginx:

```nginx
location /.well-known/mercure {
    proxy_pass http://127.0.0.1:3000;
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_buffering off;
    proxy_cache off;
    proxy_read_timeout 24h;
}
```

Then in `sync-mercure.yaml`:

```yaml
hub:
  public_url:   'https://your-grav-site.example.com/.well-known/mercure'
  internal_url: 'http://127.0.0.1:3000/.well-known/mercure'
```

Same-origin subscribers — no CORS preflight, no mixed content concerns.

### Bring-your-own TLS cert (without mkcert)

If you have your own cert (Let's Encrypt, internal CA, anything), drop
the cert and key in `user/data/sync-mercure/` named `cert.pem` and
`key.pem`. The CLI's `start` command will reuse them instead of
calling mkcert/openssl.

You can also generate a self-signed cert manually:

```sh
openssl req -x509 -newkey rsa:2048 -nodes \
    -keyout user/data/sync-mercure/key.pem \
    -out   user/data/sync-mercure/cert.pem \
    -days 3650 -subj "/CN=localhost" \
    -addext "subjectAltName=DNS:localhost,IP:127.0.0.1,IP:::1"
```

If you're running the hub elsewhere (not via this plugin's CLI), the
cert location is whatever your hub's config points at; this plugin
doesn't manage it.

---

## How it works

```
┌────────────────┐                              ┌────────────────┐
│   admin-next   │ ───── HTTP push ────────────▶│  grav-plugin-  │
│   (browser)    │ ◀─── HTTP pull ────────────▶ │  api (PHP)     │
│                │                              │                │
│  EventSource   │ ◀──────── SSE ───────────────│ MercureBridge  │
│  (per channel) │                              │  POST update   │
└────────────────┘                              └────────┬───────┘
       ▲                                                 │
       │                                                 ▼
       │                                        ┌────────────────┐
       └────────── SSE fan-out ─────────────────│  Mercure hub   │
                                                │  (Caddy)       │
                                                └────────────────┘
```

- Pushes still go through PHP first so durable storage (file/sqlite log
  in `grav-plugin-sync`) stays the source of truth.
- After PHP appends the update to its log, it `POST`s a copy to the hub
  with a short-lived publisher JWT.
- Subscribers (admin-next clients with active page-edit sessions) hold
  open EventSource connections to the hub. Each gets the new update
  within ~50ms and applies it to their local Y.Doc.
- Each room uses two topics:
  - `urn:grav:sync:<roomId>:doc`  — Yjs document updates
  - `urn:grav:sync:<roomId>:aw`   — awareness deltas (cursor / selection)
- Subscriber JWTs (issued by `POST /sync/mercure/token`) are scoped to
  the topics for a single room, so a user can't sneak into another
  room's stream by guessing its id.

---

## Configuration reference

| Key | Default | Description |
|---|---|---|
| `enabled` | `true` | Master kill-switch for the plugin. |
| `hub.public_url` | _(empty)_ | URL the browser uses to subscribe. **Required** for the plugin to advertise itself. |
| `hub.internal_url` | _(empty, falls back to public_url)_ | URL PHP uses to publish. Use when PHP can reach the hub on a faster/private network. |
| `hub.publisher_secret` | _(empty)_ | HMAC key for publisher JWTs. Must match hub's `MERCURE_PUBLISHER_JWT_KEY`. |
| `hub.subscriber_secret` | _(empty)_ | HMAC key for subscriber JWTs. Must match hub's `MERCURE_SUBSCRIBER_JWT_KEY`. Often equal to publisher_secret. |
| `topics.prefix` | `urn:grav:sync:` | URI prefix combined with the canonical room id to form topic URIs. |
| `token_ttl_seconds` | `600` | Lifetime of subscriber JWTs issued to clients. |

`bin/plugin sync-mercure install` writes sensible defaults and a fresh
random secret into `user/config/plugins/sync-mercure.yaml` when the file
is missing or any required field is empty. Subsequent runs preserve
existing values.

---

## Permissions

`POST /sync/mercure/token` is gated by `api.pages.read` on the room being
subscribed to — same as the underlying sync pull endpoint. Anyone who can
read the page can subscribe to its Mercure stream; no separate mercure
permission needs to be granted.

---

## Troubleshooting

**EventSource connection fails / `net::ERR_CERT_AUTHORITY_INVALID`**
The hub is using a self-signed cert and the browser doesn't trust it.
Either install mkcert and rerun `bin/plugin sync-mercure install`, or
visit `https://localhost:3001/healthz` once to accept the cert.

**`Mixed Content: ... was loaded over HTTPS, but requested an insecure
EventSource`**
Your admin-next is HTTPS but `hub.public_url` points at an HTTP URL.
Change `hub.public_url` to HTTPS or front the hub with a proxy that
terminates TLS.

**`"forbidden" / 403` on the EventSource subscribe request**
The subscriber JWT issued by PHP doesn't match the hub's
`MERCURE_SUBSCRIBER_JWT_KEY`. Confirm `hub.subscriber_secret` in
`sync-mercure.yaml` is identical to the env var the hub was started
with.

**`Process exited immediately` from `bin/plugin sync-mercure start`**
Run `bin/plugin sync-mercure logs`. Most common causes:
- Port 3001 already in use by another process.
- `mkcert` invoked but its local CA isn't installed (`mkcert -install`
  fixes it).
- Caddyfile syntax error — usually transient; `stop` then `start`.

**Sync works for the first peer but new joiners see no content**
Almost always a seed race when two browsers open an empty room
simultaneously. Open one browser first, wait for the avatar/Live
status, then open the second. Server-side init-once is on the polish
list to remove this caveat.

**Where do I see updates flowing through the hub?**
`bin/plugin sync-mercure logs` — Mercure logs every published update
with its topic and id. Useful for confirming PHP is reaching the hub.

---

## Security considerations

- **Secrets**: `hub.publisher_secret` and `hub.subscriber_secret` are
  HMAC keys for JWT signing. Treat them like database passwords — keep
  them out of version control. The default `sync-mercure.yaml` lives
  under `user/config/plugins/` which is typically gitignored anyway.

- **CORS**: the bundled local-dev hub uses `cors_origins *` for
  convenience. **Tighten this in production** by editing your hub's
  Caddyfile to list only the admin-next origins that should subscribe.

- **JWT scoping**: subscriber JWTs issued to clients carry a `mercure.subscribe`
  claim with **only** the doc + awareness topic for the requested room.
  A user cannot subscribe to a different room's topic with the same
  token, and they can't publish (the hub validates the `publish` claim
  separately and PHP never issues that to clients).

- **Network exposure**: the hub on `localhost:3001` is only reachable
  from the same machine. For production, run the hub on a private
  network or front it with the same TLS-terminating proxy that handles
  your Grav site.

---

## As a sync transport

When `grav-plugin-sync` is installed, this plugin auto-registers a
`MercureTransport` with sync's transport registry on the
`onSyncRegisterTransports` event. Once registered, any sync channel —
not just editor-pro's CRDT rooms — can flow through Mercure.

The transport reports:

- **id**: `mercure`
- **priority**: `50` (configurable)
- **supportedMessageTypes**: `crdt`, `broadcast`, `awareness`

Sync's facade picks the highest-priority available transport per
channel. With this plugin enabled and a hub URL configured, broadcast
and awareness messages from any consumer plugin (comments-pro v3.0+,
reactions plugins, custom widgets) get Mercure SSE delivery for free —
the consumer plugin calls `$grav['sync']->publish(...)` and never
references Mercure directly.

The legacy `onSyncUpdate` / `onSyncAwareness` event subscribers stay in
place so editor-pro's existing CodeMirror collab path keeps producing
the same wire output it always did. Both pipelines coexist.

---

## Client SDK (`window.SyncMercure`)

The plugin ships `assets/js/sync-mercure-client.js` and auto-enqueues it
on every frontend page when `plugins.sync-mercure.enabled` is true.
Consumer plugins don't bundle their own EventSource subscriber; they
just call into the global.

```js
// Initialize the connection. Returns false on incomplete config (the
// caller should treat that as auto-failover) and true on success.
window.SyncMercure.init(config, handlers);
//   config: {
//     hubUrl: string,
//     jwt: string,
//     topics: { main: string, typing?: string },
//     heartbeatSeconds?: number,
//     typingPostUrl?: string
//   }
//   handlers: { onUpdate, onFailover, onTypingChange? }

// Send a typing-presence event. POSTs to handlers.typingPostUrl with
// throttling and an automatic heartbeat while 'start' is active.
// No-op when typingPostUrl is absent.
window.SyncMercure.sendTyping('start');
window.SyncMercure.sendTyping('stop');

// Close the EventSource and clear all timers. Safe to call multiple times.
window.SyncMercure.disconnect();
```

The server-side `MercureTransport::clientConfig()` returns the
`hubUrl`, `jwt`, and `topics` keys; consumer plugins fill in
`typingPostUrl` and `heartbeatSeconds` before passing the merged config
to `init()`.

---

## Composer

The plugin ships with `vendor/` pre-installed (`firebase/php-jwt`).
End users do not need to run `composer install` — drop the release
archive into `user/plugins/sync-mercure/` and it's ready.

---

## Using as a generic Mercure bridge

Once `sync-mercure` is enabled and pointing at a hub, **any Grav plugin**
can use that same hub as a generic Mercure pub/sub backend. The bridge
is exposed on the Grav DI container as `$grav['mercure']`:

```php
use Grav\Plugin\SyncMercure\MercureBridge;

/** @var MercureBridge $mercure */
$mercure = $this->grav['mercure'];

if (!$mercure->isAvailable()) {
    return; // hub not configured / disabled, fall back gracefully
}

// Publish JSON to your own topic. Pass an array and the bridge json_encodes
// it for you; pass a pre-built string if you want full control of the body.
$mercure->publishTopic('urn:grav:myplugin:notifications', [
    'kind'  => 'job-finished',
    'jobId' => 'abc123',
    'ok'    => true,
]);

// Mint a subscriber JWT scoped to the topics your client should see.
$jwt = $mercure->issueSubscriberJwtForTopics(
    ['urn:grav:myplugin:notifications', 'urn:grav:myplugin:user:bob'],
    userId: 'bob',
    ttlSeconds: 600,
);

// Hand $jwt + $mercure->publicHubUrl() back to the browser; the browser
// opens an EventSource against the hub URL with the JWT in a cookie or
// Authorization header (see Mercure's own docs for client setup).
```

**Topic-prefix discipline.** Each plugin owns its own URI prefix and is
responsible for not colliding with other plugins. Sync uses
`urn:grav:sync:`; pick something specific to your plugin (for example
`urn:grav:myplugin:`). There is no central registry.

**API version check.** If your plugin needs a feature added in a later
release of `sync-mercure`, gate on `MercureBridge::API_VERSION`:

```php
if (MercureBridge::API_VERSION < 1) {
    throw new RuntimeException('sync-mercure 1.0.1 or newer is required');
}
```

---

## License

MIT (see `LICENSE`).
