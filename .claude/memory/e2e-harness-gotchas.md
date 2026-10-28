# E2E harness gotchas

- **Toggle Site Debug by editing `configuration.php`**, not `cli/joomla.php config:set`: `config:set` leaves the file
  read-only (0444), so the next `config:set` fails. See `ErrorDisclosureTest::setSiteDebug()`.
- **The plugin log file accumulates across tests.** Assert on what the request under test appended
  (`ErrorDisclosureTest::loggedDuring()`), never on the whole file: a test once passed only because earlier tests
  had logged what it looked for.
- **Garage checks the v4 region** (`s3_region = "us-east-1"` in the shared `garage.toml`). MinIO needed
  `MINIO_SITE_REGION` for that, and without it accepted any region, which hid known issue #12 (every v4
  custom-endpoint request signed for an empty region). Keep the region set. Garage implements v4 signatures only,
  so the E2E connections are all v4; v2 is unit-tested.
- **Anonymous reads go through the `s3` nginx front**, not Garage's S3 port (which refuses anonymous requests).
  Anonymous bucket listing does not exist: list with `Bucket::keys()`, which uses the `aws` CLI.
- **Change one provisioned connection** with `setConnectionParams('<label>', [...])`; `tearDown()` restores the
  provisioned plugin parameters.
- **Guard new tests with a positive control** where the red could have another cause (e.g. prove the viewer's
  timezone applies without the cache before blaming the cache), and use core's `local-images` adapter as the
  baseline for parity tests.
- **The site root is mounted on the host** (`tests/integration/docker/www`): tests can plant or inspect files there
  directly; commands inside the container go through `ContainerCli`.

**Why:** each of these cost a wrong red or a false green during the 2026-09 work.

**How to apply:** read this before adding or changing an E2E test.
