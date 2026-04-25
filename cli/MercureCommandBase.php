<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;

/**
 * Shared helpers for the sync-mercure subcommands. Each public command
 * (install, start, stop, status, logs) is a thin wrapper that calls the
 * matching method here so they can all share path discovery, pid checks,
 * and the Caddyfile template.
 */
abstract class MercureCommandBase extends ConsoleCommand
{
    protected const MERCURE_VERSION = '0.20.0';

    protected function getDataDir(): string
    {
        $base = (string)\Grav\Common\Grav::instance()['locator']->findResource('user-data://', true);
        return rtrim($base, '/') . '/sync-mercure';
    }

    protected function ensureDataDir(): string
    {
        $dir = $this->getDataDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    protected function getSecret(string $kind = 'publisher'): string
    {
        return (string)\Grav\Common\Grav::instance()['config']->get(
            'plugins.sync-mercure.hub.' . $kind . '_secret',
            ''
        );
    }

    protected function pidAlive(int $pid): bool
    {
        return $pid > 0 && function_exists('posix_kill') && @posix_kill($pid, 0);
    }

    protected function pidFile(): string
    {
        return $this->getDataDir() . '/mercure.pid';
    }

    protected function logFile(): string
    {
        return $this->getDataDir() . '/mercure.log';
    }

    protected function binPath(): string
    {
        return $this->getDataDir() . '/mercure';
    }

    protected function caddyfilePath(): string
    {
        return $this->getDataDir() . '/Caddyfile';
    }

    /**
     * Write a Caddyfile that runs the embedded Mercure module on :3001.
     * For dev only — production deployments should front the hub with
     * their own reverse proxy and proper TLS.
     */
    protected function writeCaddyfile(): string
    {
        $confPath = $this->caddyfilePath();
        $conf = <<<'CADDY'
{
    auto_https off
}

:3001 {
    encode zstd gzip

    mercure {
        publisher_jwt {env.MERCURE_PUBLISHER_JWT_KEY}
        subscriber_jwt {env.MERCURE_SUBSCRIBER_JWT_KEY}
        cors_origins *
        anonymous
    }

    respond /healthz 200
}
CADDY;
        file_put_contents($confPath, $conf);
        return $confPath;
    }

    protected function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_dir($f) ? $this->rrmdir($f) : @unlink($f);
        }
        @rmdir($dir);
    }
}
