<?php

declare(strict_types=1);

namespace Grav\Plugin\SyncMercure;

use Firebase\JWT\JWT;
use Grav\Common\Config\Config;
use RuntimeException;

/**
 * Bridges grav-plugin-sync to a Mercure hub.
 *
 *   - publish(roomId, channel, bytes) → POST {topic, data} to the hub
 *   - issueSubscriberJwt(roomId, userId) → signed JWT clients pass to the hub
 *
 * The hub itself runs as a separate process. PHP only sees it via two URLs:
 * the internal one (used for PHP→hub publishes) and the public one (returned
 * to clients to subscribe with EventSource).
 *
 * Topic structure:
 *   urn:grav:sync:<roomId>:<channel>
 *
 *   channel = 'doc'  — Yjs document updates (binary, base64 in JSON)
 *   channel = 'aw'   — awareness deltas (encodeAwarenessUpdate output)
 */
final class MercureBridge
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool)$this->config->get('plugins.sync-mercure.enabled')
            && $this->publicUrl() !== '';
    }

    public function publicUrl(): string
    {
        return (string)$this->config->get('plugins.sync-mercure.hub.public_url', '');
    }

    public function internalUrl(): string
    {
        $url = (string)$this->config->get('plugins.sync-mercure.hub.internal_url', '');
        return $url !== '' ? $url : $this->publicUrl();
    }

    public function topicPrefix(): string
    {
        return (string)$this->config->get('plugins.sync-mercure.topics.prefix', 'urn:grav:sync:');
    }

    public function topicFor(string $roomId, string $channel): string
    {
        return $this->topicPrefix() . $roomId . ':' . $channel;
    }

    /**
     * Publish a binary update to a room channel.
     *
     * @param string $bytes Raw binary payload (Yjs update or awareness delta).
     */
    public function publish(string $roomId, string $channel, string $bytes): void
    {
        $hub = $this->internalUrl();
        if ($hub === '') {
            return; // disabled / not configured — silent no-op
        }
        $secret = (string)$this->config->get('plugins.sync-mercure.hub.publisher_secret', '');
        if ($secret === '') {
            throw new RuntimeException('sync-mercure: publisher_secret is not configured');
        }

        $topic = $this->topicFor($roomId, $channel);
        $jwt = $this->signPublisherJwt($secret, [$topic]);

        // Mercure expects application/x-www-form-urlencoded with topic + data fields.
        // Wrap binary payload as base64 inside a small JSON envelope so subscribers
        // get a uniform shape (channel + clientId + bytes).
        $envelope = json_encode([
            'channel' => $channel,
            'bytes' => base64_encode($bytes),
            'serverTimeMs' => (int)(microtime(true) * 1000),
        ], JSON_UNESCAPED_SLASHES);

        $body = http_build_query([
            'topic' => $topic,
            'data' => $envelope,
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Authorization: Bearer ' . $jwt,
                    'Content-Type: application/x-www-form-urlencoded',
                    'Connection: close',
                ]),
                'content' => $body,
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);
        $resp = @file_get_contents($hub, false, $ctx);
        if ($resp === false) {
            throw new RuntimeException("sync-mercure: publish to {$hub} failed");
        }
        // Mercure returns 200 with the event id. We don't need it.
    }

    /**
     * Issue a subscriber JWT scoped to a single room (doc + awareness channels).
     *
     * The hub validates the `mercure.subscribe` claim against the topic the
     * client is requesting on EventSource connect — peers can only subscribe
     * to the rooms PHP has authorized for them, so peer leakage between
     * unrelated pages is impossible.
     */
    public function issueSubscriberJwt(string $roomId, string $userId): string
    {
        $secret = (string)$this->config->get('plugins.sync-mercure.hub.subscriber_secret', '');
        if ($secret === '') {
            throw new RuntimeException('sync-mercure: subscriber_secret is not configured');
        }
        $ttl = (int)$this->config->get('plugins.sync-mercure.token_ttl_seconds', 600);
        $now = time();

        $topics = [
            $this->topicFor($roomId, 'doc'),
            $this->topicFor($roomId, 'aw'),
        ];

        $payload = [
            'iat' => $now,
            'exp' => $now + max(60, $ttl),
            'sub' => $userId,
            'mercure' => [
                'subscribe' => $topics,
            ],
        ];

        return JWT::encode($payload, $secret, 'HS256');
    }

    /**
     * Generate a one-shot publisher JWT scoped to a topic. Held in memory
     * only — never returned to clients.
     *
     * @param array<int, string> $topics
     */
    private function signPublisherJwt(string $secret, array $topics): string
    {
        $now = time();
        return JWT::encode([
            'iat' => $now,
            'exp' => $now + 60,
            'mercure' => [
                'publish' => $topics,
            ],
        ], $secret, 'HS256');
    }
}
