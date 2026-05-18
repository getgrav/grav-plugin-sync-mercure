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
