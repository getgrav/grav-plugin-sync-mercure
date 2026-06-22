<?php


declare(strict_types=1);

namespace Grav\Plugin\Console;

use Symfony\Component\Console\Style\SymfonyStyle;

require_once __DIR__ . '/MercureCommandBase.php';

/**
 * Tear down the autostart service installed by `enable`: stop it, remove the
 * unit/plist, and reload the manager. Mirrors the detection in EnableCommand
 * so the right manager (systemd user/system, or launchd) is targeted.
 */
class DisableCommand extends MercureCommandBase
{
    protected function configure(): void
    {
        $this
            ->setName('disable')
            ->setDescription('Remove the autostart service (stops the hub from starting on boot)');
    }

    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';
        $io = new SymfonyStyle($this->input, $this->output);

        $manager = $this->serviceManager();
        return match ($manager) {
            'launchd'        => $this->disableLaunchd($io),
            'systemd-user'   => $this->disableSystemd($io, false),
            'systemd-system' => $this->disableSystemd($io, true),
            default          => $this->none($io),
        };
    }

    private function none(SymfonyStyle $io): int
    {
        $io->note('No supported service manager found — nothing to disable.');
        return 0;
    }

    private function disableSystemd(SymfonyStyle $io, bool $system): int
    {
        $unitPath = $system ? $this->systemdSystemUnitPath() : $this->systemdUserUnitPath();
        $svc = self::SERVICE_NAME . '.service';
        $ctl = $system ? 'systemctl' : 'systemctl --user';

        if (!is_file($unitPath)) {
            $io->note('No systemd unit at ' . $unitPath . ' — nothing to disable.');
            return 0;
        }

        $this->runShell($ctl . ' disable --now ' . escapeshellarg($svc) . ' 2>/dev/null');
        @unlink($unitPath);
        $this->runShell($ctl . ' daemon-reload');
        $io->success('Removed systemd autostart (' . ($system ? 'system' : 'user') . '): ' . $unitPath);
        return 0;
    }

    private function disableLaunchd(SymfonyStyle $io): int
    {
        $plistPath = $this->launchdPlistPath();
        $uid = function_exists('posix_getuid') ? posix_getuid() : (int)getmyuid();
        $label = self::LAUNCHD_LABEL;

        $loaded = $this->runShell('launchctl print gui/' . $uid . '/' . $label . ' > /dev/null 2>&1') === 0;
        if (!is_file($plistPath) && !$loaded) {
            $io->note('No launchd agent loaded or on disk — nothing to disable.');
            return 0;
        }

        $rc = $this->runShell('launchctl bootout gui/' . $uid . '/' . $label . ' 2>/dev/null');
        if ($rc !== 0 && is_file($plistPath)) {
            // Legacy fallback for older macOS.
            $this->runShell('launchctl unload -w ' . escapeshellarg($plistPath) . ' 2>/dev/null');
        }
        @unlink($plistPath);
        $io->success('Removed launchd autostart: ' . $plistPath);
        return 0;
    }
}
