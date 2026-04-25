<?php


declare(strict_types=1);

namespace Grav\Plugin\Console;

use Symfony\Component\Console\Style\SymfonyStyle;

require_once __DIR__ . '/MercureCommandBase.php';

class InstallCommand extends MercureCommandBase
{
    protected function configure(): void
    {
        $this
            ->setName('install')
            ->setDescription('Download the Mercure hub binary and write a default config');
    }

    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';
        $io = new SymfonyStyle($this->input, $this->output);

        $base = $this->ensureDataDir();

        // Auto-generate user/config/plugins/sync-mercure.yaml on first
        // install (or fill in any still-empty keys on a subsequent run)
        // so `start` can run with zero manual editing.
        $cfg = $this->ensureConfig();
        if ($cfg['changes'] !== []) {
            $io->writeln('Wrote config to <info>' . $cfg['path'] . '</info>:');
            foreach ($cfg['changes'] as $c) $io->writeln("  • {$c}");
        } else {
            $io->writeln('Config already in place at ' . $cfg['path']);
        }

        $binPath = $this->binPath();
        $skipDownload = is_file($binPath);
        if ($skipDownload) {
            $io->note("Mercure binary already installed at {$binPath}");
        }

        if (!$skipDownload) {
            $os = strtolower(PHP_OS_FAMILY);
            $arch = (string)php_uname('m');
            $platform = match ($os) {
                'darwin' => 'Darwin',
                'linux'  => 'Linux',
                default  => null,
            };
            if ($platform === null) {
                $io->error("Unsupported OS '{$os}'. Install Mercure manually and configure hub URLs.");
                return 1;
            }
            $archMapped = in_array($arch, ['arm64', 'aarch64'], true) ? 'arm64' : 'x86_64';
            // /latest/download/ always redirects to the current release.
            // Asset naming as of Mercure 0.23: mercure_<Platform>_<arch>.tar.gz
            $tarball = "mercure_{$platform}_{$archMapped}.tar.gz";
            $url = "https://github.com/dunglas/mercure/releases/latest/download/{$tarball}";

            $io->writeln("Fetching {$url}");
            $bytes = $this->httpGet($url);
            if ($bytes === false) {
                $io->error('Download failed.');
                return 1;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'mercure-');
            file_put_contents($tmp, $bytes);

            $extractDir = $base . '/_extract';
            @mkdir($extractDir, 0755, true);
            $rc = 0;
            passthru('tar -xzf ' . escapeshellarg($tmp) . ' -C ' . escapeshellarg($extractDir), $rc);
            @unlink($tmp);
            if ($rc !== 0 || !is_file($extractDir . '/mercure')) {
                $io->error('Extraction failed or mercure binary missing in archive.');
                $this->rrmdir($extractDir);
                return 1;
            }
            rename($extractDir . '/mercure', $binPath);
            chmod($binPath, 0755);
            $this->rrmdir($extractDir);

            $io->success("Installed Mercure at {$binPath}");
        }

        // Warm up the TLS cert during install so 'start' is instant and
        // any tooling problems surface here rather than at start time.
        try {
            $certInfo = $this->ensureCert();
            $io->writeln('TLS cert via <info>' . $certInfo['tool'] . '</info> at ' . $certInfo['cert']);
            if ($certInfo['tool'] === 'openssl') {
                $io->note(
                    'Self-signed cert in use. After starting, visit '
                    . 'https://localhost:3001/healthz in each browser once to accept it. '
                    . 'For a no-warning experience install mkcert and re-run install.'
                );
            }
        } catch (\Throwable $e) {
            $io->warning('Could not pre-generate TLS cert: ' . $e->getMessage()
                . ' (start will retry — install mkcert or openssl)');
        }

        $io->writeln('Next: <info>bin/plugin sync-mercure start</info>');
        return 0;
    }
}
