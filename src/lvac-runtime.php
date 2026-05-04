#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Laravel Vite Build in Apple Container
 *
 * Enterprise-grade local asset build wrapper for Laravel + Vite.
 *
 * Target runtime:
 *   - github.com/apple/container
 *   - CLI binary: container
 *
 * Default security posture:
 *   - No shell execution
 *   - Apple `container` CLI only
 *   - Project mounted read-only
 *   - Only public/build writable on the host
 *   - node_modules stored in an isolated named container volume
 *   - Container root filesystem read-only
 *   - Temporary writable locations are tmpfs
 *   - Network disabled by default
 *   - npm lifecycle scripts disabled by default
 *   - Non-root execution by default
 *   - CPU and memory limits enabled
 *
 * Usage:
 *   php bin/build.php
 *
 * Common flags:
 *   --allow-network       Allow network access inside the container
 *   --allow-scripts       Allow npm lifecycle scripts during npm ci
 *   --full-access         Mount the whole Laravel project writable
 *   --host-node-modules   Use host node_modules instead of isolated volume
 *   --build-only          Run only npm run build
 *   --ci-only             Run only npm ci
 *   --root                Run as root inside the container
 *   --paranoid            Add extra hardening where Apple container supports it
 *
 * Notes:
 *   - Default mode is intentionally restrictive.
 *   - First-time `npm ci` usually needs --allow-network unless dependencies are
 *     already cached in the image or available from the container runtime cache.
 *   - `npm run build` still executes arbitrary project code. This wrapper limits
 *     filesystem and network exposure; it does not make untrusted JavaScript safe.
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

/**
 * Apple container is the intended runtime.
 * Keep this configurable only for wrapper paths, not for Docker/Podman support.
 */
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
 *
 * Replace the digest only after reviewing the new image.
 */
$nodeImage = 'docker.io/library/node:24-alpine@sha256:d1b3b4da11eefd5941e7f0b9cf17783fc99d9c6fc34884a665f40a06dbdfc94f';

$buildDir = $root.'/public/build';

if (! is_dir($buildDir) && ! mkdir($buildDir, 0755, true)) {
    fail('Cannot create public/build directory.', EXIT_SOFTWARE);
}

if (! is_writable($buildDir)) {
    fail('public/build exists but is not writable by the current user.', EXIT_USAGE);
}

/**
 * Apple `container --mount` uses comma-separated key=value syntax.
 * A comma in a host path would be ambiguous.
 */
if (! $fullAccess && str_contains($root, ',')) {
    fail('Project path contains a comma, which is unsafe for container --mount syntax.', EXIT_USAGE);
}

$projectHash = substr(sha1($root), 0, 20);
$nodeModulesVolume = 'laravel_node_modules_'.$projectHash;

$userSpec = getContainerUserSpec($runAsRoot);

$base = [
    $containerCmd,
    'run',
    '--rm',
    '--init',
    '--read-only',
    '--tmpfs', '/tmp',
    '--tmpfs', '/home/node/.npm',
    '--workdir', '/app',
    '--memory', '2g',
    '--cpus', '2',
    '--env', 'HOME=/tmp',
    '--env', 'NPM_CONFIG_CACHE=/tmp/npm-cache',
    '--env', 'npm_config_cache=/tmp/npm-cache',
    '--env', 'CI=true',
];

/**
 * Apple container supports `--network none` in current releases.
 * Defaulting to no network is intentional: install/build code should not be
 * able to exfiltrate data unless the caller opts in.
 */
if (! $allowNetwork) {
    $base[] = '--network';
    $base[] = 'none';
}

if ($userSpec !== null) {
    $base[] = '--user';
    $base[] = $userSpec;
}

/**
 * Apple container supports Linux capability control.
 * Avoid Docker-only flags here.
 */
if ($paranoid) {
    $base[] = '--cap-drop';
    $base[] = 'ALL';
}

/**
 * Filesystem model:
 *
 * Default:
 *   /app                    read-only project source
 *   /app/public/build        writable host build output
 *   /app/node_modules        isolated named container volume
 *
 * Full access:
 *   /app                    writable project source
 *
 * The default prevents npm/build scripts from modifying application source,
 * PHP code, config files, package files, or other project files.
 */
if ($fullAccess) {
    $base[] = '--volume';
    $base[] = $root.':/app';

    if (! $hostNodeModules) {
        $base[] = '--volume';
        $base[] = $nodeModulesVolume.':/app/node_modules';
    }
} else {
    $base[] = '--mount';
    $base[] = 'type=bind,source='.$root.',target=/app,readonly';

    $base[] = '--mount';
    $base[] = 'type=bind,source='.$buildDir.',target=/app/public/build';

    $base[] = '--volume';
    $base[] = $nodeModulesVolume.':/app/node_modules';
}

/**
 * Run npm ci.
 */
if (! $buildOnly) {
    $npmCi = ['npm', 'ci'];

    if (! $allowScripts) {
        $npmCi[] = '--ignore-scripts';
    }

    logLine('Installing dependencies...');
    run(buildContainerCommand($base, $nodeImage, $npmCi));
    logLine('Dependencies installed.');
    logLine('');
}

/**
 * Run npm build.
 */
if (! $ciOnly) {
    logLine('Building Laravel Vite assets...');
    run(buildContainerCommand($base, $nodeImage, ['npm', 'run', 'build']));

    $files = glob($buildDir.'/*');

    if (! is_array($files) || count($files) === 0) {
        fail('Build finished, but public/build is empty.', EXIT_SOFTWARE);
    }

    logLine('');
    logLine('Build complete: public/build');
}

exit(0);

/**
 * Compose final Apple container command.
 *
 * `container run` format:
 *   container run [options] <image> [arguments...]
 */
function buildContainerCommand(array $base, string $image, array $processArgs): array
{
    return array_merge($base, [$image], $processArgs);
}

/**
 * Execute command without shell interpolation.
 */
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

/**
 * Detect Apple container without shell execution.
 *
 * Accepts:
 *   container
 *   /usr/local/bin/container
 *   /opt/homebrew/bin/container
 */
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

/**
 * Run `container --version` directly.
 */
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

/**
 * Prefer host UID:GID on Unix so host-mounted public/build remains writable.
 * Fall back to the image's `node` user if POSIX functions are unavailable.
 */
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

Examples:
  php bin/build.php --allow-network
  php bin/build.php --allow-network --allow-scripts
  php bin/build.php --build-only
  php bin/build.php --full-access --allow-network

TXT;

    fwrite(STDOUT, $help);
}
