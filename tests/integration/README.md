# plg_filesystem_s3 end-to-end tests

PHPUnit on the **host**, driving a real, disposable Joomla site and a real S3-compatible server (MinIO)
in Docker, over real HTTP. Nothing boots Joomla in-process. The suite uses the plugin exactly as the
Media Manager does, through Joomla's own `com_media` JSON API with a real back-end session and a real
anti-CSRF token. It then checks every effect in the bucket itself, independently of the plugin.

## Requirements

- Docker with the Compose plugin (`docker compose`) or `docker-compose`.
- PHP on the host with `curl`, `pdo_mysql`, `simplexml` and `json`. `getimagesizefromstring()` must
  know WebP, which is standard since PHP 7.1.
- PHPUnit 11, installed globally: `composer global require phpunit/phpunit`, with Composer's global
  `vendor/bin` on your `PATH`. It is **not** a project dependency.
- [Phing](https://www.phing.info/) on `PATH` and the sibling `../buildfiles` checkout, to build the
  package (`phing git`). Use `--skip-build` to reuse the newest `release/plg_filesystem_s3-*.zip`.
- `composer install` already run in the repository root. The package ships `vendor/`.
- `jq`, `gunzip`, `unzip` and `curl`, to resolve and unpack Joomla. No network is needed if the Joomla
  package is already in `inbox/`.

## Quick start

```sh
tests/integration/docker/run.sh                    # provision, test, tear down
tests/integration/docker/run.sh --keep-containers  # leave it up to iterate
phpunit -c phpunit-integration.xml --filter CachingTest   # then: seconds per run
php tests/integration/provision.php                # put fixtures back after poking around
tests/integration/docker/run.sh --down             # tear everything down
```

## `run.sh` options

| Option | Effect |
|---|---|
| `-j`, `--joomla=VERSION` | Joomla to test: full (`6.1.3`), branch (`6.1`) or major (`6`). Default: `JOOMLA_VERSION` in `.env`. |
| `-p`, `--php=VERSION` | PHP image tag, e.g. `8.1`. Default: `PHP_VERSION` in `.env`. |
| `--matrix` | Run every Joomla/PHP pair in `JOOMLA_MATRIX`, each from a clean slate; build only once. |
| `-f`, `--filter=NAME` | Passed to PHPUnit as `--filter`. |
| `--skip-build` | Do not run `phing git`; install the newest package in `release/`. |
| `--keep-containers` | Leave the stack up after the tests. |
| `--no-tests` | Provision only. |
| `--down` | Scrub containers, volumes, the web root and generated config, then exit. |
| `-- <args>` | Everything after `--` goes to PHPUnit verbatim. |

`run.sh` **always scrubs first**, so a crashed previous run can never leak into the next one. It
refuses a Joomla or PHP version outside the plugin's declared range *before* touching Docker. It reads
both bounds from `plugins/filesystem/s3/script.plg_filesystem_s3.php`, the same file the installer
enforces them from. It also refuses a PHP version older than the Joomla package itself requires.

## The stack

| Service | What | Host port |
|---|---|---|
| `db` | MySQL 8.4 | `33319` |
| `php` | PHP-FPM with Joomla and the Joomla CLI | — |
| `web` | Apache 2.4, FastCGI to `php` | `8190` |
| `minio` | MinIO, the S3 server every connection points at (`http://minio:9000` inside the network) | `9190` |
| `mc` | MinIO client, one-shot (`docker compose run --rm mc …`), full credentials | — |

The ports avoid every sibling harness under `~/Projects` (8080–8180, 33306–33316, 33406).

**How the tests see the bucket.** `run.sh` gives the host *anonymous, read-only* access to the test
bucket (`docker/config/minio-anonymous-policy.json.tpl`). It checks that listing works and that an
anonymous PUT is refused. Every "the object is really there / really gone / has this content"
assertion reads through that view with a plain HTTP client, never through akeeba/s3, the library the
plugin itself uses. A successful anonymous GET is also the proof that an object is publicly readable.
MinIO does not implement object ACLs, so on this server a bucket policy is the only way to make
objects public. Writing behind the plugin's back (seeding, cache tests) goes through `mc`.

## Fixtures

`src/SiteProvisioner.php` (run by `run.sh`, or by hand as `php tests/integration/provision.php`):

- **One connection per S3 flavour**, as Media Manager adapters `s3-<label>`:

  | Adapter | What it exercises |
  |---|---|
  | `s3-v2path` | The workhorse: path-style, v2 signatures |
  | `s3-v4path` | v4 signatures (the form's default) |
  | `s3-nested` | Directory option `nested/root` |
  | `s3-cdn` | "Custom CDN" type; the CDN URL is MinIO's own public bucket URL |
  | `s3-cached` | The S3 response cache on (300 s) |
  | `s3-badsecret` | Wrong secret key |
  | `s3-nobucket` | No bucket: the plugin must drop it |

- **`viewer`**, a back-end user who may open the Media Manager (`core.login.admin`, `com_media`
  `core.manage`) but may not create, edit or delete anything in it.
- **Seed objects** under `fixtures/` and `nested/`, with **no folder placeholder objects**. That is what a
  bucket filled by the AWS CLI, CyberDuck or `mc` looks like, and it is the harder case for the
  adapter.

Each test that writes works in its own scratch prefix (`t-<random>/`), removed when its class
finishes. `tearDown()` restores the plugin's parameters and clears its S3 response cache and local
thumbnails.

## What is covered

| Test class | Claims |
|---|---|
| `HarnessTest` | The adapters are registered; the seed is in place; the host's bucket view is read-only. |
| `ListingTest` | Folders, files, metadata, single-file lookups, placeholder-less folders, >1,000-object pagination. |
| `FileOperationsTest` | Create folder/file, content and headers, 409 without override, edit, copy, move, folder rename, delete (file, folder tree). |
| `PublicUrlTest` | URLs are unsigned, name the object, include the Directory, are fetchable; CDN URLs; spaces. |
| `AccessControlTest` | Guests, missing/wrong CSRF tokens and a user without create/edit/delete are refused, **and the bucket is unchanged**. Each refusal has a positive control. |
| `ConnectionTypesTest` | v2, v4 and CDN round trips; the Directory prefix confines reads and writes; bad credentials fail loudly, leak nothing, and do not affect other connections. |
| `CachingTest` | The cache really caches (a change behind its back is not seen), and upload, new folder, delete and move each invalidate it. |
| `SearchTest` | Exact and recursive search; substring search; searching a large folder. |
| `ThumbnailCacheTest` | Local WebP thumbnails are generated, sized, served by the site and reused; failures degrade to the original URL. |

## Known product bugs

Tests blocked by a genuine product bug are **skipped with the diagnosis**, not rewritten to accept the
broken behaviour. The list lives in `AbstractE2ETestCase::KNOWN_BUGS`, and each entry names its
number in `known-issues.md` (repository root, git-ignored). When a bug is fixed, delete its entry. Any
`knownBug()` call that still names it then fails, so nothing lingers. To see the real behaviour
without editing anything:

```sh
S3FS_E2E_IGNORE_KNOWN_BUGS=1 phpunit -c phpunit-integration.xml
```

Beware of `SearchTest::testSearchingALargeFolderFinishes` in that mode. Until known issue #1 is fixed,
it hangs for the client's 60-second timeout, and the PHP-FPM worker keeps looping until PHP's
`max_execution_time` (300 s).

Every known-bug test was checked to fail without its skip, so none can pass by accident.

## The version matrix

`JOOMLA_MATRIX` in `docker/env.dist` is `5.4:8.1,8.5 6.0:8.3,8.5 6.1:8.3,8.5`. These are the **edges**
of the supported range, not a cross product.

| Bound | Value | Where it comes from |
|---|---|---|
| Joomla floor | 5.4 | `$minimumJoomla = '5.4.0'` in the installer script (= `extra.akcompat.limit` in `composer.json`) |
| Joomla ceiling | 6.1 today | `$maximumJoomla = '6.3'` (exclusive) allows 6.2, but 6.2 is not released yet; add `6.2:8.3,8.5` when it is |
| PHP floor | 8.1 (Joomla 5.4), 8.3 (Joomla 6.x) | the plugin's `$minimumPhp = '8.1.0'`; Joomla 6 itself requires 8.3 |
| PHP ceiling | 8.5 | the plugin allows `<8.7`, but 8.5 is the newest stable `php:*-fpm` image; add 8.6 when it ships |

The plugin has no Joomla-version-specific code paths of its own. The matrix is there for the code it
*leans on*, which does change between versions: the `com_media` API and adapter contract (the
double-duty `getFile()` and `getFiles()` calls), `CallbackController` caching, `Image::toFile()`
WebP output, and `Joomla\Http`. **PHP 8.1 on Joomla 5.4 is the matrix entry that matters most for the
plugin's own code.** `composer.json` pins dependency resolution to PHP 8.1, and a 8.2+ construct
anywhere in `src/` or `vendor/` would only fail there. A green default run (Joomla 6.1, PHP 8.5) says
nothing about that combination; run `--matrix` before a release.

Keep the matrix current with the `audit-e2e-matrix` skill rather than editing versions by hand.

## What is deliberately not covered

- **Amazon S3 and CloudFront themselves**: dual-stack endpoints, storage classes other than
  `STANDARD`, real canned ACLs, EC2 IAM-role credentials, Lambda@Edge resizing. None of these can run
  in Docker. MinIO accepts and ignores `x-amz-acl`, and only knows `STANDARD` and
  `REDUCED_REDUNDANCY`. The EC2 metadata handshake and the ACL/region/endpoint parsing are
  unit-tested instead (`UnitTest/`).
- **Virtual-hosted access**: it needs wildcard DNS for `bucket.minio`, which the compose network does
  not have.
