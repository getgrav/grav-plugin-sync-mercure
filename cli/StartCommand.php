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

        $publisherSecret = $this->getSecret('publisher');
        $subscriberSecret = $this->getSecret('subscriber') ?: $publisherSecret;
        if ($publisherSecret === '') {
            $io->error('Set plugins.sync-mercure.hub.publisher_secret (and ideally subscriber_secret) before starting the hub.');
            return 1;
        }

        $caddyConf = $this->writeCaddyfile();
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

        $cmd = 'cd ' . escapeshellarg($base)
            . ' && ' . $envStr
            . ' ' . escapeshellarg($binPath)
            . ' run --config ' . escapeshellarg($caddyConf)
            . ' > ' . escapeshellarg($logFile) . ' 2>&1 & echo $! > ' . escapeshellarg($pidFile);

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
        $io->success("Started Mercure (pid {$pid}) on http://localhost:3001/.well-known/mercure");
        $io->writeln('Set hub.public_url to that URL in plugins/sync-mercure.yaml.');
        return 0;
    }
}
