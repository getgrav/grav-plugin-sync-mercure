<?php

declare(strict_types=1);

namespace Grav\Plugin\SyncMercure;

use Firebase\JWT\JWT;
use Grav\Common\Config\Config;
use RuntimeException;

/**
 * Bridges Grav to a Mercure hub.
 *
 * Two layers of API live on this class:
 *
 *   - Sync-specific (kept for backward compatibility):
 *       publish(roomId, channel, bytes) → POST envelope to the hub
 *       issueSubscriberJwt(roomId, userId) → JWT for room's doc + aw topics
 *
 *   - Generic public API (any Grav plugin can use it):
 *       publishTopic(topic, payload, private) → POST to an arbitrary topic
 *       issueSubscriberJwtForTopics(topics, userId, ttl) → JWT for any topics
 *
 * The hub itself runs as a separate process. PHP only sees it via two URLs:
 * the internal one (used for PHP to hub publishes) and the public one
 * (returned to clients to subscribe with EventSource).
 *
 * Sync topic structure:
 *   urn:grav:sync:<roomId>:<channel>
 *
 *   channel = 'doc'  (Yjs document updates, binary, base64 in JSON)
 *   channel = 'aw'   (awareness deltas, encodeAwarenessUpdate output)
 *
 * Other plugins should pick their own topic prefix (for example
 * `urn:grav:myplugin:`) and own that namespace themselves; there is no
 * central registry.
 */
final class MercureBridge
{
    /**
     * Public API version. Increment on breaking changes to publishTopic /
     * issueSubscriberJwtForTopics. Consumers can guard against older
     * sync-mercure builds with `MercureBridge::API_VERSION >= N`.
     */
    public const API_VERSION = 1;

    public function __construct(
        private readonly Config $config,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool)$this->config->get('plugins.sync-mercure.enabled')
            && $this->publicUrl() !== '';
    }

    /**
     * Public-API-friendly alias for isEnabled(). Generic consumers should
     * prefer this name; it reads better outside the sync plugin.
     */
    public function isAvailable(): bool
    {
        return $this->isEnabled();
    }

    public function publicUrl(): string
    {
        return (string)$this->config->get('plugins.sync-mercure.hub.public_url', '');
    }

    /**
     * Public-API-friendly alias for publicUrl().
     */
    public function publicHubUrl(): string
    {
        return $this->publicUrl();
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
     * Publish a binary update to a sync room channel.
     *
     * Kept for backward compatibility with grav-plugin-sync. The wire output
     * (envelope JSON, topic, JWT claim shape, HTTP body) is byte-identical
     * to the original implementation; only the HTTP transport step is shared
     * with the new generic publishTopic() helper.
     *
     * @param string $bytes Raw binary payload (Yjs update or awareness delta).
     */
    public function publish(string $roomId, string $channel, string $bytes): void
    {
        if ($this->internalUrl() === '') {
            return; // disabled / not configured (silent no-op)
        }

        $topic = $this->topicFor($roomId, $channel);

        // Wrap binary payload as base64 inside a small JSON envelope so
        // subscribers get a uniform shape (channel + bytes + serverTimeMs).
        $envelope = json_encode([
            'channel' => $channel,
            'bytes' => base64_encode($bytes),
            'serverTimeMs' => (int)(microtime(true) * 1000),
        ], JSON_UNESCAPED_SLASHES);

        $this->httpPostToHub($topic, $envelope, false);
    }

    /**
     * Generic publish to an arbitrary Mercure topic. Any Grav plugin can
     * call this through `$grav['mercure']` to use the configured hub as a
     * generic pub/sub backend.
     *
     * Each plugin owns its own topic prefix (for example
     * `urn:grav:myplugin:`); there is no central registry. Pick something
     * specific enough not to clash with other plugins.
     *
     * @param string       $topic   The Mercure topic URI to publish on.
     * @param array|string $payload If array, json_encoded. If string, sent
     *                              through unchanged (lets binary callers
     *                              control their own envelope).
     * @param bool         $private If true, sets Mercure's standard
     *                              `private[]=on` flag so only authorized
     *                              subscribers see the update.
     */
    public function publishTopic(string $topic, array|string $payload, bool $private = false): void
    {
        if ($this->internalUrl() === '') {
            return; // disabled / not configured (silent no-op)
        }

        $data = is_array($payload)
            ? (string)json_encode($payload, JSON_UNESCAPED_SLASHES)
            : $payload;

        $this->httpPostToHub($topic, $data, $private);
    }

    /**
     * Issue a subscriber JWT scoped to a single sync room (doc + awareness
     * channels). Kept for backward compatibility; new code should prefer
     * issueSubscriberJwtForTopics().
     *
     * The hub validates the `mercure.subscribe` claim against the topic the
     * client requests on EventSource connect, so peer leakage between
     * unrelated rooms is impossible.
     */
    public function issueSubscriberJwt(string $roomId, string $userId): string
    {
        return $this->issueSubscriberJwtForTopics(
            [
                $this->topicFor($roomId, 'doc'),
                $this->topicFor($roomId, 'aw'),
            ],
            $userId,
        );
    }

    /**
     * Issue a subscriber JWT for an arbitrary list of topics. Generic
     * plugins should call this directly with the topic(s) they want their
     * client to subscribe to.
     *
     * @param array<int, string> $topics  Topics the client may subscribe to.
     * @param string             $userId  Goes in the `sub` claim.
     * @param int|null           $ttlSeconds Optional TTL override; falls
     *                                       back to the plugin's
     *                                       `token_ttl_seconds` config.
     */
    public function issueSubscriberJwtForTopics(array $topics, string $userId, ?int $ttlSeconds = null): string
    {
        $secret = (string)$this->config->get('plugins.sync-mercure.hub.subscriber_secret', '');
        if ($secret === '') {
            throw new RuntimeException('sync-mercure: subscriber_secret is not configured');
        }
        $configTtl = (int)$this->config->get('plugins.sync-mercure.token_ttl_seconds', 600);
        $ttl = $ttlSeconds ?? $configTtl;
        $now = time();

        $payload = [
            'iat' => $now,
            'exp' => $now + max(60, $ttl),
            'sub' => $userId,
            'mercure' => [
                'subscribe' => array_values($topics),
            ],
        ];

        return JWT::encode($payload, $secret, 'HS256');
    }

    /**
     * Shared HTTP POST helper used by both publish() and publishTopic().
     * Mints a short-lived publisher JWT scoped to the single topic and
     * sends the form-encoded body Mercure expects.
     */
    private function httpPostToHub(string $topic, string $data, bool $private): void
    {
        $hub = $this->internalUrl();
        if ($hub === '') {
            return;
        }
        $secret = (string)$this->config->get('plugins.sync-mercure.hub.publisher_secret', '');
        if ($secret === '') {
            throw new RuntimeException('sync-mercure: publisher_secret is not configured');
        }

        $jwt = $this->signPublisherJwt($secret, [$topic]);

        $fields = [
            'topic' => $topic,
            'data' => $data,
        ];
        $body = http_build_query($fields);
        if ($private) {
            // Mercure's private flag is its own form field; http_build_query
            // would index `private[]` and the hub wouldn't recognize it,
            // so append it manually as Mercure expects.
            $body .= '&private[]=on';
        }

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
            'ssl' => $this->sslOptionsFor($hub),
        ]);
        $resp = @file_get_contents($hub, false, $ctx);
        if ($resp === false) {
            // ignore_errors keeps HTTP error bodies, so a false here is a
            // transport failure (DNS, connect, TLS). Surface the underlying
            // reason instead of a bare "failed" — a self-signed loopback cert
            // rejected by verify_peer was the classic silent culprit.
            $err = error_get_last();
            $detail = isset($err['message']) ? ': ' . trim((string)$err['message']) : '';
            throw new RuntimeException("sync-mercure: publish to {$hub} failed{$detail}");
        }
        // Mercure returns 200 with the event id. We don't need it.
    }

    /**
     * TLS options for the publish request. The internal hub normally listens
     * on loopback with a self-signed certificate (Caddy's local cert), which
     * PHP's default verify_peer rejects — the publish then fails on every
     * event. For a loopback hub we skip verification (there is no MITM surface
     * on 127.0.0.0/8 or ::1); a non-loopback internal_url keeps full
     * verification. Set `hub.internal_tls_insecure: true|false` to override the
     * auto-detection either way. No-op for plain-HTTP hubs.
     *
     * @return array<string, mixed>
     */
    private function sslOptionsFor(string $hub): array
    {
        if (stripos($hub, 'https://') !== 0) {
            return [];
        }

        $override = $this->config->get('plugins.sync-mercure.hub.internal_tls_insecure');
        $insecure = $override !== null ? (bool)$override : $this->isLoopbackUrl($hub);
        if (!$insecure) {
            return [];
        }

        return [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ];
    }

    /** Whether the URL's host is a loopback address (127.0.0.0/8, ::1, localhost). */
    private function isLoopbackUrl(string $url): bool
    {
        $host = trim((string)parse_url($url, PHP_URL_HOST), '[]');

        return $host === 'localhost' || $host === '::1' || str_starts_with($host, '127.');
    }

    /**
     * Generate a one-shot publisher JWT scoped to a topic. Held in memory
     * only, never returned to clients.
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
