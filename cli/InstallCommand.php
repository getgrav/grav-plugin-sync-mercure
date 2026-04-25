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
        if (is_file($binPath)) {
            $io->note("Mercure binary already installed at {$binPath}");
            $io->writeln('Next: <info>bin/plugin sync-mercure start</info>');
            return 0;
        }

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
        // The /latest/download/ redirect always points at the most recent
        // release, so we don't have to chase version numbers here. Asset
        // naming convention as of Mercure 0.23: mercure_<Platform>_<arch>.tar.gz
        $tarball = "mercure_{$platform}_{$archMapped}.tar.gz";
        $url = "https://github.com/dunglas/mercure/releases/latest/download/{$tarball}";

        $io->writeln("Fetching {$url}");
        // file_get_contents follows redirects by default with the http
        // wrapper but cURL is more reliable on macOS where HTTPS support
        // for streams can be flaky. Try cURL first.
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

        $io->success("Installed Mercure {$tag} at {$binPath}");
        $io->writeln('Next: <info>bin/plugin sync-mercure start</info>');
        return 0;
    }
}
