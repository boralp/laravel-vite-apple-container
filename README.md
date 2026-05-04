# Laravel Vite Apple Container

Build Laravel Vite frontend assets inside an isolated Apple Container.

This package is designed for macOS users who use [`github.com/apple/container`](https://github.com/apple/container). It runs `npm ci` and `npm run build` in a hardened container with restricted filesystem access, disabled network access by default, and npm lifecycle scripts disabled by default.

The primary interface is a Laravel Artisan command:

```bash
php artisan lvac:build
```

A Composer binary wrapper is also provided:

```bash
./vendor/bin/lvac
```

The wrapper forwards arguments to the Artisan command.

## Installation

Install via Composer:

```bash
composer require boralp/laravel-vite-apple-container --dev
```

If you need this package during production deployment builds, install it without `--dev`:

```bash
composer require boralp/laravel-vite-apple-container
```

Laravel package discovery will register the Artisan command automatically.

## Quick Start

From your Laravel project root, run the first build with network access so `npm ci` can download dependencies:

```bash
php artisan lvac:build --allow-network
```

Or use the Composer binary wrapper:

```bash
./vendor/bin/lvac --allow-network
```

Your compiled CSS and JavaScript assets will be written to:

```text
public/build/
```

By default, the project source is mounted read-only. Only `public/build` is writable on the host.

After dependencies have been installed once, subsequent builds can usually run without network access:

```bash
php artisan lvac:build --build-only
```

## Requirements

- macOS with Apple Container installed
- Laravel 12.x or Laravel 13.x
- Laravel project with:
  - `artisan`
  - `package.json`
  - `package-lock.json`
- Apple Container system started:

```bash
container system start
```

## Configuration

The package ships with a Laravel config file.

Publish it with:

```bash
php artisan vendor:publish --tag=lvac-config
```

This creates:

```text
config/lvac.php
```

Available environment variables:

```env
CONTAINER_CLI=container
LVAC_NODE_IMAGE=docker.io/library/node:24-alpine@sha256:d1b3b4da11eefd5941e7f0b9cf17783fc99d9c6fc34884a665f40a06dbdfc94f
LVAC_MEMORY=2g
LVAC_CPUS=2
```

Default config:

```php
<?php

declare(strict_types=1);

return [
    'container_cli' => env('CONTAINER_CLI', 'container'),

    'node_image' => env(
        'LVAC_NODE_IMAGE',
        'docker.io/library/node:24-alpine@sha256:d1b3b4da11eefd5941e7f0b9cf17783fc99d9c6fc34884a665f40a06dbdfc94f'
    ),

    'memory' => env('LVAC_MEMORY', '2g'),
    'cpus' => env('LVAC_CPUS', '2'),
];
```

## How It Works

The command runs the Laravel frontend build inside Apple Container using a pinned Node Alpine OCI image.

Default container behavior:

| Feature | Default | Purpose |
| --- | ---: | --- |
| Project filesystem | Read-only | Prevents build scripts from modifying source files |
| `public/build` | Writable | Allows Vite output |
| `node_modules` | Isolated container volume | Avoids writing dependencies into the host project |
| Network | Disabled | Blocks exfiltration and unexpected remote fetches |
| npm lifecycle scripts | Disabled | Blocks install-time hooks such as `postinstall` |
| Container user | Root in isolated-volume mode | Allows the container to manage the private `node_modules` volume |
| Root filesystem | Read-only | Limits writable paths inside the container |
| Temporary storage | tmpfs | Provides controlled writable scratch space |
| CPU / memory | Limited | Reduces accidental resource exhaustion |

The project source remains read-only in default mode even when the container runs as root. The only host path mounted writable is `public/build`.

In default mode, the project is copied from a read-only mount into a temporary working directory inside the container. The package keeps `node_modules` in a named Apple Container volume and restores it into the temporary working directory when available.

## Commands

Primary command:

```bash
php artisan lvac:build
```

Composer binary wrapper:

```bash
./vendor/bin/lvac
```

The wrapper is equivalent to:

```bash
php artisan lvac:build
```

All flags are supported by both forms.

Running the command without flags does not start a build. Because network access is disabled by default, a no-flag run would otherwise attempt npm ci without registry access. Instead, the command exits with guidance.

## Regular Usage

First run, usually with npm registry access:

```bash
php artisan lvac:build --allow-network
```

Subsequent builds, using the existing isolated `node_modules` volume:

```bash
php artisan lvac:build --build-only
```

Install dependencies only:

```bash
php artisan lvac:build --ci-only --allow-network
```

Build assets only:

```bash
php artisan lvac:build --build-only
```

Allow lifecycle scripts during install:

```bash
php artisan lvac:build --allow-network --allow-scripts
```

Using the binary wrapper:

```bash
./vendor/bin/lvac --allow-network
./vendor/bin/lvac --build-only
./vendor/bin/lvac --ci-only --allow-network
```

## Flags

| Flag | Description |
| --- | --- |
| `--allow-network` | Allows network access inside the container |
| `--allow-scripts` | Allows npm lifecycle scripts during `npm ci` |
| `--full-access` | Mounts the whole Laravel project writable at `/app` |
| `--host-node-modules` | Uses host `node_modules`; requires `--full-access` |
| `--build-only` | Runs only `npm run build` |
| `--ci-only` | Runs only `npm ci` |
| `--root` | Runs as root inside the container |
| `--paranoid` | Drops Linux capabilities where supported |
| `--high-security` | Alias for `--paranoid` |
| `--help`, `-h` | Shows help |

Show command help:

```bash
php artisan lvac:build --help
```

or:

```bash
./vendor/bin/lvac --help
```

## Security Model

### Default Protection

By default, the command protects against common npm supply-chain and build-time risks:

- Blocks network access unless `--allow-network` is passed
- Disables npm lifecycle scripts unless `--allow-scripts` is passed
- Prevents writes to application source files
- Allows writes only to `public/build`
- Keeps `node_modules` in an isolated container volume
- Uses a read-only container root filesystem
- Uses tmpfs for temporary writable locations
- Avoids host shell interpolation
- Applies CPU and memory limits from config

In default isolated-volume mode, the process may run as root inside the container so it can manage the private named volume used for `node_modules`. This does not make the project source writable; the project is still copied from a read-only mount into a temporary working directory.

### Important Limitations

This tool reduces risk; it does not make arbitrary JavaScript safe.

`npm run build` still executes code from your `package.json`. A malicious build script can still affect writable locations such as `public/build`, consume resources within configured limits, or attempt attacks against the container runtime.

Use extra caution with:

```bash
php artisan lvac:build --allow-network --allow-scripts
```

That combination allows dependency install scripts to run with network access and should only be used for trusted projects.

### Highest-Risk Mode

```bash
php artisan lvac:build --full-access --allow-network --allow-scripts
```

This mode gives the build process writable access to the whole project and should only be used for trusted codebases.

## Apple Container

This project targets Apple Container, not Docker or Podman.

Check installation:

```bash
container --version
```

Start the container system:

```bash
container system start
```

Pull the Node image manually if desired:

```bash
container image pull docker.io/library/node:24-alpine
```

## Updating the Pinned Node Image

The command uses a pinned image digest for reproducibility.

To update it intentionally:

```bash
container image pull docker.io/library/node:24-alpine
container image inspect docker.io/library/node:24-alpine
```

Review the new digest, then update either:

```env
LVAC_NODE_IMAGE=docker.io/library/node:24-alpine@sha256:new_digest_here
```

or the published config value in:

```text
config/lvac.php
```

If you have not published the config file, update the package default in `config/lvac.php`.

## Troubleshooting

### `Apple container CLI not found or not executable`

Install Apple Container and make sure the `container` command is available:

```bash
container --version
```

If the binary is in a custom location:

```bash
CONTAINER_CLI=/path/to/container php artisan lvac:build
```

or set it in `.env`:

```env
CONTAINER_CLI=/path/to/container
```

### Apple Container is installed but not running

Start the container system:

```bash
container system start
```

Then retry:

```bash
php artisan lvac:build
```

### `npm ci` fails without network

This is expected on first install. Network is disabled by default.

Run:

```bash
php artisan lvac:build --allow-network --ci-only
```

Then build without network:

```bash
php artisan lvac:build --build-only
```

### `vite: not found`

This usually means dependencies have not been installed into the isolated `node_modules` volume yet.

Run:

```bash
php artisan lvac:build --allow-network
```

After that, build-only mode should work:

```bash
php artisan lvac:build --build-only
```

### A package requires postinstall scripts

Some packages need lifecycle scripts.

Use:

```bash
php artisan lvac:build --allow-network --allow-scripts --ci-only
```

Then build:

```bash
php artisan lvac:build --build-only
```

Only use `--allow-scripts` after reviewing the dependency tree.

### `public/build is empty`

Check that Laravel Vite is configured correctly.

Typical `vite.config.js`:

```javascript
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
            ],
            refresh: true,
        }),
    ],
});
```

### Permission problems writing `public/build`

Ensure the directory is writable by your user:

```bash
mkdir -p public/build
chmod -R u+rw public/build
```

Then retry:

```bash
php artisan lvac:build --build-only
```

### Build needs to write outside `public/build`

Default mode intentionally blocks this.

For trusted projects only:

```bash
php artisan lvac:build --full-access
```

### Host `node_modules` is required

By default, dependencies are installed into an isolated Apple Container volume, not the host project.

For trusted projects only, you can use host `node_modules`:

```bash
php artisan lvac:build --full-access --host-node-modules --allow-network
```

`--host-node-modules` requires `--full-access`.

## How It Differs from Local npm

| Aspect | Local npm | This tool |
| --- | ---: | ---: |
| Network access | Allowed | Blocked by default |
| npm lifecycle scripts | Allowed | Blocked by default |
| Project source writes | Allowed | Blocked by default |
| `public/build` writes | Allowed | Allowed |
| `node_modules` location | Host project | Isolated container volume |
| Root filesystem | Host filesystem | Read-only container filesystem |
| Runtime consistency | Depends on host | OCI Node image |
| Resource limits | Depends on host | Configured CPU and memory limits |

## CI/CD

This tool is intended primarily for Apple Container on macOS. For Linux CI environments, use the project’s normal Node build pipeline unless your CI runner also supports Apple Container.

Example conventional CI build:

```yaml
name: Build Assets

on:
  push:
  pull_request:

jobs:
  build:
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v4

      - uses: actions/setup-node@v4
        with:
          node-version: 24
          cache: npm

      - run: npm ci
      - run: npm run build
``` 

## Threat Model Summary

Good fit:

- Trusted Laravel projects
- Reducing npm supply-chain exposure
- Preventing accidental project source writes
- Reproducible local frontend builds on macOS

Not a complete sandbox for:

- Fully untrusted repositories
- Malicious `package.json` build scripts
- Arbitrary npm command execution
- Secrets protection if secrets are already present in readable project files

## Security

If you find a security issue, do not open a public issue. Contact the maintainer privately.
