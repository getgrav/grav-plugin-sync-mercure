<?php

/**
 * Regression check for sync channel privacy against a real Mercure hub.
 *
 *   php tests/hub-privacy-check.php <mercure-binary> [grav-root]
 *
 * <mercure-binary> is the hub `bin/plugin sync-mercure install` downloads
 * (user/data/sync-mercure/mercure). [grav-root] defaults to the site this
 * plugin sits in (user/plugins/sync-mercure → ../../..) and supplies Grav's
 * autoloader and the sync plugin (user/plugins/sync). Needs the `curl` CLI.
 * Starts two throwaway hubs on loopback: one with the Caddyfile
 * writeCaddyfile() generates, and one with `anonymous` on, like hubs
 * started before 1.2.2 until they are restarted.
 * Exits non-zero if any check fails.
 */

declare(strict_types=1);

use Grav\Common\Config\Config;
use Grav\Plugin\Sync\Channel;
use Grav\Plugin\Sync\Message\BroadcastMessage;
use Grav\Plugin\Sync\MessageType;
use Grav\Plugin\SyncMercure\MercureBridge;
use Grav\Plugin\SyncMercure\MercureTransport;

$bin = $argv[1] ?? '';
$gravRoot = rtrim($argv[2] ?? dirname(__DIR__, 4), '/');
if ($bin === '' || !is_executable($bin)) {
    fwrite(STDERR, "usage: php tests/hub-privacy-check.php <mercure-binary> [grav-root]\n");
    exit(2);
}

$loader = require $gravRoot . '/vendor/autoload.php';
$loader->addPsr4('Grav\\Plugin\\SyncMercure\\', dirname(__DIR__) . '/classes/SyncMercure');
$loader->addPsr4('Grav\\Plugin\\Sync\\', $gravRoot . '/user/plugins/sync/classes/Sync');
require dirname(__DIR__) . '/vendor/autoload.php';

$work = sys_get_temp_dir() . '/sync-mercure-check-' . bin2hex(random_bytes(4));
mkdir($work);
$pubKey = bin2hex(random_bytes(32));
$subKey = bin2hex(random_bytes(32));

/** Start a hub; returns [process, base url]. */
$startHub = function (bool $anonymous) use ($bin, $work, $pubKey, $subKey): array {
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $file = "{$work}/Caddyfile." . ($anonymous ? 'anon' : 'current');
    file_put_contents($file, "{\n    auto_https off\n    admin off\n}\n\nhttp://127.0.0.1:{$port} {\n    mercure {\n"
        . "        transport local\n        publisher_jwt {env.MERCURE_PUBLISHER_JWT_KEY}\n"
        . "        subscriber_jwt {env.MERCURE_SUBSCRIBER_JWT_KEY}\n        cors_origins *\n"
        . ($anonymous ? "        anonymous\n" : '') . "    }\n}\n");
    $env = ['MERCURE_PUBLISHER_JWT_KEY' => $pubKey, 'MERCURE_SUBSCRIBER_JWT_KEY' => $subKey, 'PATH' => getenv('PATH')];
    $proc = proc_open([$bin, 'run', '--config', $file, '--adapter', 'caddyfile'], [1 => ['file', "{$file}.log", 'w'], 2 => ['file', "{$file}.log", 'a']], $pipes, $work, $env);
    $url = "http://127.0.0.1:{$port}/.well-known/mercure";
    for ($i = 0; $i < 50 && @file_get_contents("http://127.0.0.1:{$port}/healthz") === false && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(100_000);
    }

    return [$proc, $url];
};

$bridgeFor = fn (string $hub): MercureBridge => new MercureBridge(new Config(['plugins' => ['sync-mercure' => [
    'enabled' => true,
    'hub' => ['public_url' => $hub, 'internal_url' => $hub, 'publisher_secret' => $pubKey, 'subscriber_secret' => $subKey],
    'topics' => ['prefix' => 'urn:grav:sync:'],
    'token_ttl_seconds' => 600,
]]]));

/**
 * Subscribe to $topics (optionally with a JWT), run $publish, and return the
 * data lines the subscriber received.
 *
 * @param list<string> $topics
 * @return array{status: int, received: list<string>}
 */
$listen = function (string $hub, array $topics, ?string $jwt, callable $publish) use ($work): array {
    $query = implode('&', array_map(static fn (string $t): string => 'topic=' . rawurlencode($t), $topics));
    if ($jwt !== null) {
        $query .= '&authorization=' . rawurlencode($jwt);
    }
    $out = "{$work}/sub-" . bin2hex(random_bytes(3));
    $proc = proc_open(['curl', '-sN', '-m', '3', '-o', $out, '-w', '%{http_code}', "{$hub}?{$query}"], [1 => ['pipe', 'w']], $pipes);
    usleep(700_000); // let the subscription register before publishing
    $publish();
    $status = (int)stream_get_contents($pipes[1]);
    proc_close($proc);
    $received = [];
    foreach (file(is_file($out) ? $out : '/dev/null', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (str_starts_with($line, 'data:')) {
            $received[] = trim(substr($line, 5));
        }
    }

    return ['status' => $status, 'received' => $received];
};

$failures = 0;
$check = function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok    ' : '  FAIL  ') . $label . "\n";
    $failures += $ok ? 0 : 1;
};

[$current, $currentHub] = $startHub(false);
[$legacy, $legacyHub] = $startHub(true);

try {
    $alice = new Channel('forum-pro:user/1', 'forum-pro', MessageType::Broadcast, fn () => true);
    $bob = new Channel('forum-pro:user/2', 'forum-pro', MessageType::Broadcast, fn () => true);
    $aliceTopic = 'urn:grav:forum-pro:user/1';
    $bobTopic = 'urn:grav:forum-pro:user/2';

    echo "Hub started with anonymous on (pre-1.2.2 config):\n";
    $bridge = $bridgeFor($legacyHub);
    $transport = new MercureTransport($bridge);
    $r = $listen($legacyHub, [$aliceTopic], null, function () use ($transport, $alice): void {
        $transport->publish($alice, new BroadcastMessage(['secret' => 'alice-note'], 'notification.created'));
    });
    $check('an anonymous subscriber receives no channel broadcast', $r['received'] === []);
    $r = $listen($legacyHub, [$aliceTopic], null, function () use ($bridge, $aliceTopic): void {
        $bridge->publishTopic($aliceTopic, ['control' => 'public'], false);
    });
    $check('(control) an explicitly public publish still reaches it', \count($r['received']) === 1);
    $r = $listen($legacyHub, ['urn:grav:sync:room-a:doc'], null, function () use ($bridge): void {
        $bridge->publish('room-a', 'doc', 'draft bytes');
    });
    $check('an anonymous subscriber receives no document update', $r['received'] === []);

    echo "Hub with the generated config:\n";
    $bridge = $bridgeFor($currentHub);
    $transport = new MercureTransport($bridge);
    $r = $listen($currentHub, [$aliceTopic], null, static function (): void {
    });
    $check('a subscriber without a JWT is refused', $r['status'] === 401);

    $aliceJwt = $transport->clientConfig($alice, null)['jwt'];
    $r = $listen($currentHub, [$aliceTopic, $bobTopic], $aliceJwt, function () use ($transport, $alice, $bob): void {
        $transport->publish($bob, new BroadcastMessage(['secret' => 'bob-note'], 'notification.created'));
        $transport->publish($alice, new BroadcastMessage(['secret' => 'alice-note'], 'notification.created'));
    });
    $check("user 1's JWT receives user 1's broadcast", \count($r['received']) === 1 && str_contains($r['received'][0], 'alice-note'));
    $check("user 1's JWT receives nothing from user 2's channel", !str_contains(implode('', $r['received']), 'bob-note'));

    $roomJwt = $bridge->issueSubscriberJwt('room-a', 'editor');
    $r = $listen($currentHub, ['urn:grav:sync:room-a:doc', 'urn:grav:sync:room-b:doc'], $roomJwt, function () use ($bridge): void {
        $bridge->publish('room-b', 'doc', 'other room');
        $bridge->publish('room-a', 'doc', 'this room');
    });
    $check("a room JWT receives only its own room's document updates", \count($r['received']) === 1
        && str_contains($r['received'][0], base64_encode('this room')));
} finally {
    foreach ([$current, $legacy] as $proc) {
        proc_terminate($proc);
        proc_close($proc);
    }
    // The hub keeps its Caddy state in the working directory.
    $files = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($work, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($work);
}

echo $failures === 0 ? "All checks passed.\n" : "{$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
