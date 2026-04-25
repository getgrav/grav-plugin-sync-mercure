<?php


declare(strict_types=1);

namespace Grav\Plugin\Console;

use Symfony\Component\Console\Style\SymfonyStyle;

require_once __DIR__ . '/MercureCommandBase.php';

class StopCommand extends MercureCommandBase
{
    protected function configure(): void
    {
        $this
            ->setName('stop')
            ->setDescription('Stop the running Mercure hub');
    }

    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';
        $io = new SymfonyStyle($this->input, $this->output);

        $pidFile = $this->pidFile();
        if (!is_file($pidFile)) {
            $io->note('Not running.');
            return 0;
        }
        $pid = (int)trim((string)file_get_contents($pidFile));
        if ($pid <= 0) {
            @unlink($pidFile);
            $io->note('Stale pidfile removed.');
            return 0;
        }
        if (!$this->pidAlive($pid)) {
            @unlink($pidFile);
            $io->note('Process already gone.');
            return 0;
        }
        if (!function_exists('posix_kill')) {
            $io->error('posix_kill is not available — stop the hub manually (pid ' . $pid . ').');
            return 1;
        }
        @posix_kill($pid, SIGTERM);
        for ($i = 0; $i < 20 && $this->pidAlive($pid); $i++) usleep(100_000);
        if ($this->pidAlive($pid)) @posix_kill($pid, SIGKILL);
        @unlink($pidFile);
        $io->success("Stopped Mercure (pid {$pid}).");
        return 0;
    }
}
