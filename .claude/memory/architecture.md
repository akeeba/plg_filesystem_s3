# Architecture and implementation details

Read before changing the plugin's classes, configuration, install script or listing cache.

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
   - A safety layer ported from core's `LocalAdapter`: `checkContent()` (`MediaHelper::canUpload()`), `makeSafeName()`
     (`LocalAdapter::getSafeName()`), `checkPath()` (`Path::check()`), called from every public method
   - `s3()`: every akeeba/s3 call goes through it, so `safeException()` can hide bucket internals from users (raw for a
     Super User with Site Debug on) and log the full error to `administrator/logs/plg_filesystem_s3.php`
   - `statPath()`: file/folder lookup that also recognises folders without a `folder/` placeholder object

3. **`src/Helper/Ec2Metadata.php`** — Retrieves temporary credentials from EC2 IMDSv2. Static-cached per page load with 5-minute expiry buffer. Only used with Amazon S3 (not custom endpoints) and v4 signatures.

4. **`src/Helper/Preview.php`** — Thumbnail generation for Media Manager. Supports three modes: Lambda@Edge resize, local cached thumbnails (downloaded + resized to WebP), or raw URLs. Time-budgeted to avoid request timeouts.

5. **`src/Filter.php`** — Form filter for directory path sanitization.

6. **`src/Rule/BucketRule.php`** — Form validation rule enforcing AWS S3 bucket naming rules.

### S3 Communication Layer
`vendor/akeeba/s3/` — Akeeba's custom S3 library (also used in Akeeba Backup). Handles signatures (v2/v4), requests, and responses. Installed via Composer as `akeeba/s3`.

### Joomla Media Manager API Workarounds
The adapter contains extensive workarounds for Joomla's inefficient adapter design: `getFile()` is called for both files AND directories, and `getFiles()` is called for both directory listings AND single file metadata. This forces extra S3 API calls on every operation. These workarounds are documented in comments within `getFile()` and `getFiles()`.

## Plugin Configuration

Configured via Joomla's plugin parameters with a `connections` subform (multiple S3 connections). Each connection specifies: type (s3/cloudfront/custom/customcdn), credentials, bucket, region (the form saves `custom` for a custom region), signature version, storage class, CDN URL, public URL protocol (`url_scheme`: https/http), caching settings. The XML manifest is `s3.xml`.

## Key Implementation Details

- **Storage classes**: STANDARD, REDUCED_REDUNDANCY, STANDARD_IA, ONEZONE_IA
- **Move operation**: S3 has no atomic move — implemented as copy + delete source
- **Directory creation**: S3 has no real folders — creates a `.` placeholder file with trailing `/` key
- **Temporary file cleanup**: Tracked in `$tempFiles` array, cleaned up in `__destruct()`
- **MIME detection**: `league/mime-type-detection` (finfo) with fallback to built-in extension map (`MIME_TYPES` constant)
- **EC2 IAM Role auth** (v1.3.0+): Empty access+secret keys triggers IMDSv2 credential fetch. Requires Amazon S3, v4 signatures, EC2 with IAM role.
- **Install script** (`script.plg_filesystem_s3.php`): Handles OPcache invalidation and PSR-4 namespace map rebuild on install/update, and deletes files older versions shipped (`$deleteFiles`/`$deleteFolders`). **When you remove a file from the plugin, add it there**; `UnitTest/Structure/ObsoleteFilesTest` checks Git history and fails otherwise
- **Listing cache**: shared by all users; anything user-specific (formatted dates) is computed after the cache read (`formatDates()`)
