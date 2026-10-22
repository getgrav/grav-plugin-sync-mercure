<?php

declare(strict_types=1);

namespace Grav\Plugin\SyncMercure;

use Firebase\JWT\JWT;
use Grav\Common\Config\Config;
use Grav\Common\Grav;
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
 * (returned to clients, which subscribe with a streaming fetch that sends
 * the JWT in the Authorization header).
 *
 * Both hub generations are supported. Every token carries the 0.x `mercure`
 * claim and the 1.x OAuth access token claims side by side, so the same
 * token works on a 0.x hub, a 1.x hub in compatibility mode and a modern
 * 1.x hub. The one thing clients must know is which subscribe parameter to
 * use (`topic=` on 0.x, `match=` on 1.x); protocolVersion() tells them.
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

    /** `type` of a Mercure 1.x authorization detail. */
    private const AUTHORIZATION_DETAIL_TYPE = 'https://mercure.rocks/authorization-detail';

    /** How long a probed protocol version is cached (seconds). */
    private const PROTOCOL_CACHE_TTL = 3600;

    private ?int $protocol = null;

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
     * Kept for backward compatibility with grav-plugin-sync. The envelope
     * JSON and topic match the original implementation.
     * The update is published private: room content (page drafts being
     * edited) must only reach subscribers whose JWT names the room's topic,
     * which the token endpoint issues after checking page access. A public
     * update would go to anyone who guessed the topic.
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

        $this->httpPostToHub($topic, $envelope, true);
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
     *                              `private[]=on` flag so only subscribers
     *                              whose JWT names the topic see the update.
     *                              A public update reaches anyone who knows
     *                              or guesses the topic, JWT or not, so pass
     *                              true for anything that isn't public.
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
     * The hub checks the token's subscribe grant against the topics the
     * client asks for when it connects, so peer leakage between unrelated
     * rooms is impossible.
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

        return $this->signToken($secret, 'subscribe', array_values($topics), $userId, max(60, $ttl));
    }

    /**
     * Mercure protocol the hub speaks: 1 for a 1.x hub (subscribe with
     * `match=`), 0 for a 0.x hub (subscribe with `topic=`). Clients read it
     * from the token response and the transport client config. Tokens work
     * on both, so this only picks the subscribe parameter.
     *
     * `hub.protocol` set to 0 or 1 forces the answer. `auto` (the default)
     * asks the hub once and caches the answer for an hour: only a 1.x hub
     * serves RFC 9728 resource metadata, a JSON document with a `resource`
     * member, at /.well-known/oauth-protected-resource/.well-known/mercure.
     * An unreachable hub answers 0 without caching, so the next request asks
     * again. The clients also switch parameter by themselves when the hub
     * answers 400, so a stale answer heals on its own.
     */
    public function protocolVersion(): int
    {
        $setting = strtolower(trim((string)$this->config->get('plugins.sync-mercure.hub.protocol', 'auto')));
        if ($setting === '0' || $setting === '1') {
            return (int)$setting;
        }
        if ($this->protocol !== null) {
            return $this->protocol;
        }

        $hub = $this->internalUrl();
        $metadataUrl = $this->resourceMetadataUrl($hub);
        if ($metadataUrl === null) {
            return 0;
        }

        $cache = $this->cache();
        $key = self::protocolCacheKey($hub);
        $cached = $cache?->fetch($key);
        if ($cached === 0 || $cached === 1) {
            return $this->protocol = $cached;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'Accept: application/json',
                'timeout' => 2,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => $this->sslOptionsFor($hub),
        ]);
        $body = @file_get_contents($metadataUrl, false, $ctx);
        if ($body === false) {
            return 0; // hub unreachable: don't cache, ask again next time
        }
        $json = json_decode($body, true);
        $version = \is_array($json) && isset($json['resource']) ? 1 : 0;
        $cache?->save($key, $version, self::PROTOCOL_CACHE_TTL);

        return $this->protocol = $version;
    }

    /**
     * Drop the cached protocol answer for the configured hub, so the next
     * token request asks the hub again. The CLI calls this after (re)starting
     * the bundled hub, which may have changed generation.
     */
    public function forgetProtocol(): void
    {
        $this->protocol = null;
        $this->cache()?->delete(self::protocolCacheKey($this->internalUrl()));
    }

    /** RFC 9728 metadata URL for a hub URL, or null when the URL is unusable. */
    private function resourceMetadataUrl(string $hub): ?string
    {
        $parts = $hub !== '' ? parse_url($hub) : false;
        if (!\is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $host = str_contains($parts['host'], ':') && !str_starts_with($parts['host'], '[')
            ? '[' . $parts['host'] . ']'
            : $parts['host'];

        return ($parts['scheme'] ?? 'https') . '://' . $host
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . '/.well-known/oauth-protected-resource'
            . ($parts['path'] ?? '/.well-known/mercure');
    }

    private static function protocolCacheKey(string $hub): string
    {
        return 'sync-mercure-protocol-' . md5($hub);
    }

    private function cache(): ?\Grav\Common\Cache
    {
        try {
            $cache = Grav::instance()['cache'] ?? null;
        } catch (\Throwable) {
            return null;
        }

        return $cache instanceof \Grav\Common\Cache ? $cache : null;
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
        if ($private) {
            // Mercure reads a plain `private` form field. The `private[]=on`
            // this used to send is a different key, so the hub ignored it and
            // published every "private" update publicly.
            $fields['private'] = 'on';
        }
        $body = http_build_query($fields);

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
                // Never replay the Bearer token to wherever a redirect points.
                'follow_location' => 0,
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
        // A refused publish (bad token, wrong issuer or audience, a topic the
        // token doesn't grant) comes back as an HTTP error, not a transport
        // failure, so check the status too instead of treating it as sent.
        $status = (string)($http_response_header[0] ?? '');
        if (!preg_match('#^HTTP/\S+\s+2\d\d\b#', $status)) {
            $reason = trim(substr((string)$resp, 0, 200));
            throw new RuntimeException("sync-mercure: publish to {$hub} was refused ({$status})" . ($reason !== '' ? ": {$reason}" : ''));
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
        return $this->signToken($secret, 'publish', array_values($topics), 'grav-sync-mercure', 60);
    }

    /**
     * One token every hub generation accepts:
     *
     *   - a 0.x hub reads the `mercure` claim and ignores the rest;
     *   - a 1.x hub (modern, or in compatibility mode) reads
     *     `authorization_details` and checks the `at+jwt` type, `iss`
     *     against its issuer block, `aud` against its resource identifier,
     *     and `exp`.
     *
     * `aud` lists every identifier the hub may use: a pinned
     * `hub.resource_identifier` when set, then the public and internal hub
     * URLs, since a 1.x hub without a pinned identifier derives it from the
     * URL each request arrives on (browsers use the public one, PHP the
     * internal one).
     *
     * Always sent in the Authorization header: a 1.x hub refuses a token
     * carrying `authorization_details` in the `authorization` query
     * parameter, and a token in a URL ends up in access logs anyway.
     *
     * @param 'publish'|'subscribe' $action
     * @param list<string> $topics
     */
    private function signToken(string $secret, string $action, array $topics, string $subject, int $ttl): string
    {
        $now = time();
        $payload = [
            'iss' => $this->issuer(),
            'aud' => $this->audience(),
            'sub' => $subject,
            'client_id' => 'grav-sync-mercure',
            'jti' => bin2hex(random_bytes(16)),
            'iat' => $now,
            'exp' => $now + $ttl,
            'mercure' => [$action => $topics],
        ];
        // 1.x rejects a whole token when one detail has no topics, so a
        // token for nothing carries no detail at all (and grants nothing).
        if ($topics !== []) {
            $payload['authorization_details'] = [[
                'type' => self::AUTHORIZATION_DETAIL_TYPE,
                'actions' => [$action],
                'topics' => array_map(static fn (string $t): array => ['match' => $t], $topics),
            ]];
        }

        return JWT::encode($payload, $secret, 'HS256', null, ['typ' => 'at+jwt']);
    }

    /** `iss` of every token: must match the hub's `issuer` block on 1.x. */
    public function issuer(): string
    {
        $issuer = trim((string)$this->config->get('plugins.sync-mercure.hub.issuer', ''));

        return $issuer !== '' ? $issuer : 'https://localhost';
    }

    /**
     * `aud` of every token.
     *
     * @return list<string>
     */
    private function audience(): array
    {
        return array_values(array_unique(array_filter([
            trim((string)$this->config->get('plugins.sync-mercure.hub.resource_identifier', '')),
            $this->publicUrl(),
            $this->internalUrl(),
        ], static fn (string $v): bool => $v !== '')));
    }
}
