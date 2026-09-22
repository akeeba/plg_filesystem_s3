# plg_filesystem_s3 test suites

Two suites. The split between them is deliberate.

| Suite | Config | Location | Needs | Speed | Answers |
|---|---|---|---|---|---|
| **Unit** | `phpunit.xml` | `UnitTest/` | PHP, PHPUnit, `composer install` | well under a second | is this logic correct in isolation? |
| **Integration (E2E)** | `phpunit-integration.xml` | `tests/integration/` | Docker | a few minutes to provision, under a minute to run | does the real Media Manager, over real HTTP, against a real S3 server, do what it should and refuse what it should? |

> The unit suite lives in `UnitTest/` at the **repository root**, not under `tests/`. `tests/` holds only
> the integration suite. This matches the layout of the other Akeeba extensions.

Neither suite adds a PHPUnit dependency to `composer.json`. Both use PHPUnit 11 installed globally
(`composer global require phpunit/phpunit`) and on your `PATH`.

## Unit suite (`UnitTest/`)

```sh
composer install               # once: the adapter needs akeeba/s3 and league/mime-type-detection
phpunit                        # phpunit.xml is picked up automatically
phpunit --filter S3Filesystem
```

It boots **no** Joomla, makes **no** network request, and talks to **no** S3 server.
`UnitTest/bootstrap.php` registers a PSR-4 autoloader for the plugin and loads the plugin's own Composer
autoloader. It also loads a small set of guarded Joomla stubs (`UnitTest/Stubs/joomla-stubs.php`; read
its header before adding to it). `HttpFactory` is the one programmable fake: it lets the tests put the
EC2 metadata client in front of every answer IMDSv2 can give. `JPATH_ROOT` points at a disposable
per-run directory.

Target PHP: the highest version satisfying `composer.json`'s `require.php` (`>=8.1.0 <8.7`). Today that
is 8.5, because 8.6 is not released yet.

What it covers:

- **Connection parsing** (`S3Filesystem::getFromConnection()`): required settings; when EC2 role
  credentials are fetched, and that they are fetched once per page load; signature, region, endpoint
  and SSL; path-style, dual-stack and HTTP-date options; bucket sanitising; the canned-ACL allow-list;
  cache-lifetime clamping.
- **Public URLs**: unsigned, directory-prefixed S3 URLs; CDN URLs; space encoding.
- **Name sanitising** and the Amazon-only storage-class header.
- **Preview**: which files get thumbnails in each mode, the extension list, dimension clamping, and
  Lambda@Edge URLs.
- **EC2 metadata (IMDSv2)**: the three-step handshake, and `null` (never an exception or a half-filled
  credential set) for every failure.
- **Form rules and filters**: AWS bucket naming rules; the Directory filter, including NUL/CR/LF,
  `#`/`?` and NFC normalisation.
- **Structure**: every shipped PHP file has the `_JEXEC` guard; the installer enforces exactly the
  version range `composer.json` declares.

A successful run ends in `OK, but some tests were skipped!`. Each skip is a known product bug; see
below.

## Integration suite (`tests/integration/`)

```sh
tests/integration/docker/run.sh                    # provision, test, tear down
tests/integration/docker/run.sh --keep-containers  # leave it up to iterate
tests/integration/docker/run.sh --matrix           # every supported Joomla/PHP edge
```

**Read [`tests/integration/README.md`](integration/README.md) before adding or changing tests.** It is
the reference for the harness, the fixtures, the version matrix and why it contains what it does.

## Skipped tests are known product bugs

Both suites **skip** tests that fail because of a genuine product defect, with the diagnosis as the
skip message, rather than asserting broken behaviour as correct. The defects are numbered, most
severe first, in `known-issues.md` at the repository root (git-ignored working notes). In the unit
suite, each such test calls `markTestSkipped()`: remove the call once the bug is fixed. In the E2E
suite, they are centralised in `AbstractE2ETestCase::KNOWN_BUGS`: delete the entry once the bug is
fixed.

## Conventions

- Namespaces: `Akeeba\Plugin\Filesystem\S3\UnitTest\…`, mirroring the source tree (one `<Class>Test`
  per class), and `Akeeba\Plugin\Filesystem\S3\IntegrationTest\Tests\…`.
- The standard file header, and `defined('_JEXEC') or die;` after the namespace, like every other PHP
  file here.
- PHPUnit 11 **attributes** (`#[CoversClass]`, `#[DataProvider]`), never annotations.
- **Test names state the claim**, not the mechanics.
- **Confirm expected output empirically** before hard-coding it. Run the real code and look.
- **Check that a new test can fail.** For a regression test, revert the fix and watch it go red. For a
  refusal, pair it with a positive control that succeeds.
