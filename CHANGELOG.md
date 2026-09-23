# v1.2.2
## 09/23/2026

1. [](#bugfix)
    * **Sync updates are now private, so only admitted subscribers receive them.** Every document edit, awareness update and channel broadcast used to go to the hub as a public Mercure update, and a public update reaches anyone subscribed to its topic, with or without a JWT. Topics are predictable (`urn:grav:sync:<room>:doc`, `urn:grav:forum-pro:user/42`), so anyone who guessed one could read live page edits, a member's notification channel, or new posts in a restricted forum, whatever the channel's access check said. Updates are now published private, and the hub only delivers them to subscribers whose JWT names the topic, which PHP issues only after the room or channel admits the user
    * **Fixed `publishTopic(..., private: true)` publishing publicly.** The bridge sent Mercure's private flag as `private[]=on`, a field name the hub doesn't read, so every update asked to be private went out public. It now sends `private=on`
    * The generated hub config no longer allows anonymous subscribers. Every Grav client subscribes with a JWT, so a connection without one now gets a 401 instead of an open stream. Restart the bundled hub (`stop` then `start`, or `disable` then `enable`) to regenerate its Caddyfile; a hub you run yourself needs `anonymous` removed by hand
2. [](#improved)
    * New `tests/hub-privacy-check.php` runs the privacy checks against a real hub binary: anonymous subscribers get nothing, and one user's or room's JWT can't read another's updates

# v1.2.1
## 07/15/2026

1. [](#bugfix)
    * **Fixed every publish to a loopback hub failing TLS verification.** When `hub.internal_url` points at the hub over HTTPS on `127.0.0.1` / `::1` / `localhost` (the normal self-signed local-hub setup), PHP's default `verify_peer` rejected the hub's self-signed certificate, so every event silently failed to publish. The publisher now skips certificate verification for a loopback internal URL only (there is no man-in-the-middle surface on loopback); a non-loopback `internal_url` keeps full verification. Set `hub.internal_tls_insecure: true` or `false` to override the auto-detection. A failed publish now also reports the underlying TLS or connection reason instead of a bare "publish failed".

# v1.2.0
## 07/04/2026

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
