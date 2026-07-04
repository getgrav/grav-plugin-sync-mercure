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

    /** systemd unit basename (becomes grav-mercure.service). */
    protected const SERVICE_NAME = 'grav-mercure';

    /** launchd job label (reverse-DNS, becomes the plist filename). */
    protected const LAUNCHD_LABEL = 'org.getgrav.mercure';

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
     * Port the hub should prefer: whatever sits in the configured
     * public_url, else 3001. resolvePort() walks up from here when it's
     * already taken (e.g. a second Grav site on the same host).
     */
    protected function configuredPort(): int
    {
        $url = (string)\Grav\Common\Grav::instance()['config']->get(
            'plugins.sync-mercure.hub.public_url',
            ''
        );
        $port = $url !== '' ? (int)parse_url($url, PHP_URL_PORT) : 0;
        return $port > 0 ? $port : 3001;
    }

    /**
     * True if nothing is bound to $port. Tests a bind on 0.0.0.0 to match
     * how Caddy's ":<port>" listener grabs all interfaces — so the answer
     * lines up with what the hub will actually attempt.
     */
    protected function portIsFree(int $port): bool
    {
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_server("tcp://0.0.0.0:{$port}", $errno, $errstr);
        if ($sock === false) {
            return false;
        }
        fclose($sock);
        return true;
    }

    /**
     * Return the first free port at or above $preferred, so two sites on
     * one host don't both fight over 3001. Gives up after $tries and
     * returns $preferred (let the hub surface the real bind error).
     */
    protected function resolvePort(int $preferred, int $tries = 20): int
    {
        for ($p = $preferred; $p < $preferred + $tries; $p++) {
            if ($this->portIsFree($p)) {
                return $p;
            }
        }
        return $preferred;
    }

    /**
     * Rewrite the :port segment of hub.public_url in the user override so
     * the browser (clientConfig) and the PHP publisher (internalUrl falls
     * back to publicUrl) both target the port the hub actually bound.
     * Returns the new URL, or null when no change was needed/possible.
     */
    protected function persistPublicUrlPort(int $port): ?string
    {
        $path = $this->userConfigPath();
        if (!is_file($path)) {
            return null;
        }
        try {
            $parsed = \Symfony\Component\Yaml\Yaml::parseFile($path);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($parsed)) {
            return null;
        }
        $url = (string)($parsed['hub']['public_url'] ?? '');
        $current = $url !== '' ? (int)parse_url($url, PHP_URL_PORT) : 0;
        // Only safe to swap when the URL carries an explicit port already.
        if ($url === '' || $current <= 0 || $current === $port) {
            return null;
        }
        $new = preg_replace('#:' . $current . '(/|$)#', ':' . $port . '$1', $url, 1);
        if (!is_string($new) || $new === $url) {
            return null;
        }
        $parsed['hub']['public_url'] = $new;
        $yaml = \Symfony\Component\Yaml\Yaml::dump($parsed, 4, 2);
        $header = "# grav-plugin-sync-mercure overrides\n"
            . "# Generated/updated by `bin/plugin sync-mercure install`. Edit freely;\n"
            . "# values you set here override the plugin's bundled sync-mercure.yaml.\n\n";
        file_put_contents($path, $header . $yaml);
        $this->bustConfigCache();
        return $new;
    }

    /**
     * Which service manager autostart should target on this host.
     * Returns 'launchd' (macOS), 'systemd-system' (Linux as root),
     * 'systemd-user' (Linux as a normal user), or 'unsupported'.
     */
    protected function serviceManager(): string
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            return $this->commandExists('launchctl') ? 'launchd' : 'unsupported';
        }
        if (PHP_OS_FAMILY === 'Linux' && $this->commandExists('systemctl')) {
            $isRoot = function_exists('posix_getuid') && posix_getuid() === 0;
            return $isRoot ? 'systemd-system' : 'systemd-user';
        }
        return 'unsupported';
    }

    protected function homeDir(): string
    {
        return rtrim((string)($_SERVER['HOME'] ?? getenv('HOME') ?: ''), '/');
    }

    protected function launchdPlistPath(): string
    {
        return $this->homeDir() . '/Library/LaunchAgents/' . self::LAUNCHD_LABEL . '.plist';
    }

    protected function systemdUserUnitPath(): string
    {
        return $this->homeDir() . '/.config/systemd/user/' . self::SERVICE_NAME . '.service';
    }

    protected function systemdSystemUnitPath(): string
    {
        return '/etc/systemd/system/' . self::SERVICE_NAME . '.service';
    }

    protected function runShell(string $cmd): int
    {
        $rc = 0;
        passthru($cmd, $rc);
        return $rc;
    }

    protected function certPath(): string
    {
        return $this->getDataDir() . '/cert.pem';
    }

    protected function keyPath(): string
    {
        return $this->getDataDir() . '/key.pem';
    }

    /**
     * Ensure a TLS cert+key pair exists for the hub. Strategy:
     *
     *   1. If cert.pem and key.pem already sit in the data dir, reuse.
     *   2. If `mkcert` is on PATH, use it. mkcert installs a local CA
     *      root in the system trust store on first run, so browsers
     *      trust the resulting cert without any warnings — exactly the
     *      Symfony-recommended local-dev setup.
     *   3. Otherwise fall back to a self-signed cert via openssl. Browsers
     *      will show a one-time warning the first time the user visits
     *      https://localhost:3001/healthz; once accepted, EventSource
     *      connections work for the lifetime of that exception.
     *
     * Returns ['cert' => …, 'key' => …, 'tool' => 'mkcert'|'openssl'|'reused'].
     */
    protected function ensureCert(): array
    {
        $cert = $this->certPath();
        $key = $this->keyPath();
        if (is_file($cert) && is_file($key)) {
            return ['cert' => $cert, 'key' => $key, 'tool' => 'reused'];
        }

        $dataDir = $this->ensureDataDir();
        $hosts = ['localhost', '127.0.0.1', '::1'];

        if ($this->commandExists('mkcert')) {
            $rc = 0;
            $cmd = 'cd ' . escapeshellarg($dataDir)
                . ' && mkcert -cert-file ' . escapeshellarg($cert)
                . ' -key-file ' . escapeshellarg($key)
                . ' ' . implode(' ', array_map('escapeshellarg', $hosts));
            passthru($cmd, $rc);
            if ($rc === 0 && is_file($cert) && is_file($key)) {
                return ['cert' => $cert, 'key' => $key, 'tool' => 'mkcert'];
            }
        }

        if (!$this->commandExists('openssl')) {
            throw new \RuntimeException(
                'Neither mkcert nor openssl found on PATH. Install one of them, '
                . 'or pre-place cert.pem and key.pem in ' . $dataDir
            );
        }

        $san = 'subjectAltName=DNS:localhost,IP:127.0.0.1,IP:0:0:0:0:0:0:0:1';
        $cmd = 'openssl req -x509 -newkey rsa:2048 -nodes'
            . ' -keyout ' . escapeshellarg($key)
            . ' -out ' . escapeshellarg($cert)
            . ' -days 3650 -subj /CN=localhost'
            . ' -addext ' . escapeshellarg($san)
            . ' 2>/dev/null';
        $rc = 0;
        passthru($cmd, $rc);
        if ($rc !== 0 || !is_file($cert) || !is_file($key)) {
            throw new \RuntimeException('openssl failed to generate cert; check that openssl is functional.');
        }
        return ['cert' => $cert, 'key' => $key, 'tool' => 'openssl'];
    }

    protected function commandExists(string $cmd): bool
    {
        $out = [];
        $rc = 0;
        @exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null', $out, $rc);
        return $rc === 0 && !empty($out);
    }

    /**
     * Write a Caddyfile that runs the embedded Mercure module on $port
     * with HTTPS using a cert pair from $this->ensureCert().
     *
     * `admin off` disables Caddy's admin API (default :2019). The hub never
     * uses it, and leaving it on means a second Grav site on the same host
     * collides on 2019 even after the data port has been bumped.
     *
     * `transport` is set explicitly because Mercure 0.24 dropped the implicit
     * default transport — without it the hub aborts at startup with "invalid
     * transport". We use `local` (in-memory) rather than bolt: the bolt module
     * shipped in Mercure 0.24.2 aborts provisioning with "invalid transport:
     * timeout" even with the modern `transport bolt { path … }` block, so it is
     * unusable on this build. The tradeoff is no on-disk Last-Event-ID replay;
     * collaborative clients resync through the API on reconnect anyway.
     */
    protected function writeCaddyfile(string $certFile, string $keyFile, int $port = 3001): string
    {
        $confPath = $this->caddyfilePath();
        $conf = '{
    auto_https off
    admin off
}

:' . $port . ' {
    tls ' . $certFile . ' ' . $keyFile . '
    encode zstd gzip

    mercure {
        transport local
        publisher_jwt {env.MERCURE_PUBLISHER_JWT_KEY}
        subscriber_jwt {env.MERCURE_SUBSCRIBER_JWT_KEY}
        cors_origins *
        anonymous
    }

    respond /healthz 200
}
';
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
            $hub['public_url'] = 'https://localhost:3001/.well-known/mercure';
            $changes[] = 'public_url (https://localhost:3001 default)';
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

        $this->bustConfigCache();

        return ['path' => $path, 'changes' => $changes, 'hub' => $hub];
    }

    /**
     * Bust Grav's compiled config cache so freshly-written override values
     * are visible to subsequent commands in the same shell session.
     */
    protected function bustConfigCache(): void
    {
        $cacheDir = (string)\Grav\Common\Grav::instance()['locator']->findResource('cache://compiled', true);
        if ($cacheDir !== '' && is_dir($cacheDir)) {
            foreach (glob($cacheDir . '/config/*.php') ?: [] as $f) {
                @unlink($f);
            }
        }
    }
}
