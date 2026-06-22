<?php


declare(strict_types=1);

namespace Grav\Plugin\Console;

use Symfony\Component\Console\Style\SymfonyStyle;

require_once __DIR__ . '/MercureCommandBase.php';

/**
 * Install an OS-native autostart service for the hub so it comes back on
 * reboot. Detects the host's service manager — systemd on Linux (a user
 * unit + linger when run as a normal user, a system unit when run as root)
 * or launchd on macOS — writes the matching unit/plist and enables it. The
 * service runs the bundled mercure binary directly (no PHP/Grav bootstrap),
 * so the manager owns the process and can restart it on crash.
 */
class EnableCommand extends MercureCommandBase
{
    protected function configure(): void
    {
        $this
            ->setName('enable')
            ->setDescription('Install an autostart service so the hub starts on boot (systemd/launchd)');
    }

    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';
        $io = new SymfonyStyle($this->input, $this->output);

        $binPath = $this->binPath();
        if (!is_file($binPath)) {
            $io->error('Not installed. Run: bin/plugin sync-mercure install');
            return 1;
        }

        $manager = $this->serviceManager();
        if ($manager === 'unsupported') {
            $io->error(
                'No supported service manager found (need systemd on Linux or launchd on macOS). '
                . 'Run the hub under your own supervisor pointing at:'
            );
            $io->writeln('  ' . $binPath . ' run --config ' . $this->caddyfilePath());
            return 1;
        }

        // Make the runtime fully self-contained: secrets, a resolved port,
        // a TLS cert, and a matching Caddyfile must all exist before the
        // service references them.
        $cfg = $this->ensureConfig();
        $publisherSecret = $this->getSecret('publisher') ?: ($cfg['hub']['publisher_secret'] ?? '');
        $subscriberSecret = $this->getSecret('subscriber') ?: ($cfg['hub']['subscriber_secret'] ?? $publisherSecret);
        if ($publisherSecret === '') {
            $io->error('Could not establish a publisher secret. Check ' . $cfg['path']);
            return 1;
        }

        try {
            $certInfo = $this->ensureCert();
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return 1;
        }

        // Stop any `start`-forked instance first, then resolve the port —
        // so the service can reclaim the configured port the old process was
        // holding instead of needlessly stepping past it.
        $this->stopForkedInstance($io);

        $preferred = $this->configuredPort();
        $port = $this->resolvePort($preferred);
        if ($port !== $preferred) {
            $this->persistPublicUrlPort($port);
            $io->note("Port {$preferred} is busy — hub will use {$port} (public_url updated).");
        }
        $caddyConf = $this->writeCaddyfile($certInfo['cert'], $certInfo['key'], $port);

        $env = [
            'MERCURE_PUBLISHER_JWT_KEY'  => $publisherSecret,
            'MERCURE_SUBSCRIBER_JWT_KEY' => $subscriberSecret,
        ];

        return match ($manager) {
            'launchd'        => $this->enableLaunchd($io, $binPath, $caddyConf, $env),
            'systemd-user'   => $this->enableSystemd($io, $binPath, $caddyConf, $env, false),
            'systemd-system' => $this->enableSystemd($io, $binPath, $caddyConf, $env, true),
            default          => 1,
        };
    }

    /**
     * Kill any hub started via `bin/plugin sync-mercure start` so it doesn't
     * hold the port the autostart service is about to take.
     */
    private function stopForkedInstance(SymfonyStyle $io): void
    {
        $pidFile = $this->pidFile();
        if (!is_file($pidFile)) {
            return;
        }
        $pid = (int)trim((string)file_get_contents($pidFile));
        if ($pid > 0 && $this->pidAlive($pid) && function_exists('posix_kill')) {
            @posix_kill($pid, SIGTERM);
            for ($i = 0; $i < 20 && $this->pidAlive($pid); $i++) {
                usleep(100_000);
            }
            if ($this->pidAlive($pid)) {
                @posix_kill($pid, SIGKILL);
            }
            $io->writeln("Stopped foreground hub (pid {$pid}) to hand off to the service.");
        }
        @unlink($pidFile);
    }

    private function enableSystemd(
        SymfonyStyle $io,
        string $binPath,
        string $caddyConf,
        array $env,
        bool $system
    ): int {
        $unitPath = $system ? $this->systemdSystemUnitPath() : $this->systemdUserUnitPath();
        @mkdir(dirname($unitPath), 0755, true);

        $envLines = '';
        foreach ($env as $k => $v) {
            $envLines .= 'Environment="' . $k . '=' . $v . '"' . "\n";
        }

        // For a root-installed system unit, run mercure as the data dir's
        // owner rather than root when that owner is a normal user.
        $userLines = '';
        $wantedBy = 'default.target';
        if ($system) {
            $wantedBy = 'multi-user.target';
            $owner = $this->dataDirOwner();
            if ($owner !== null && $owner['name'] !== 'root') {
                $userLines = 'User=' . $owner['name'] . "\n";
                if ($owner['group'] !== '') {
                    $userLines .= 'Group=' . $owner['group'] . "\n";
                }
            }
        }

        $unit = "[Unit]\n"
            . "Description=Mercure hub for Grav (sync-mercure)\n"
            . "After=network.target\n\n"
            . "[Service]\n"
            . "Type=simple\n"
            . $envLines
            . $userLines
            . 'WorkingDirectory=' . $this->getDataDir() . "\n"
            . 'ExecStart=' . $binPath . ' run --config ' . $caddyConf . "\n"
            . "Restart=on-failure\n"
            . "RestartSec=2\n\n"
            . "[Install]\n"
            . 'WantedBy=' . $wantedBy . "\n";

        file_put_contents($unitPath, $unit);
        @chmod($unitPath, 0600); // contains JWT secrets
        $io->writeln('Wrote systemd unit to <info>' . $unitPath . '</info>');

        $svc = self::SERVICE_NAME . '.service';
        $ctl = $system ? 'systemctl' : 'systemctl --user';

        $this->runShell($ctl . ' daemon-reload');
        $rc = $this->runShell($ctl . ' enable --now ' . escapeshellarg($svc));
        if ($rc !== 0) {
            $io->error('systemctl enable failed. Inspect with: ' . $ctl . ' status ' . $svc);
            return 1;
        }

        if (!$system) {
            // Without linger the user manager only runs while the user is
            // logged in — so the hub would not start at boot on a headless
            // server. Try to enable it; fall back to a sudo hint.
            $user = $this->currentUser();
            $lingerRc = $this->runShell('loginctl enable-linger ' . escapeshellarg($user) . ' 2>/dev/null');
            if ($lingerRc !== 0) {
                $io->note(
                    "To start the hub at boot without an active login, enable linger:\n"
                    . '  sudo loginctl enable-linger ' . $user
                );
            }
        }

        $io->success('Mercure autostart enabled via systemd (' . ($system ? 'system' : 'user') . ").");
        $io->writeln('Check:  ' . $ctl . ' status ' . $svc);
        $io->writeln('Logs:   journalctl ' . ($system ? '' : '--user ') . '-u ' . $svc . ' -f');
        $io->writeln('Remove: bin/plugin sync-mercure disable');
        return 0;
    }

    private function enableLaunchd(
        SymfonyStyle $io,
        string $binPath,
        string $caddyConf,
        array $env
    ): int {
        $plistPath = $this->launchdPlistPath();
        @mkdir(dirname($plistPath), 0755, true);

        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $envXml = '';
        foreach ($env as $k => $v) {
            $envXml .= '    <key>' . $esc($k) . '</key><string>' . $esc((string)$v) . "</string>\n";
        }

        $plist = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" '
            . '"http://www.apple.com/DTDs/PropertyList-1.0.dtd">' . "\n"
            . '<plist version="1.0">' . "\n"
            . "<dict>\n"
            . '  <key>Label</key><string>' . self::LAUNCHD_LABEL . "</string>\n"
            . "  <key>ProgramArguments</key>\n"
            . "  <array>\n"
            . '    <string>' . $esc($binPath) . "</string>\n"
            . "    <string>run</string>\n"
            . "    <string>--config</string>\n"
            . '    <string>' . $esc($caddyConf) . "</string>\n"
            . "  </array>\n"
            . "  <key>EnvironmentVariables</key>\n"
            . "  <dict>\n"
            . $envXml
            . "  </dict>\n"
            . '  <key>WorkingDirectory</key><string>' . $esc($this->getDataDir()) . "</string>\n"
            . "  <key>RunAtLoad</key><true/>\n"
            . "  <key>KeepAlive</key><true/>\n"
            . '  <key>StandardOutPath</key><string>' . $esc($this->logFile()) . "</string>\n"
            . '  <key>StandardErrorPath</key><string>' . $esc($this->logFile()) . "</string>\n"
            . "</dict>\n"
            . "</plist>\n";

        file_put_contents($plistPath, $plist);
        @chmod($plistPath, 0600); // contains JWT secrets
        $io->writeln('Wrote launchd agent to <info>' . $plistPath . '</info>');

        $uid = function_exists('posix_getuid') ? posix_getuid() : (int)getmyuid();
        $label = self::LAUNCHD_LABEL;

        // Boot out any prior copy first so re-running enable is idempotent.
        $this->runShell('launchctl bootout gui/' . $uid . '/' . $label . ' 2>/dev/null');
        $rc = $this->runShell('launchctl bootstrap gui/' . $uid . ' ' . escapeshellarg($plistPath));
        if ($rc !== 0) {
            // Older macOS without bootstrap — fall back to the legacy verb.
            $rc = $this->runShell('launchctl load -w ' . escapeshellarg($plistPath));
        }
        if ($rc !== 0) {
            $io->error('launchctl could not load the agent. Inspect: launchctl print gui/' . $uid . '/' . $label);
            return 1;
        }

        $io->success('Mercure autostart enabled via launchd.');
        $io->writeln('Check:  launchctl print gui/' . $uid . '/' . $label);
        $io->writeln('Remove: bin/plugin sync-mercure disable');
        return 0;
    }

    /** Owner of the data dir as ['name' => ..., 'group' => ...], or null. */
    private function dataDirOwner(): ?array
    {
        $dir = $this->getDataDir();
        if (!function_exists('posix_getpwuid') || !is_dir($dir)) {
            return null;
        }
        $uid = fileowner($dir);
        $gid = filegroup($dir);
        $pw = $uid !== false ? posix_getpwuid($uid) : false;
        if (!$pw) {
            return null;
        }
        $gr = ($gid !== false && function_exists('posix_getgrgid')) ? posix_getgrgid($gid) : false;
        return ['name' => $pw['name'], 'group' => $gr['name'] ?? ''];
    }

    private function currentUser(): string
    {
        if (function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
            $pw = posix_getpwuid(posix_getuid());
            if ($pw && !empty($pw['name'])) {
                return $pw['name'];
            }
        }
        return (string)(getenv('USER') ?: get_current_user());
    }
}
