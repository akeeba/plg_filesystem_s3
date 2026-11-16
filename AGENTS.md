# AGENTS.md

This file provides guidance to coding agents when working with code in this repository.

## Project Overview

Joomla 5/6 filesystem plugin (`plg_filesystem_s3`) that integrates Amazon S3 and S3-compatible storage with Joomla's Media Manager. Supports optional CloudFront CDN URL generation.

**Critical constraint**: Only works with files stored with Public ACLs. All uploads are hardcoded to `Acl::ACL_PUBLIC_READ`. The plugin strips query strings from authenticated S3 URLs since it expects public files.

## Build & Development

- **Build**: `phing git` (default), `phing package-pkg` (ZIP package in `build/release/`), `phing release` (GitHub release)
- **Dependencies**: `composer install` (vendors go to `plugins/filesystem/s3/vendor/`)
- **Composer platform target**: PHP 8.1.0
- **All tests**: `composer test` or `phing test` (both suites; installs the Composer dependencies first)
- **Unit tests**: `composer test:unit` / `phing test-unit`, or plain `phpunit` (global PHPUnit 11; config `phpunit.xml`, tests in `UnitTest/`)
- **E2E tests**: `composer test:integration` / `phing test-integration`, or `tests/integration/docker/run.sh` for its options (Docker: Joomla + Garage; config `phpunit-integration.xml`). Read `tests/README.md` and `tests/integration/README.md` first
- **Known bugs** found by the suites are numbered in `known-issues.md` (git-ignored); their tests are skipped, not rewritten
- Build config is imported from a sibling `../buildfiles/` repository (`common.xml`)

## Always-on rules

- Run every `git commit` and `git tag` outside the sandbox from the first attempt (1Password signing agent); never use `--no-gpg-sign`. See [git-signing.md](.claude/memory/git-signing.md).
- When you remove a file from the plugin, add it to `$deleteFiles`/`$deleteFolders` in `script.plg_filesystem_s3.php`; `UnitTest/Structure/ObsoleteFilesTest` fails otherwise.
- `services/provider.php` is the only place the vendor autoloader is required.
- Allman braces, tab indentation, `defined('_JEXEC') or die;` in all PHP files; PHP 8.1.0 to 8.6.x, Joomla 5.4 to 6.2.

## Project memory

Project memory lives in `.claude/memory/`. Read the matching file before starting that kind of work.

| File | Read it when… |
|------|---------------|
| [`architecture.md`](.claude/memory/architecture.md) | Before you change the plugin's classes, `S3Filesystem`/`S3` internals, plugin configuration, the install script or the listing cache |
| [`coding-conventions-and-compatibility.md`](.claude/memory/coding-conventions-and-compatibility.md) | Before you write code, or change the supported PHP or Joomla versions |
| [`git-signing.md`](.claude/memory/git-signing.md) | Before you run `git commit` or `git tag` |
| [`core-adapter-parity.md`](.claude/memory/core-adapter-parity.md) | Before you fix or harden anything in `S3Filesystem` (security findings included) |
| [`threat-model.md`](.claude/memory/threat-model.md) | Before you report, triage or fix a security finding |
| [`com-media-adapter-contract.md`](.claude/memory/com-media-adapter-contract.md) | Before you change an adapter method, or write an E2E test for one |
| [`akeeba-s3-dependency.md`](.claude/memory/akeeba-s3-dependency.md) | Before you fix anything that lives in akeeba/s3 (signing, host names, pre-signed URLs, `Configuration`) |
| [`e2e-harness-gotchas.md`](.claude/memory/e2e-harness-gotchas.md) | Before you add or change an E2E test |

## Recording new memories

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
