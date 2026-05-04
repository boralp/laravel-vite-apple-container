# Laravel Vite Apple Container

Build Laravel Vite frontend assets inside an isolated Apple Container.

This tool is designed for macOS users who use [`github.com/apple/container`](https://github.com/apple/container). It runs `npm ci` and `npm run build` in a hardened container with restricted filesystem access, disabled network access by default, and npm lifecycle scripts disabled by default.

## Installation

Install via Composer:

```bash
composer require boralp/laravel-vite-apple-container --dev

## Quick Start

From your Laravel project root:

```bash
./vendor/bin/lvac
````

Your compiled CSS and JavaScript assets will be written to:

```text
public/build/
```

By default, the project source is mounted read-only. Only `public/build` is writable on the host.

## Requirements

* macOS with Apple Container installed
* PHP 8.1+
* Laravel project with:

  * `artisan`
  * `package.json`
  * `package-lock.json`
* Apple Container system started:

```bash
container system start
```

## How It Works

The command runs the Laravel frontend build inside Apple Container using a pinned Node Alpine OCI image.

Default container behavior:

| Feature               |                   Default | Purpose                                            |
| --------------------- | ------------------------: | -------------------------------------------------- |
| Project filesystem    |                 Read-only | Prevents build scripts from modifying source files |
| `public/build`        |                  Writable | Allows Vite output                                 |
| `node_modules`        | Isolated container volume | Avoids writing dependencies into the host project  |
| Network               |                  Disabled | Blocks exfiltration and unexpected remote fetches  |
| npm lifecycle scripts |                  Disabled | Blocks install-time hooks such as `postinstall`    |
| Container user        |                  Non-root | Reduces privilege risk                             |
| Root filesystem       |                 Read-only | Limits writable paths inside the container         |
| Temporary storage     |                     tmpfs | Provides controlled writable scratch space         |
| CPU / memory          |                   Limited | Reduces accidental resource exhaustion             |

## Regular Usage

First run, usually with npm registry access:

```bash
./vendor/bin/lvac --allow-network
```

Subsequent builds, using the existing isolated `node_modules` volume:

```bash
./vendor/bin/lvac --build-only
```

Install dependencies only:

```bash
./vendor/bin/lvac --ci-only --allow-network
```

Build assets only:

```bash
./vendor/bin/lvac --build-only
```

## Flags

| Flag                  | Description                                         |
| --------------------- | --------------------------------------------------- |
| `--allow-network`     | Allows network access inside the container          |
| `--allow-scripts`     | Allows npm lifecycle scripts during `npm ci`        |
| `--full-access`       | Mounts the whole Laravel project writable at `/app` |
| `--host-node-modules` | Uses host `node_modules`; requires `--full-access`  |
| `--build-only`        | Runs only `npm run build`                           |
| `--ci-only`           | Runs only `npm ci`                                  |
| `--root`              | Runs as root inside the container                   |
| `--paranoid`          | Drops Linux capabilities where supported            |
| `--help`, `-h`        | Shows help                                          |

## Security Model

### Default Protection

By default, the script protects against common npm supply-chain and build-time risks:

* Blocks network access unless `--allow-network` is passed
* Disables npm lifecycle scripts unless `--allow-scripts` is passed
* Prevents writes to application source files
* Allows writes only to `public/build`
* Keeps `node_modules` in an isolated container volume
* Runs without root privileges
* Uses a read-only container root filesystem
* Avoids shell interpolation entirely

### Important Limitations

This tool reduces risk; it does not make arbitrary JavaScript safe.

`npm run build` still executes code from your `package.json`. A malicious build script can still affect writable locations such as `public/build`, consume resources within configured limits, or attempt attacks against the container runtime.

Use extra caution with:

```bash
./vendor/bin/lvac --allow-network --allow-scripts
```

That combination allows dependency install scripts to run with network access and should only be used for trusted projects.

### Highest-Risk Mode

```bash
./vendor/bin/lvac --full-access --allow-network --allow-scripts
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

The script uses a pinned image digest for reproducibility.

To update it intentionally:

```bash
container image pull docker.io/library/node:24-alpine
container image inspect docker.io/library/node:24-alpine
```

Review the new digest, then update the image reference in the script.


### `Apple container CLI not found or not executable`

Install Apple Container and make sure the `container` command is available:

```bash
container --version
```

If the binary is in a custom location:

```bash
CONTAINER_CLI=/path/to/container ./vendor/bin/lvac
```

### `container system start`

If Apple Container is installed but not running:

```bash
container system start
```

Then retry:

```bash
./vendor/bin/lvac
```

### `npm ci` fails without network

This is expected on first install. Network is disabled by default.

Run:

```bash
./vendor/bin/lvac --allow-network --ci-only
```

Then build without network:

```bash
./vendor/bin/lvac --build-only
```

### A package requires postinstall scripts

Some packages need lifecycle scripts.

Use:

```bash
./vendor/bin/lvac --allow-network --allow-scripts --ci-only
```

Then build:

```bash
./vendor/bin/lvac --build-only
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

Then retry.

### Build needs to write outside `public/build`

Default mode intentionally blocks this.

For trusted projects only:

```bash
./vendor/bin/lvac --full-access
```

## How It Differs from Local npm

| Aspect                  |       Local npm |                      This tool |
| ----------------------- | --------------: | -----------------------------: |
| Network access          |         Allowed |             Blocked by default |
| npm lifecycle scripts   |         Allowed |             Blocked by default |
| Project source writes   |         Allowed |             Blocked by default |
| `public/build` writes   |         Allowed |                        Allowed |
| `node_modules` location |    Host project |      Isolated container volume |
| Root filesystem         | Host filesystem | Read-only container filesystem |
| User                    |       Host user |        Non-root container user |
| Runtime consistency     | Depends on host |                 OCI Node image |

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

* Trusted Laravel projects
* Reducing npm supply-chain exposure
* Preventing accidental project source writes
* Reproducible local frontend builds on macOS

Not a complete sandbox for:

* Fully untrusted repositories
* Malicious `package.json` build scripts
* Arbitrary npm command execution
* Secrets protection if secrets are already present in readable project files

## Security

If you find a security issue, do not open a public issue. Contact the maintainer privately.
