<?php

declare(strict_types=1);

namespace Grav\Plugin\SyncMercure;

use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Sync\Channel;
use Grav\Plugin\Sync\Message\AwarenessMessage;
use Grav\Plugin\Sync\Message\BroadcastMessage;
use Grav\Plugin\Sync\Message\CrdtMessage;
use Grav\Plugin\Sync\Message\Message;
use Grav\Plugin\Sync\MessageType;
use Grav\Plugin\Sync\Transport\TransportInterface;

/**
 * Mercure SSE implementation of sync's TransportInterface (Round 4a).
 *
 * Wraps the existing MercureBridge primitives (publishTopic /
 * issueSubscriberJwtForTopics) so any sync channel (CRDT, broadcast, or
 * awareness) can flow through Mercure when this plugin is installed.
 *
 * Coexists with the legacy onSyncUpdate / onSyncAwareness event subscribers
 * in sync-mercure.php for the editor-pro CodeMirror collab path. Both
 * pipelines run side-by-side this round; future rounds may collapse the
 * legacy subscribers once editor-pro switches to the facade.
 */
final class MercureTransport implements TransportInterface
{
    /**
     * Channels auto-registered by sync's SyncController for editor-pro
     * rooms use this prefix in their id (e.g. "editor-pro:foo@default").
     * The wire topics editor-pro's client subscribes to are scoped by the
     * bare room id, so we strip the prefix when computing the topic.
     */
    private const EDITOR_PRO_PREFIX = 'editor-pro:';

    public function __construct(
        private readonly MercureBridge $bridge,
    ) {
    }

    public function id(): string
    {
        return 'mercure';
    }

    public function name(): string
    {
        return 'Mercure SSE';
    }

    public function isAvailable(): bool
    {
        try {
            return $this->bridge->isAvailable();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    public function supportedMessageTypes(): array
    {
        return [
            MessageType::Crdt->value,
            MessageType::Broadcast->value,
            MessageType::Awareness->value,
        ];
    }

    public function priority(): int
    {
        return 50;
    }

    public function publish(Channel $channel, Message $message): void
    {
        if ($message->type() !== $channel->messageType) {
            // Defensive: facade already validates.
            return;
        }

        switch (true) {
            case $message instanceof CrdtMessage:
                $this->publishCrdt($channel, $message);
                return;

            case $message instanceof BroadcastMessage:
                $this->publishBroadcast($channel, $message);
                return;

            case $message instanceof AwarenessMessage:
                $this->publishAwareness($channel, $message);
                return;
        }
    }

    public function clientConfig(Channel $channel, ?UserInterface $user): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $userId = $this->resolveUserId($user);
        $topics = $this->topicsFor($channel);

        try {
            $jwt = $this->bridge->issueSubscriberJwtForTopics($topics, $userId);
            $hubUrl = $this->bridge->publicHubUrl();
        } catch (\Throwable) {
            return [];
        }

        return [
            'transport' => 'mercure',
            'hubUrl' => $hubUrl,
            'jwt' => $jwt,
            'topics' => $topics,
            'apiVersion' => MercureBridge::API_VERSION,
        ];
    }

    /**
     * CRDT publishes follow MercureBridge::publish() byte-for-byte: the
     * envelope is `{channel, bytes, serverTimeMs}`, the topic uses the
     * sync prefix plus the bare room id plus the `:doc` suffix. This makes
     * the new transport-interface path produce the same wire output as the
     * legacy onSyncUpdate subscriber.
     */
    private function publishCrdt(Channel $channel, CrdtMessage $message): void
    {
        $roomId = $this->roomIdFromChannelId($channel->id);
        $this->bridge->publish($roomId, 'doc', $message->bytes);
    }

    private function publishBroadcast(Channel $channel, BroadcastMessage $message): void
    {
        $topic = 'urn:grav:' . $channel->id;
        $this->bridge->publishTopic($topic, [
            'event' => $message->eventName,
            'data' => $message->payload,
            'ts' => $message->timestamp,
        ]);
    }

    private function publishAwareness(Channel $channel, AwarenessMessage $message): void
    {
        $topic = 'urn:grav:' . $channel->id . ':awareness';
        $this->bridge->publishTopic($topic, [
            'payload' => $message->payload,
            'sourceClientId' => $message->sourceClientId,
        ]);
    }

    /**
     * Topic list a subscriber needs for a given channel.
     *
     *   Crdt:      [urn:grav:sync:<roomId>:doc, urn:grav:sync:<roomId>:aw]
     *              (matches the editor-pro client's existing expectation)
     *   Broadcast: [urn:grav:<channel.id>] plus optional sub-topic if the
     *              channel metadata advertises one (see
     *              metadata.subscribeSubTopics).
     *   Awareness: [urn:grav:<channel.id>:awareness]
     *
     * @return list<string>
     */
    private function topicsFor(Channel $channel): array
    {
        return match ($channel->messageType) {
            MessageType::Crdt => (function () use ($channel) {
                $roomId = $this->roomIdFromChannelId($channel->id);
                return [
                    $this->bridge->topicFor($roomId, 'doc'),
                    $this->bridge->topicFor($roomId, 'aw'),
                ];
            })(),
            MessageType::Broadcast => (function () use ($channel) {
                $topics = ['urn:grav:' . $channel->id];
                /** @var list<string>|null $extra */
                $extra = $channel->metadata['subscribeSubTopics'] ?? null;
                if (is_array($extra)) {
                    foreach ($extra as $sub) {
                        if (is_string($sub) && $sub !== '') {
                            $topics[] = 'urn:grav:' . $channel->id . ':' . $sub;
                        }
                    }
                }
                return $topics;
            })(),
            MessageType::Awareness => ['urn:grav:' . $channel->id . ':awareness'],
        };
    }

    /**
     * For sync's auto-registered CRDT channels (id = "editor-pro:<roomId>"),
     * the wire topic uses the bare roomId. For any other CRDT channel id
     * (manually registered without the sync prefix), we treat the id
     * itself as the room id, so the wire format degrades sensibly.
     */
    private function roomIdFromChannelId(string $channelId): string
    {
        if (str_starts_with($channelId, self::EDITOR_PRO_PREFIX)) {
            return substr($channelId, strlen(self::EDITOR_PRO_PREFIX));
        }
        return $channelId;
    }

    private function resolveUserId(?UserInterface $user): string
    {
        if ($user !== null && $user->authenticated) {
            $username = (string)($user->get('username') ?? '');
            if ($username !== '') {
                return $username;
            }
        }
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0');
        $sid = function_exists('session_id') ? (string)session_id() : '';
        return 'anon-' . substr(sha1($ip . '|' . $sid), 0, 12);
    }
}
