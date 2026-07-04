# v1.2.0
## 07/03/2026

1. [](#new)
    * **`bin/plugin sync-mercure enable` / `disable`** — install (or remove) an OS-native autostart service so the hub comes back on reboot. Detects the host's service manager automatically: a systemd user unit (+ linger) or system unit on Linux, or a launchd agent on macOS. The service runs the bundled `mercure` binary directly so the manager owns the process and restarts it on crash.
    * **Automatic port-conflict resolution** — `start` and `enable` now prefer the configured port but step up from it when it's already taken (e.g. a second Grav site on the same host), and persist the chosen port into `hub.public_url` so the browser and PHP publisher both follow.
2. [](#bugfix)
    * **Fixed startup crash on Mercure 0.24+** (`invalid transport`). Mercure 0.24 dropped the implicit default transport, so the generated Caddyfile now sets the transport explicitly with `transport local`. (The bolt store was tried for `Last-Event-ID` replay but the bolt module in Mercure 0.24.2 aborts provisioning with `invalid transport: timeout`, so it is unusable on that build; clients resync through the API on reconnect instead.)
    * The generated Caddyfile now disables Caddy's admin API (`admin off`). The hub never used it, and leaving it on (default `:2019`) was a second collision point preventing two hubs from running on one host even after the data port was bumped.

# v1.1.2
## 05/28/2026

1. [](#improved)
    * **`POST /sync/mercure/token` is now gated by regular page permissions** (`api.pages.read`) instead of the separate `api.collab.*` permission, matching the change in the sync plugin. Fixes [getgrav/grav-plugin-admin2#24](https://github.com/getgrav/grav-plugin-admin2/issues/24).

# v1.1.1
## 05/18/2026

1. [](#bugfix)
    * Removed `vendor` from `.gitignore` file, so it's included in package


# v1.1.0
## 05/13/2026

1. [](#new)
    * Ships `sync-mercure-client.js` (`window.SyncMercure`). The Mercure subscriber JS that comments-pro previously bundled now lives here and is auto-enqueued for consumer plugins on the frontend.
2. [](#improved)
    * Mercure now appears in the capabilities response as a properly-structured transport entry (id, name, priority, supported message types) sourced from the transport registry, instead of a bare string entry stitched in by the capabilities listener.
3. [](#bugfix)
    * Typing indicators (and any other awareness-typed message) now actually reach subscribers. Awareness events publish to the channel's own topic with a flat envelope instead of a `:awareness`-suffixed topic the client never listened to.

# v1.0.1
## 05/09/2026

1. [](#new)
    * Public Mercure bridge API. The `$grav['mercure']` service exposes `publishTopic()` and `issueSubscriberJwtForTopics()` so any Grav plugin can use the configured hub as a generic pub/sub backend.
    * Added `MercureBridge::API_VERSION` constant for consumer version checks.
    * Now registers as a sync transport provider via `onSyncRegisterTransports`. The new `MercureTransport` class implements `\Grav\Plugin\Sync\Transport\TransportInterface` and handles CRDT, broadcast, and awareness messages published through `$grav['sync']`.
2. [](#improved)
    * Existing `publish()` and `issueSubscriberJwt()` signatures and wire output are unchanged. `$grav['sync_mercure_bridge']` continues to work as an alias for `$grav['mercure']` (same shared instance).
    * Existing `onSyncUpdate` and `onSyncAwareness` event subscribers and the `POST /sync/mercure/token` endpoint are preserved, so editor-pro's CodeMirror collab path keeps working unchanged. Both the legacy event path and the new transport-interface path coexist.

# v1.0.0
## 04/25/2026

1. [](#new)
    * Initial Release
