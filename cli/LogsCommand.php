<?php


declare(strict_types=1);

namespace Grav\Plugin\Console;

use Symfony\Component\Console\Style\SymfonyStyle;

require_once __DIR__ . '/MercureCommandBase.php';

class LogsCommand extends MercureCommandBase
{
    protected function configure(): void
    {
        $this
            ->setName('logs')
            ->setDescription('Print the most recent Mercure hub log entries');
    }

    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';
        $io = new SymfonyStyle($this->input, $this->output);

        $logFile = $this->logFile();
        if (!is_file($logFile)) {
            $io->note('No log file yet.');
            return 0;
        }
        $contents = (string)file_get_contents($logFile);
        $tail = implode("\n", array_slice(explode("\n", $contents), -50));
        $io->writeln($tail);
        return 0;
    }
}
