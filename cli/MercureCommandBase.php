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

    /**
     * Best-effort HTTP GET for the install tarball. cURL when available
     * (handles redirects + HTTPS without surprises), falls back to the
     * file_get_contents stream wrapper otherwise.
     */
    protected function httpGet(string $url): string|false
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_USERAGENT      => 'grav-plugin-sync-mercure',
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (is_string($body) && $code >= 200 && $code < 300) {
                return $body;
            }
            return false;
        }
        $ctx = stream_context_create([
            'http' => [
                'follow_location' => 1,
                'max_redirects'   => 5,
                'timeout'         => 120,
                'user_agent'      => 'grav-plugin-sync-mercure',
            ],
        ]);
        $r = @file_get_contents($url, false, $ctx);
        return $r === false ? false : $r;
    }

    /**
     * Path to the per-environment config override file. Writing here
     * leaves the plugin's bundled sync-mercure.yaml defaults intact and
     * just layers the overrides we generate on top.
     */
    protected function userConfigPath(): string
    {
        $base = (string)\Grav\Common\Grav::instance()['locator']->findResource('config://', true);
        if ($base === '') {
            $base = (string)\Grav\Common\Grav::instance()['locator']->findResource('user://config', true);
        }
        return rtrim($base, '/') . '/plugins/sync-mercure.yaml';
    }

    /**
     * Ensure a usable config exists. If the user-side override file is
     * missing or has no secrets/URL, fill it in with sensible defaults
     * (random JWT secret + localhost hub URL on port 3001).
     *
     * Returns true if anything was written, false when the existing file
     * already had the required values and no changes were needed.
     */
    protected function ensureConfig(): array
    {
        $path = $this->userConfigPath();
        @mkdir(dirname($path), 0755, true);

        $existing = [];
        if (is_file($path)) {
            try {
                $parsed = \Symfony\Component\Yaml\Yaml::parseFile($path);
                if (is_array($parsed)) $existing = $parsed;
            } catch (\Throwable) {
                $existing = [];
            }
        }

        $hub = $existing['hub'] ?? [];
        $changes = [];

        if (empty($hub['publisher_secret'])) {
            $hub['publisher_secret'] = bin2hex(random_bytes(32));
            $changes[] = 'publisher_secret (generated)';
        }
        if (empty($hub['subscriber_secret'])) {
            $hub['subscriber_secret'] = $hub['publisher_secret'];
            $changes[] = 'subscriber_secret (matched to publisher)';
        }
        if (empty($hub['public_url'])) {
            $hub['public_url'] = 'http://localhost:3001/.well-known/mercure';
            $changes[] = 'public_url (localhost default)';
        }

        if ($changes === []) {
            return ['path' => $path, 'changes' => [], 'hub' => $hub];
        }

        $existing['hub'] = $hub;
        // Make sure the plugin is enabled in the override too — if the
        // user hand-disabled it we leave their value intact.
        if (!array_key_exists('enabled', $existing)) {
            $existing['enabled'] = true;
        }

        $yaml = \Symfony\Component\Yaml\Yaml::dump($existing, 4, 2);
        // Header comment so the file is recognisable at a glance.
        $header = "# grav-plugin-sync-mercure overrides\n"
            . "# Generated/updated by `bin/plugin sync-mercure install`. Edit freely;\n"
            . "# values you set here override the plugin's bundled sync-mercure.yaml.\n\n";
        file_put_contents($path, $header . $yaml);

        // Bust Grav's compiled config cache so the new values are visible
        // to subsequent commands in the same shell session.
        $cacheDir = (string)\Grav\Common\Grav::instance()['locator']->findResource('cache://compiled', true);
        if ($cacheDir !== '' && is_dir($cacheDir)) {
            foreach (glob($cacheDir . '/config/*.php') ?: [] as $f) {
                @unlink($f);
            }
        }

        return ['path' => $path, 'changes' => $changes, 'hub' => $hub];
    }
}
