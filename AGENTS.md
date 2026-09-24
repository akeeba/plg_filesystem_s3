# AGENTS.md

This file provides guidance to coding agents when working with code in this repository.

## Project Overview

Joomla 5/6 filesystem plugin (`plg_filesystem_s3`) that integrates Amazon S3 and S3-compatible storage with Joomla's Media Manager. Supports optional CloudFront CDN URL generation.

**Critical constraint**: Only works with files stored with Public ACLs. All uploads are hardcoded to `Acl::ACL_PUBLIC_READ`. The plugin strips query strings from authenticated S3 URLs since it expects public files.

## Build & Development

- **Build**: `phing git` (default), `phing package-pkg` (ZIP package in `build/release/`), `phing release` (GitHub release)
- **Dependencies**: `composer install` (vendors go to `plugins/filesystem/s3/vendor/`)
- **Composer platform target**: PHP 8.1.0
- **Unit tests**: `phpunit` (global PHPUnit 11; config `phpunit.xml`, tests in `UnitTest/`; needs `composer install`)
- **E2E tests**: `tests/integration/docker/run.sh` (Docker: Joomla + MinIO; config `phpunit-integration.xml`). Read `tests/README.md` and `tests/integration/README.md` first
- **Known bugs** found by the suites are numbered in `known-issues.md` (git-ignored); their tests are skipped, not rewritten
- Build config is imported from a sibling `../buildfiles/` repository (`common.xml`)

## Architecture

Namespace: `Akeeba\Plugin\Filesystem\S3`

All plugin source lives under `plugins/filesystem/s3/`:

### Dependency Injection Entry Point
`services/provider.php` — Joomla DI service provider. Registers the plugin, defines `AKEEBAENGINE` constant, and loads the Composer autoloader. This is the only place the vendor autoloader is required.

### Core Classes

1. **`src/Extension/S3.php`** — Main plugin class. Implements `SubscriberInterface` + `ProviderInterface`. Subscribes to `onSetupProviders` event. Parses the `connections` subform config (JSON) and creates one `S3Filesystem` adapter per connection.

2. **`src/Adapter/S3Filesystem.php`** (~1400 lines) — The workhorse. Implements Joomla's `AdapterInterface`. Private constructor; instantiated via static `getFromConnection()` factory. Handles:
   - All CRUD operations against S3 via `Akeeba\S3\Connector`
   - Optional response caching using Joomla's `CallbackController` (cache group: `plg_filesystem_s3`)
   - Cache invalidation on mutating operations via `uncacheDirectory()`
   - EC2 IAM Role credential auto-detection when access/secret keys are empty
   - File name sanitization (`makeSafeName()`: no trailing dots, slashes to underscores, lowercase extensions)

3. **`src/Helper/Ec2Metadata.php`** — Retrieves temporary credentials from EC2 IMDSv2. Static-cached per page load with 5-minute expiry buffer. Only used with Amazon S3 (not custom endpoints) and v4 signatures.

4. **`src/Helper/Preview.php`** — Thumbnail generation for Media Manager. Supports three modes: Lambda@Edge resize, local cached thumbnails (downloaded + resized to WebP), or raw URLs. Time-budgeted to avoid request timeouts.

5. **`src/Filter.php`** — Form filter for directory path sanitization.

6. **`src/Rule/BucketRule.php`** — Form validation rule enforcing AWS S3 bucket naming rules.

### S3 Communication Layer
`vendor/akeeba/s3/` — Akeeba's custom S3 library (also used in Akeeba Backup). Handles signatures (v2/v4), requests, and responses. Installed via Composer as `akeeba/s3`.

### Joomla Media Manager API Workarounds
The adapter contains extensive workarounds for Joomla's inefficient adapter design: `getFile()` is called for both files AND directories, and `getFiles()` is called for both directory listings AND single file metadata. This forces extra S3 API calls on every operation. These workarounds are documented in comments within `getFile()` and `getFiles()`.

## Plugin Configuration

Configured via Joomla's plugin parameters with a `connections` subform (multiple S3 connections). Each connection specifies: type (s3/cloudfront/custom/customcdn), credentials, bucket, region, signature version, storage class, CDN URL, caching settings. The XML manifest is `s3.xml`.

## Key Implementation Details

- **Storage classes**: STANDARD, REDUCED_REDUNDANCY, STANDARD_IA, ONEZONE_IA
- **Move operation**: S3 has no atomic move — implemented as copy + delete source
- **Directory creation**: S3 has no real folders — creates a `.` placeholder file with trailing `/` key
- **Temporary file cleanup**: Tracked in `$tempFiles` array, cleaned up in `__destruct()`
- **MIME detection**: `league/mime-type-detection` (finfo) with fallback to built-in extension map (`MIME_TYPES` constant)
- **EC2 IAM Role auth** (v1.3.0+): Empty access+secret keys triggers IMDSv2 credential fetch. Requires Amazon S3, v4 signatures, EC2 with IAM role.
- **Install script** (`script.plg_filesystem_s3.php`): Handles OPcache invalidation and PSR-4 namespace map rebuild on install/update

## Coding Conventions

- Allman brace style (opening brace on its own line)
- `defined('_JEXEC') or die;` guard on all PHP files
- Tab indentation
- PHPDoc with `@since` version tags
- PHP 8.1+ compatible syntax (no features from PHP versions newer than the declared ceiling, currently 8.6)

## Compatibility

- PHP: 8.1.0 – 8.6.x (platform target: 8.1.0)
- Joomla: 5.4.x – 6.2.x
- Only tested on supported (non-EOL) PHP versions
- Minimum and maximum requirements enforced in install script: PHP 8.1.0–8.6.x, Joomla 5.4.0–6.2.x

## Git: commit and tag outside the sandbox

Commits and tags are always signed, with a key held in 1Password. The 1Password signing agent is reached
over a local socket that agent sandboxes do not expose, so a sandboxed `git commit` or `git tag` **always**
fails (e.g. `error: 1Password: Could not connect to socket. Is the agent running?`).

Run every `git commit` and `git tag` **outside the sandbox from the first attempt** — in Claude Code with
`dangerouslyDisableSandbox: true`, in other harnesses with their equivalent unsandboxed / escalated
execution. Do not try the sandboxed form first, do not diagnose the failure, and never work around it
with `--no-gpg-sign`, `-c commit.gpgsign=false` or unsigned tags.

## Project memory

Project memory lives in `.claude/memory/`, committed with the code, so that it is shared across machines
and across agentic harnesses (Claude Code, Codex, Qwen Code, Kimi Code, Junie, …).

There are no memory files yet. When there is something worth remembering, create `.claude/memory/`,
the topic file, and a table here mapping each file to a concrete trigger ("Before you… | Read").

### Recording new memories

This is the **default and only** place for project memory. Do not write memories for this project to a
harness's private memory store (such as Claude Code's auto-memory under `~/.claude/projects/`); write
them here instead:

- Add to the existing topic file when one fits; otherwise create a new kebab-case `.md` file named after
  the topic, and add a row for it to the table above with a concrete trigger.
- Plain Markdown, no frontmatter. State the rule, then **Why:** (the reason or incident behind it) and
  **How to apply:**. Link related files with relative Markdown links.
- Don't record what the code, Git history or an existing `AGENTS.md` already says — update that
  `AGENTS.md` instead when the rule belongs there. Remove or correct entries that turn out wrong.
- These files are committed: no secrets, credentials, customer data or personal details.
