<?php

declare(strict_types=1);

namespace Grav\Plugin\SyncMercure;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Sync\RoomRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Endpoints specific to the Mercure transport.
 *
 *   POST /sync/mercure/token
 *     Body: { route, lang? }
 *     Returns: { hub, topic_doc, topic_aw, jwt, expires_in }
 *
 *   GET  /sync/mercure/capabilities (advisory; main capabilities endpoint
 *     in grav-plugin-sync's SyncController also reflects mercure
 *     availability via the onSyncCapabilities event).
 *
 * Security: same gate as pulling content. We refuse to issue a
 * subscriber JWT for a page the user can't read, so an authenticated
 * user can't sneak into another room by guessing its id.
 */
class MercureController extends AbstractApiController
{
    private const PERMISSION_READ = 'api.collab.read';

    public function token(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);
        $this->requirePermission($request, 'api.pages.read');

        /** @var MercureBridge $bridge */
        $bridge = $this->grav['sync_mercure_bridge'];
        if (!$bridge->isEnabled()) {
            throw new NotFoundException('Mercure transport is not configured.');
        }

        $body = $this->getRequestBody($request);
        $route = isset($body['route']) ? (string)$body['route'] : '';
        $lang = isset($body['lang']) ? (string)$body['lang'] : '';
        if ($route === '') {
            throw new ValidationException('`route` is required.');
        }
        $route = '/' . ltrim($route, '/');

        $this->enablePages();
        $page = $this->grav['pages']->find($route);
        if (!$page) {
            throw new NotFoundException("Page not found at route: {$route}");
        }

        /** @var RoomRegistry $rooms */
        $rooms = $this->grav['sync_rooms'];
        $room = $rooms->roomFor($route, $lang ?: null, $page->template() ?: 'default');

        $user = $this->getUser($request);
        $jwt = $bridge->issueSubscriberJwt($room->id, (string)($user->username ?? 'anon'));

        $ttl = (int)$this->config->get('plugins.sync-mercure.token_ttl_seconds', 600);

        return ApiResponse::create([
            'hub' => $bridge->publicUrl(),
            'topic_doc' => $bridge->topicFor($room->id, 'doc'),
            'topic_aw' => $bridge->topicFor($room->id, 'aw'),
            'jwt' => $jwt,
            'expires_in' => $ttl,
            'room' => $room->id,
        ]);
    }

    private function enablePages(): void
    {
        /** @var \Grav\Common\Page\Pages $pages */
        $pages = $this->grav['pages'];
        $pages->enablePages();
    }
}
