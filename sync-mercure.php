<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Events\PermissionsRegisterEvent;
use Grav\Framework\Acl\PermissionsReader;
use Grav\Plugin\SyncMercure\MercureBridge;
use Grav\Plugin\SyncMercure\MercureController;
use RocketTheme\Toolbox\Event\Event;

/**
 * grav-plugin-sync-mercure — Mercure SSE transport for grav-plugin-sync.
 *
 * Pure side-car: doesn't replace any existing endpoints. Adds:
 *
 *   - $grav['sync_mercure_bridge'] service
 *   - POST /sync/mercure/token (subscriber JWT issuance)
 *   - onSyncUpdate listener → publish doc updates to hub
 *   - onSyncAwareness listener → publish awareness deltas to hub
 *   - onSyncCapabilities listener → advertise mercure transport
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

        $this->grav['sync_mercure_bridge'] = function (): MercureBridge {
            return new MercureBridge($this->config);
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
     * Republish Yjs doc updates to the Mercure hub for the room's "doc"
     * channel. Subscribers receive within milliseconds and apply the
     * update to their local Y.Doc — same as if it had been pulled.
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

        $caps = $event['capabilities'] ?? [];
        $transports = $caps['transports'] ?? [];
        if (!in_array('mercure', $transports, true)) {
            $transports[] = 'mercure';
        }
        $caps['transports'] = $transports;
        $caps['preferred'] = 'mercure';
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
