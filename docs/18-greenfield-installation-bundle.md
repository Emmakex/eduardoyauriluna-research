# Greenfield installation bundle

## Purpose

The Research site is a Greenfield WordPress deployment. It does not require discovery, interpretation or migration of a legacy website before the canonical Research state can be applied.

The Greenfield installation bundle turns the repository implementation into one auditable deployment artifact. The bundle is intentionally independent from a repository checkout: it contains everything required after WordPress core itself is installed.

## Bundle contents

A build produces a versioned archive named from the Theme and Manager component versions, for example:

`eduardo-research-greenfield-t1.0.0-m0.9.0.zip`

The archive contains:

- `packages/eduardo-research-<version>.zip`
- `packages/eduardo-research-manager-<version>.zip`
- `config/research-site-blueprint.json`
- `manifest.json`
- `SHA256SUMS`
- `install.sh`
- `README.txt`

`manifest.json` records the component versions, file hashes, blueprint identity and deployment requirements. `SHA256SUMS` is the operational integrity boundary used by the installer before any WordPress mutation.

## Build

From the repository root:

```bash
bash scripts/build-greenfield-bundle.sh
```

Artifacts are written to `dist/greenfield/` together with a SHA-256 file for the outer bundle.

The build normalizes timestamps and file ordering for the packaged Theme, Manager and outer archive so repeated builds from the same source state are deterministic.

## Install

Prerequisites:

- WordPress 6.6 or newer already installed
- PHP 8.1 or newer
- WP-CLI
- one WordPress administrator account

Example:

```bash
unzip eduardo-research-greenfield-t1.0.0-m0.9.0.zip
cd eduardo-research-greenfield-t1.0.0-m0.9.0
GREENFIELD_WP_PATH=/var/www/html \
GREENFIELD_ADMIN_USER=admin \
bash install.sh
```

The installer performs this controlled lifecycle:

1. verify every bundled file against `SHA256SUMS`;
2. verify that the target WordPress core is installed;
3. install and activate the Research Theme;
4. install and activate Research Manager;
5. establish the permalink structure;
6. run canonical Greenfield Preview;
7. run canonical Greenfield Apply with administrator capability;
8. run Greenfield Status and diagnostics.

The Apply path is the same `Preview -> Apply -> Verify -> Rollback` control plane used by the Research Manager. The bundle does not introduce a second mutation implementation.

## Canonical blueprint

The root `config/research-site-blueprint.json` and the copy bundled inside Research Manager are already required by CI to remain byte-for-byte identical. The standalone bundle includes the root copy for inspection and hashes it independently, while runtime Apply uses the Manager's bundled canonical blueprint.

## CI acceptance

`Research Greenfield Bundle Quality` is the release gate for the bundle. It must:

- build the standalone archive;
- validate the outer ZIP and checksum;
- extract it outside repository source paths;
- validate all internal checksums and manifest fields;
- install a fresh WordPress core with no Theme or Manager copied from the checkout;
- execute `install.sh` from the extracted bundle;
- verify Theme and Manager versions and activation;
- verify eight canonical Research Lines and Manager readiness;
- verify the blueprint summary;
- prove a repeated canonical Apply reports `mutated: false`;
- upload the verified bundle as a workflow artifact.

This distinguishes three states clearly:

- **implemented in repository** — source code exists;
- **bundle verified** — the standalone artifact installs correctly in CI;
- **deployed on eduardoyauriluna.com** — the verified artifact has been installed and checked on the real WordPress environment.
