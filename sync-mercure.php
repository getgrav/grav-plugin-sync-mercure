<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Events\PermissionsRegisterEvent;
use Grav\Framework\Acl\PermissionsReader;
use Grav\Plugin\Sync\Transport\TransportRegistry;
use Grav\Plugin\SyncMercure\MercureBridge;
use Grav\Plugin\SyncMercure\MercureController;
use Grav\Plugin\SyncMercure\MercureTransport;
use RocketTheme\Toolbox\Event\Event;

/**
 * grav-plugin-sync-mercure (Mercure SSE transport for grav-plugin-sync, plus
 * a generic Mercure bridge any Grav plugin can use).
 *
 * Pure side-car: doesn't replace any existing endpoints. Adds:
 *
 *   - $grav['mercure']             canonical public service name (any plugin
 *                                  can fetch this and call publishTopic /
 *                                  issueSubscriberJwtForTopics)
 *   - $grav['sync_mercure_bridge'] kept-for-compat alias (same instance);
 *                                  used by grav-plugin-sync internally
 *   - POST /sync/mercure/token     subscriber JWT issuance for sync rooms
 *   - onSyncUpdate listener        publishes doc updates to the hub
 *   - onSyncAwareness listener     publishes awareness deltas to the hub
 *   - onSyncCapabilities listener  advertises mercure transport
 *
 * The hub itself runs as a separate process (see bin/plugin sync-mercure).
 */
class SyncMercurePlugin extends Plugin
{
    public $features = [
        'blueprints' => 1000,
    ];

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized'        => [['onPluginsInitialized', 1000]],
            'onApiRegisterRoutes'         => ['onApiRegisterRoutes', 0],
            'onAssetsInitialized'         => ['onAssetsInitialized', 0],
            'onSyncRegisterTransports'    => ['onSyncRegisterTransports', 0],
            'onSyncUpdate'                => ['onSyncUpdate', 0],
            'onSyncAwareness'             => ['onSyncAwareness', 0],
            'onSyncCapabilities'          => ['onSyncCapabilities', 0],
            PermissionsRegisterEvent::class => ['onRegisterPermissions', 1000],
        ];
    }

    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    public function onPluginsInitialized(): void
    {
        if (!$this->config->get('plugins.sync-mercure.enabled')) {
            return;
        }

        // Define the factory once and register it under both keys. Pimple
        // shares the result of each closure independently, so to guarantee
        // the two service keys resolve to the SAME object we register the
        // canonical key first then alias it through a closure that fetches
        // the canonical instance.
        $this->grav['mercure'] = function (): MercureBridge {
            return new MercureBridge($this->config);
        };
        $this->grav['sync_mercure_bridge'] = function ($c): MercureBridge {
            return $c['mercure'];
        };
    }

    public function onApiRegisterRoutes(Event $event): void
    {
        if (!$this->config->get('plugins.sync-mercure.enabled')) {
            return;
        }

        /** @var \Grav\Plugin\Api\ApiRouteCollector $routes */
        $routes = $event['routes'];
        $routes->post('/sync/mercure/token', [MercureController::class, 'token']);
    }

    /**
     * Enqueue the generic Mercure subscriber SDK on frontend pages so any
     * consumer plugin (comments-pro today; future plugins later) can call
     * window.SyncMercure.init(config, handlers) without bundling its own
     * EventSource client.
     *
     * Frontend only. Admin-next loads its Mercure client through its own
     * editor-pro / collab bundles, so injecting this script there would
     * just add weight without being used.
     */
    public function onAssetsInitialized(): void
    {
        if (!$this->config->get('plugins.sync-mercure.enabled')) {
            return;
        }
        if ($this->isAdmin()) {
            return;
        }

        /** @var \Grav\Common\Assets $assets */
        $assets = $this->grav['assets'];
        $assets->addJs(
            'plugin://sync-mercure/assets/js/sync-mercure-client.js',
            ['group' => 'bottom']
        );
    }

    /**
     * Register MercureTransport with sync's pluggable transport registry
     * (Round 4a). The transport handles the new facade-driven publish
     * path (Crdt, Broadcast, Awareness) for any channel whose owner
     * plugin opts in. The legacy onSyncUpdate / onSyncAwareness
     * subscribers below remain in place this round so editor-pro's
     * existing CodeMirror collab path keeps producing the same wire
     * output it always did; both pipelines coexist.
     */
    public function onSyncRegisterTransports(Event $event): void
    {
        if (!$this->config->get('plugins.sync-mercure.enabled')) {
            return;
        }

        if (!$this->grav->offsetExists('mercure')) {
            return;
        }

        /** @var TransportRegistry|null $registry */
        $registry = $event['transports'] ?? null;
        if (!$registry instanceof TransportRegistry) {
            return;
        }

        /** @var MercureBridge $bridge */
        $bridge = $this->grav['mercure'];
        $registry->register(new MercureTransport($bridge));
    }

    /**
     * Republish Yjs doc updates to the Mercure hub for the room's "doc"
     * channel. Subscribers receive within milliseconds and apply the
     * update to their local Y.Doc, same as if it had been pulled.
     */
    public function onSyncUpdate(Event $event): void
    {
        $bridge = $this->bridge();
        if (!$bridge || !$bridge->isEnabled()) return;

        $room = $event['room'] ?? null;
        $update = $event['update'] ?? null;
        if (!$room || !is_string($update) || $update === '') return;

        try {
            $bridge->publish((string)$room, 'doc', $update);
        } catch (\Throwable $e) {
            $this->grav['log']->warning('[sync-mercure] doc publish failed: ' . $e->getMessage());
        }
    }

    /**
     * Republish awareness deltas to the room's "aw" channel. The payload
     * arrives at the controller already base64-encoded (clients do this
     * in their presence heartbeat); we decode once and pass the bytes
     * through to the hub.
     */
    public function onSyncAwareness(Event $event): void
    {
        $bridge = $this->bridge();
        if (!$bridge || !$bridge->isEnabled()) return;

        $room = $event['room'] ?? null;
        $b64 = $event['awarenessUpdateB64'] ?? null;
        if (!$room || !is_string($b64) || $b64 === '') return;

        $bytes = base64_decode($b64, true);
        if ($bytes === false || $bytes === '') return;

        try {
            $bridge->publish((string)$room, 'aw', $bytes);
        } catch (\Throwable $e) {
            $this->grav['log']->warning('[sync-mercure] awareness publish failed: ' . $e->getMessage());
        }
    }

    public function onSyncCapabilities(Event $event): void
    {
        $bridge = $this->bridge();
        if (!$bridge || !$bridge->isEnabled()) return;

        // Transport advertisement (id, name, priority, supports) and the
        // controller's `preferred` selection now come from the
        // TransportRegistry — see SyncController::capabilities(). All this
        // listener still does is contribute the mercure-specific metadata
        // block clients need to actually subscribe (hub URL + topic prefix).
        $caps = $event['capabilities'] ?? [];
        $caps['mercure'] = [
            'hub' => $bridge->publicUrl(),
            'topic_prefix' => $bridge->topicPrefix(),
        ];
        $event['capabilities'] = $caps;
    }

    public function onRegisterPermissions(PermissionsRegisterEvent $event): void
    {
        $actions = PermissionsReader::fromYaml("plugin://{$this->name}/permissions.yaml");
        $event->permissions->addActions($actions);
    }

    private function bridge(): ?MercureBridge
    {
        return $this->grav->offsetExists('sync_mercure_bridge')
            ? $this->grav['sync_mercure_bridge']
            : null;
    }
}
