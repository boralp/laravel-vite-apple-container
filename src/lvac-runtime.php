#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Laravel Vite Build in Apple Container
 *
 * Target runtime:
 *   - github.com/apple/container
 *   - CLI binary: container
 *
 * Main fix:
 *   - Do not mount the named volume directly at /app/node_modules.
 *   - Mount it at /mnt/node_modules_volume instead.
 *   - Use /mnt/node_modules_volume/node_modules as the real node_modules path.
 *   - Symlink /work/app/node_modules or /app/node_modules to that child path.
 *
 * Why:
 *   Apple/container-created Linux volumes may contain a root-owned lost+found
 *   directory at the volume root. npm ci tries to clean node_modules and fails
 *   when node_modules is the volume root.
 *
 * Security posture:
 *   - Project mounted read-only by default
 *   - public/build writable on the host
 *   - node_modules isolated in a named container volume
 *   - Network disabled by default
 *   - npm lifecycle scripts disabled by default
 *   - Container root filesystem read-only
 *   - Temporary writable locations are tmpfs
 *
 * Note:
 *   In default isolated-volume mode, the container runs as root so it can create
 *   and manage the named volume contents. The project source remains read-only
 *   and only public/build is writable on the host.
 */
const EXIT_USAGE = 64;
const EXIT_SOFTWARE = 70;

$argv = $_SERVER['argv'] ?? [];
$root = realpath(getcwd());

if (! $root || ! is_dir($root)) {
    fail('Cannot resolve current working directory.', EXIT_USAGE);
}

if (! is_file($root.'/artisan')) {
    fail('Run this command from the Laravel project root. Missing: artisan', EXIT_USAGE);
}

if (! is_file($root.'/package.json')) {
    fail('package.json not found.', EXIT_USAGE);
}

if (! is_file($root.'/package-lock.json')) {
    fail('package-lock.json not found. Commit package-lock.json for reproducible builds.', EXIT_USAGE);
}

$allowNetwork = hasFlag('--allow-network', $argv);
$allowScripts = hasFlag('--allow-scripts', $argv);
$fullAccess = hasFlag('--full-access', $argv);
$hostNodeModules = hasFlag('--host-node-modules', $argv);
$buildOnly = hasFlag('--build-only', $argv);
$ciOnly = hasFlag('--ci-only', $argv);
$runAsRoot = hasFlag('--root', $argv);
$paranoid = hasFlag('--paranoid', $argv) || hasFlag('--high-security', $argv);
$help = hasFlag('--help', $argv) || hasFlag('-h', $argv);

if ($help) {
    printHelp();
    exit(0);
}

if ($ciOnly && $buildOnly) {
    fail('--ci-only and --build-only cannot be used together.', EXIT_USAGE);
}

if ($hostNodeModules && ! $fullAccess) {
    fail('--host-node-modules requires --full-access because /app is read-only in default mode.', EXIT_USAGE);
}

$containerCmd = getenv('CONTAINER_CLI') ?: 'container';

if (! detectAppleContainer($containerCmd)) {
    fail(
        "Apple container CLI not found or not executable: {$containerCmd}\n".
        'Install/start it, then run: container system start',
        EXIT_SOFTWARE
    );
}

/**
 * Pinned OCI image.
 *
 * Update intentionally:
 *   container image pull docker.io/library/node:24-alpine
 *   container image inspect docker.io/library/node:24-alpine
 */
$nodeImage = 'docker.io/library/node:24-alpine@sha256:d1b3b4da11eefd5941e7f0b9cf17783fc99d9c6fc34884a665f40a06dbdfc94f';

$buildDir = $root.'/public/build';

if (! is_dir($buildDir) && ! mkdir($buildDir, 0755, true)) {
    fail('Cannot create public/build directory.', EXIT_SOFTWARE);
}

if (! is_writable($buildDir)) {
    fail('public/build exists but is not writable by the current user.', EXIT_USAGE);
}

if (! $fullAccess && str_contains($root, ',')) {
    fail('Project path contains a comma, which is unsafe for container --mount syntax.', EXIT_USAGE);
}

$projectHash = substr(sha1($root), 0, 20);
$nodeModulesVolume = 'laravel_node_modules_'.$projectHash;

/**
 * In default mode, node_modules is isolated in a named container volume.
 * Run as root in that case so the script can create/manage the volume child
 * directory even when the volume root is owned by root.
 *
 * In full-access or host-node-modules mode, preserve the host UID/GID unless
 * --root is explicitly passed.
 */
$userSpec = (! $fullAccess && ! $hostNodeModules)
    ? null
    : getContainerUserSpec($runAsRoot);

$workdir = $fullAccess ? '/app' : '/work/app';

$base = [
    $containerCmd,
    'run',
    '--rm',
    '--init',
    '--read-only',
    '--tmpfs', '/tmp',
    '--tmpfs', '/home/node/.npm',
    '--workdir', $workdir,
    '--memory', '2g',
    '--cpus', '2',
    '--env', 'HOME=/tmp',
    '--env', 'NPM_CONFIG_CACHE=/tmp/npm-cache',
    '--env', 'npm_config_cache=/tmp/npm-cache',
    '--env', 'CI=true',
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

/**
 * Filesystem model:
 *
 * Default mode:
 *   /src                       read-only project source
 *   /work                      tmpfs writable working area
 *   /work/app                  writable copy of project
 *   /mnt/public_build          writable host public/build
 *   /mnt/node_modules_volume   isolated named volume
 *   /work/app/node_modules     symlink to volume child directory
 *
 * Full-access mode:
 *   /app                       writable project source
 *   /mnt/node_modules_volume   isolated named volume unless host node_modules
 */
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

$insideScript = buildInsideScript(
    fullAccess: $fullAccess,
    hostNodeModules: $hostNodeModules,
    allowScripts: $allowScripts,
    buildOnly: $buildOnly,
    ciOnly: $ciOnly
);

run(buildContainerCommand($base, $nodeImage, ['sh', '-eu', '-c', $insideScript]));

$files = glob($buildDir.'/*');

if (! $ciOnly && (! is_array($files) || count($files) === 0)) {
    fail('Build finished, but public/build is empty.', EXIT_SOFTWARE);
}

if (! $ciOnly) {
    logLine('');
    logLine('Build complete: public/build');
}

exit(0);

function buildContainerCommand(array $base, string $image, array $processArgs): array
{
    return array_merge($base, [$image], $processArgs);
}

function buildInsideScript(
    bool $fullAccess,
    bool $hostNodeModules,
    bool $allowScripts,
    bool $buildOnly,
    bool $ciOnly
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

function run(array $cmd): void
{
    $descriptors = [
        0 => STDIN,
        1 => STDOUT,
        2 => STDERR,
    ];

    $proc = proc_open($cmd, $descriptors, $pipes);

    if (! is_resource($proc)) {
        fail('Failed to start container process.', EXIT_SOFTWARE);
    }

    $code = proc_close($proc);

    if ($code !== 0) {
        exit($code);
    }
}

function detectAppleContainer(string $cmd): bool
{
    if ($cmd === '') {
        return false;
    }

    if (! preg_match('/^[A-Za-z0-9_\/.\-]+$/', $cmd)) {
        return false;
    }

    $candidates = [];

    if (str_contains($cmd, '/')) {
        $candidates[] = $cmd;
    } else {
        $candidates[] = '/usr/local/bin/'.$cmd;
        $candidates[] = '/opt/homebrew/bin/'.$cmd;
        $candidates[] = '/usr/bin/'.$cmd;
        $candidates[] = $cmd;
    }

    foreach (array_unique($candidates) as $candidate) {
        if (canRunContainerVersion($candidate)) {
            return true;
        }
    }

    return false;
}

function canRunContainerVersion(string $cmd): bool
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = @proc_open([$cmd, '--version'], $descriptors, $pipes);

    if (! is_resource($proc)) {
        return false;
    }

    foreach ($pipes as $pipe) {
        fclose($pipe);
    }

    return proc_close($proc) === 0;
}

function getContainerUserSpec(bool $runAsRoot): ?string
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

function hasFlag(string $flag, array $argv): bool
{
    return in_array($flag, $argv, true);
}

function logLine(string $message): void
{
    fwrite(STDOUT, $message.PHP_EOL);
}

function fail(string $message, int $code): never
{
    fwrite(STDERR, 'Error: '.$message.PHP_EOL);
    exit($code);
}

function printHelp(): void
{
    $help = <<<'TXT'
Laravel Vite Build in Apple Container

Usage:
  ./vendor/bin/lvac [flags]
  php bin/build.php [flags]

Flags:
  --allow-network       Allow network access inside the container
  --allow-scripts       Allow npm lifecycle scripts during npm ci
  --full-access         Mount the whole Laravel project writable at /app
  --host-node-modules   Use host node_modules; requires --full-access
  --build-only          Only run npm run build
  --ci-only             Only run npm ci
  --root                Run as root inside the container
  --paranoid            Drop all Linux capabilities where supported
  --help, -h            Show this help

Default behavior:
  - Project is read-only
  - public/build is writable
  - node_modules uses an isolated named container volume
  - Network is disabled
  - npm lifecycle scripts are disabled
  - Container root filesystem is read-only
  - Container runs as root only for isolated-volume compatibility

Examples:
  ./vendor/bin/lvac --allow-network
  ./vendor/bin/lvac --allow-network --allow-scripts
  ./vendor/bin/lvac --build-only
  ./vendor/bin/lvac --full-access --allow-network
  ./vendor/bin/lvac --full-access --host-node-modules --allow-network

TXT;

    fwrite(STDOUT, $help);
}
