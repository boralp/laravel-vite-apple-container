<?php

declare(strict_types=1);

namespace Boralp\LaravelViteAppleContainer\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

final class BuildCommand extends Command
{
    protected $signature = 'lvac:build
        {--allow-network : Allow network access inside the container}
        {--allow-scripts : Allow npm lifecycle scripts during npm ci}
        {--full-access : Mount the whole Laravel project writable at /app}
        {--host-node-modules : Use host node_modules; requires --full-access}
        {--build-only : Only run npm run build}
        {--ci-only : Only run npm ci}
        {--root : Run as root inside the container}
        {--paranoid : Drop all Linux capabilities where supported}
        {--high-security : Alias for --paranoid}';

    protected $description = 'Build Laravel Vite assets inside a hardened Apple Container. Use --allow-network for dependency install or --build-only for cached builds.';

    public function handle(): int
    {
        $root = base_path();

        if (! is_dir($root)) {
            $this->error('Cannot resolve Laravel project root.');

            return self::FAILURE;
        }

        if (! is_file($root.'/package.json')) {
            $this->error('package.json not found.');

            return self::FAILURE;
        }

        if (! is_file($root.'/package-lock.json')) {
            $this->error('package-lock.json not found. Commit package-lock.json for reproducible builds.');

            return self::FAILURE;
        }

        if ($this->option('ci-only') && $this->option('build-only')) {
            $this->error('--ci-only and --build-only cannot be used together.');

            return self::FAILURE;
        }

        if ($this->option('host-node-modules') && ! $this->option('full-access')) {
            $this->error('--host-node-modules requires --full-access because /app is read-only in default mode.');

            return self::FAILURE;
        }

        $containerCmd = trim((string) config('lvac.container_cli', 'container'));
        $nodeImage = trim((string) config('lvac.node_image'));
        $memory = trim((string) config('lvac.memory', '2g'));
        $cpus = trim((string) config('lvac.cpus', '2'));

        if ($containerCmd === '') {
            $this->error('lvac.container_cli is empty.');

            return self::FAILURE;
        }

        if ($nodeImage === '') {
            $this->error('lvac.node_image is empty.');

            return self::FAILURE;
        }

        if ($memory === '') {
            $this->error('lvac.memory is empty.');

            return self::FAILURE;
        }

        if ($cpus === '') {
            $this->error('lvac.cpus is empty.');

            return self::FAILURE;
        }

        if (! $this->detectAppleContainer($containerCmd)) {
            $this->error("Apple container CLI not found or not executable: {$containerCmd}");
            $this->line('Install/start it, then run: container system start');

            return self::FAILURE;
        }

        $buildDir = $root.'/public/build';

        if (! is_dir($buildDir) && ! mkdir($buildDir, 0755, true)) {
            $this->error('Cannot create public/build directory.');

            return self::FAILURE;
        }

        if (! is_writable($buildDir)) {
            $this->error('public/build exists but is not writable by the current user.');

            return self::FAILURE;
        }

        $fullAccess = (bool) $this->option('full-access');
        $hostNodeModules = (bool) $this->option('host-node-modules');
        $allowNetwork = (bool) $this->option('allow-network');
        $allowScripts = (bool) $this->option('allow-scripts');
        $buildOnly = (bool) $this->option('build-only');
        $ciOnly = (bool) $this->option('ci-only');
        $runAsRoot = (bool) $this->option('root');
        $paranoid = (bool) $this->option('paranoid') || (bool) $this->option('high-security');

        if (! $allowNetwork && ! $buildOnly) {
            $this->warn('Network is disabled, but this command would run npm ci.');
            $this->line('');
            $this->line('Use one of these instead:');
            $this->line('  php artisan lvac:build --allow-network       First run or after package-lock.json changes');
            $this->line('  php artisan lvac:build --build-only          Build from cached node_modules');
            $this->line('  php artisan lvac:build --ci-only --allow-network  Install dependencies only');
            $this->line('');

            return self::FAILURE;
        }

        if (! $fullAccess && str_contains($root, ',')) {
            $this->error('Project path contains a comma, which is unsafe for container --mount syntax.');

            return self::FAILURE;
        }

        $projectHash = substr(sha1($root), 0, 20);
        $nodeModulesVolume = 'laravel_node_modules_'.$projectHash;

        $userSpec = (! $fullAccess && ! $hostNodeModules)
            ? null
            : $this->getContainerUserSpec($runAsRoot);

        $workdir = $fullAccess ? '/app' : '/work/app';

        $base = [
            $containerCmd,
            'run',
            '--rm',
            '--init',
            '--read-only',
            '--tmpfs',
            '/tmp',
            '--tmpfs',
            '/home/node/.npm',
            '--workdir',
            $workdir,
            '--memory',
            $memory,
            '--cpus',
            $cpus,
            '--env',
            'HOME=/tmp',
            '--env',
            'NPM_CONFIG_CACHE=/tmp/npm-cache',
            '--env',
            'npm_config_cache=/tmp/npm-cache',
            '--env',
            'CI=true',
        ];

        if (! $allowNetwork) {
            $base[] = '--network';
            $base[] = 'none';
        }

        if ($userSpec !== null) {
            $base[] = '--user';
            $base[] = $userSpec;
        }

        if ($paranoid) {
            $base[] = '--cap-drop';
            $base[] = 'ALL';
        }

        if ($fullAccess) {
            $base[] = '--volume';
            $base[] = $root.':/app';

            if (! $hostNodeModules) {
                $base[] = '--volume';
                $base[] = $nodeModulesVolume.':/mnt/node_modules_volume';
            }
        } else {
            $base[] = '--mount';
            $base[] = 'type=bind,source='.$root.',target=/src,readonly';

            $base[] = '--mount';
            $base[] = 'type=bind,source='.$buildDir.',target=/mnt/public_build';

            $base[] = '--volume';
            $base[] = $nodeModulesVolume.':/mnt/node_modules_volume';

            $base[] = '--tmpfs';
            $base[] = '/work';
        }

        $insideScript = $this->buildInsideScript(
            fullAccess: $fullAccess,
            hostNodeModules: $hostNodeModules,
            allowScripts: $allowScripts,
            buildOnly: $buildOnly,
            ciOnly: $ciOnly,
        );

        $command = array_merge($base, [$nodeImage, 'sh', '-eu', '-c', $insideScript]);

        $process = new Process($command, $root, null, null, null);

        $exitCode = $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        if ($exitCode !== 0) {
            return $exitCode;
        }

        $files = glob($buildDir.'/*');

        if (! $ciOnly && (! is_array($files) || count($files) === 0)) {
            $this->error('Build finished, but public/build is empty.');

            return self::FAILURE;
        }

        if (! $ciOnly) {
            $this->newLine();
            $this->info('Build complete: public/build');
        }

        return self::SUCCESS;
    }

    private function buildInsideScript(
        bool $fullAccess,
        bool $hostNodeModules,
        bool $allowScripts,
        bool $buildOnly,
        bool $ciOnly,
    ): string {
        $script = <<<'SH'
if [ -d /src ]; then
    mkdir -p /work/app

    tar \
        --exclude='./node_modules' \
        --exclude='./public/build' \
        -C /src \
        -cf - . | tar -C /work/app -xf -

    mkdir -p /work/app/public
    rm -rf /work/app/public/build
    ln -s /mnt/public_build /work/app/public/build

    mkdir -p /mnt/node_modules_volume/node_modules

    if [ -d /mnt/node_modules_volume/node_modules/.bin ]; then
        echo 'Restoring node_modules from container volume...'
        cp -a /mnt/node_modules_volume/node_modules /work/app/node_modules
    fi

    cd /work/app
else
    cd /app
fi

SH;

        if ($fullAccess && ! $hostNodeModules) {
            $script .= <<<'SH'
mkdir -p /mnt/node_modules_volume/node_modules

if [ -d /mnt/node_modules_volume/node_modules/.bin ]; then
    rm -rf /app/node_modules
    cp -a /mnt/node_modules_volume/node_modules /app/node_modules
fi

SH;
        }

        if (! $buildOnly) {
            $npmCi = 'npm ci';

            if (! $allowScripts) {
                $npmCi .= ' --ignore-scripts';
            }

            $script .= "echo 'Installing dependencies...'\n";
            $script .= $npmCi."\n";
            $script .= "rm -rf /mnt/node_modules_volume/node_modules\n";
            $script .= "mkdir -p /mnt/node_modules_volume\n";
            $script .= "cp -a node_modules /mnt/node_modules_volume/node_modules\n";
            $script .= "echo 'Dependencies installed.'\n";
            $script .= "echo ''\n";
        }

        if (! $ciOnly) {
            $script .= "echo 'Building Laravel Vite assets...'\n";
            $script .= "npm run build\n";
        }

        return $script;
    }

    private function detectAppleContainer(string $cmd): bool
    {
        if ($cmd === '') {
            return false;
        }

        if (! preg_match('/^[A-Za-z0-9_\/.\-]+$/', $cmd)) {
            return false;
        }

        $candidates = str_contains($cmd, '/')
            ? [$cmd]
            : [
                '/usr/local/bin/'.$cmd,
                '/opt/homebrew/bin/'.$cmd,
                '/usr/bin/'.$cmd,
                $cmd,
            ];

        foreach (array_unique($candidates) as $candidate) {
            if ($this->canRunContainerVersion($candidate)) {
                return true;
            }
        }

        return false;
    }

    private function canRunContainerVersion(string $cmd): bool
    {
        $process = new Process([$cmd, '--version']);
        $process->setTimeout(10);

        return $process->run() === 0;
    }

    private function getContainerUserSpec(bool $runAsRoot): ?string
    {
        if ($runAsRoot) {
            return null;
        }

        if (function_exists('posix_getuid') && function_exists('posix_getgid')) {
            $uid = posix_getuid();
            $gid = posix_getgid();

            if (is_int($uid) && is_int($gid) && $uid > 0 && $gid > 0) {
                return $uid.':'.$gid;
            }
        }

        return 'node';
    }
}
