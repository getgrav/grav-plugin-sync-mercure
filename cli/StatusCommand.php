<?php


declare(strict_types=1);

namespace Grav\Plugin\Console;

use Symfony\Component\Console\Style\SymfonyStyle;

require_once __DIR__ . '/MercureCommandBase.php';

class StatusCommand extends MercureCommandBase
{
    protected function configure(): void
    {
        $this
            ->setName('status')
            ->setDescription('Report whether the Mercure hub is running');
    }

    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';
        $io = new SymfonyStyle($this->input, $this->output);

        $pidFile = $this->pidFile();
        if (!is_file($pidFile)) {
            $io->writeln('<comment>not running</comment>');
            return 1;
        }
        $pid = (int)trim((string)file_get_contents($pidFile));
        if ($pid <= 0 || !$this->pidAlive($pid)) {
            $io->writeln('<comment>stale pidfile (process not alive)</comment>');
            return 1;
        }
        $io->writeln("<info>running</info>  pid={$pid}");
        return 0;
    }
}
