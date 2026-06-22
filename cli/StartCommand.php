<?php


declare(strict_types=1);

namespace Grav\Plugin\Console;

use Symfony\Component\Console\Style\SymfonyStyle;

require_once __DIR__ . '/MercureCommandBase.php';

class StartCommand extends MercureCommandBase
{
    protected function configure(): void
    {
        $this
            ->setName('start')
            ->setDescription('Start the Mercure hub in the background');
    }

    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';
        $io = new SymfonyStyle($this->input, $this->output);

        $base = $this->ensureDataDir();
        $binPath = $this->binPath();
        if (!is_file($binPath)) {
            $io->error('Not installed. Run: bin/plugin sync-mercure install');
            return 1;
        }

        $pidFile = $this->pidFile();
        if (is_file($pidFile)) {
            $pid = (int)trim((string)@file_get_contents($pidFile));
            if ($pid > 0 && $this->pidAlive($pid)) {
                $io->note("Already running (pid {$pid}).");
                return 0;
            }
            @unlink($pidFile);
        }

        // Idempotent — generates secrets / public_url only if missing.
        // Lets `start` succeed even if the user skipped `install` (or
        // skipped editing the config before running it).
        $cfg = $this->ensureConfig();
        if ($cfg['changes'] !== []) {
            $io->writeln('Generated config at ' . $cfg['path'] . ':');
            foreach ($cfg['changes'] as $c) $io->writeln("  • {$c}");
        }

        // Read secrets via Grav config (cache was busted in ensureConfig);
        // fall back to the freshly-generated values to avoid a race on
        // very fast invocations.
        $publisherSecret = $this->getSecret('publisher') ?: ($cfg['hub']['publisher_secret'] ?? '');
        $subscriberSecret = $this->getSecret('subscriber') ?: ($cfg['hub']['subscriber_secret'] ?? $publisherSecret);
        if ($publisherSecret === '') {
            $io->error('Could not establish a publisher secret. Check ' . $cfg['path']);
            return 1;
        }

        // Generate (or reuse) a TLS cert for the hub. mkcert if available
        // for trusted-by-browsers certs, otherwise self-signed. Without
        // TLS the hub can't be reached from an HTTPS-served admin-next
        // because of mixed-content blocking.
        try {
            $certInfo = $this->ensureCert();
            if ($certInfo['tool'] !== 'reused') {
                $io->writeln('Generated TLS cert via ' . $certInfo['tool'] . ' at ' . $certInfo['cert']);
                if ($certInfo['tool'] === 'openssl') {
                    $io->note(
                        'Self-signed cert in use. Visit https://localhost:3001/healthz '
                        . 'in each browser once to accept the cert.'
                    );
                }
            }
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return 1;
        }

        // Prefer the configured port, but step up if it's already taken so
        // a second Grav site on this host doesn't collide on 3001. Persist
        // the choice into public_url so the browser and PHP both follow.
        $preferred = $this->configuredPort();
        $port = $this->resolvePort($preferred);
        if ($port !== $preferred) {
            $this->persistPublicUrlPort($port);
            $io->note("Port {$preferred} is busy — hub moving to {$port} (public_url updated).");
        }
        $caddyConf = $this->writeCaddyfile($certInfo['cert'], $certInfo['key'], $port);
        $logFile = $this->logFile();

        $env = [
            'MERCURE_PUBLISHER_JWT_KEY'  => $publisherSecret,
            'MERCURE_SUBSCRIBER_JWT_KEY' => $subscriberSecret,
        ];
        $envStr = implode(' ', array_map(
            static fn ($k, $v) => $k . '=' . escapeshellarg((string)$v),
            array_keys($env),
            array_values($env),
        ));

        // `exec` replaces the subshell with the env-then-mercure process so
        // the PID we capture in the pidfile *is* the mercure PID. Without
        // exec, `$!` was the subshell wrapper, which exits when mercure
        // exits and leaves an orphan if you tried to stop it (the stop
        // command would kill the long-dead wrapper instead of mercure).
        // `exec env VAR=val ... cmd ...` replaces the subshell with env(1),
        // which in turn exec's mercure — keeping a single PID across the
        // chain so $! captured outside is mercure's actual PID. Plain
        // `VAR=val cmd` syntax doesn't work after `exec` because exec
        // requires a command name, not an assignment.
        $cmd = '(cd ' . escapeshellarg($base)
            . ' && exec env ' . $envStr
            . ' ' . escapeshellarg($binPath)
            . ' run --config ' . escapeshellarg($caddyConf)
            . ' > ' . escapeshellarg($logFile) . ' 2>&1) & echo $! > ' . escapeshellarg($pidFile);

        exec('(' . $cmd . ') > /dev/null 2>&1');
        sleep(1);

        if (!is_file($pidFile)) {
            $io->error('Failed to start. See ' . $logFile);
            return 1;
        }
        $pid = (int)trim((string)file_get_contents($pidFile));
        if (!$this->pidAlive($pid)) {
            $io->error('Process exited immediately. See ' . $logFile);
            return 1;
        }
        // Report the port we actually bound. The in-memory config still
        // holds the pre-resolution value (the cache bust only helps the next
        // process), so swap the resolved port into the configured URL.
        $publicUrl = (string)\Grav\Common\Grav::instance()['config']->get(
            'plugins.sync-mercure.hub.public_url',
            'https://localhost:3001/.well-known/mercure'
        );
        $configuredPortInUrl = (int)parse_url($publicUrl, PHP_URL_PORT);
        if ($configuredPortInUrl > 0 && $configuredPortInUrl !== $port) {
            $publicUrl = preg_replace(
                '#:' . $configuredPortInUrl . '(/|$)#',
                ':' . $port . '$1',
                $publicUrl,
                1
            ) ?? $publicUrl;
        }
        $io->success("Started Mercure (pid {$pid}) at {$publicUrl}");
        $io->writeln('Hard-reload admin2 to pick up the new transport.');
        return 0;
    }
}
