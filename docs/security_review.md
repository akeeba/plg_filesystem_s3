# Security Review — `plg_filesystem_s3`

This document is the running audit log for the S3 filesystem plugin. Each entry
records (a) the original finding from the security audit, (b) the maintainer's
commentary/correction, and (c) the verified final state. Future security audits
on this project should consult this file first to avoid repeating settled
disputes and to inherit the maintainer's documented preferences.

Entries are ordered most-recent-first. The initial entry below was produced
on 2026-08-26 during the first review of the `development` branch (plugin
version 1.3.1).

---

## Audit window: 2026-08-26 — plugin version 1.3.1

### Maintainer preferences established during this audit

These are *cross-cutting* preferences, not findings. They govern how future
audits on this project should be conducted.

1. **Document every audit in `docs/security_review.md`.** The maintainer does
   not want to repeat context in subsequent audits. Both findings and
   counter-arguments belong here, with the final verdict and a pointer to
   the code that implements it.
2. **Verify documentation claims against `docs/*.md` before flagging.** The
   `customendpoint` field looked like a missing URL validator; it is in fact
   documented to accept bare hostnames (see `docs/3pd-config.md`). Always
   cross-check form field semantics against the third-party config docs.
3. **Treat "intentional public-data design choices" as INFO, not LOW.** This
   plugin is for the Joomla Media Manager, which only handles **public**
   files. Concerns that only matter when files are confidential (cache path
   predictability, URL legibility vs. RFC-3986 escaping, temp-file leaks on
   fatal errors) are operational / UX matters here, not security matters.
   Document them but do not weight them in the severity table.
4. **Confirm field intent before complaining about missing validation.** The
   `accesskey` field is an AWS access key id, not a URL. A missing
   `filter="url"` on it is not a bug. Always re-read the XML attribute name
   before drafting a finding.
5. **The maintainer prefers small atomic commits per fix.** Findings marked
   "fix it" below are expected to land as separate commits. Findings marked
   "design choice" or "deferred" should not be patched.

---

### Finding ledger

| # | Severity (initial) | Severity (final) | Status | Topic |
|---|---|---|---|---|
| 1 | MEDIUM | DEFERRED (by design) | `customendpoint` has no URL validator |
| 2 | MEDIUM | RETRACTED | `accesskey` claimed to need URL validator — misread |
| 3 | LOW | FIXED in `8fadf02` | `Content-Disposition` not quote-escaped |
| 4 | LOW | FIXED in `8d3cfdb` | `customEndpoint` protocol detection uses `:\` instead of `://` |
| 5 | LOW | FIXED in `f0146b5` | `$connection['$region']` typo (key has a stray `$`) |
| 6 | LOW | FIXED in `a38403a` | `Filter::filterDirectory()` does not normalise unicode / strip NUL/CRLF |
| 7 | LOW | DEFERRED (by design) | `getUrl()` only URL-encodes spaces |
| 8 | INFO | CONFIRMED | EC2 IMDSv2 implementation is correct |
| 9 | INFO | CONFIRMED | `BucketRule` faithfully implements AWS naming rules |
| 10 | INFO | DEFERRED (by design) | `Preview.php` thumbnail cache path is predictable |
| 11 | INFO | DEFERRED (by design) | `getResource()` temp file may leak on fatal error |
| 12 | INFO | CONFIRMED | `serialize()` for cache salt is safe (input is admin-only params) |

Skills-based mechanical audits: `_JEXEC` guards PASS on all 8 PHP files;
SQL injection N/A (no SQL surface); controller/authz audits N/A (filesystem
plugin has no controllers).

---

### #1 — `customendpoint` field: no URL validator (`s3.xml` lines 47–51)

**Initial finding (MEDIUM).** `<field name="customendpoint" type="text" …
X_filter="url" X_validate="url" />`. The `X_` prefix disables both. An admin
could therefore supply any string (SSRF risk, because signed S3 requests
would be sent to whatever host they typed).

**Maintainer commentary.** Intentional. Some third-party endpoints are NOT
full URLs but bare domain names (e.g. `objects-us-east-1.dream.io`,
`0123…r2.cloudflarestorage.com`). Joomla's URL filter and validator would
reject these common use cases. The plugin therefore strips validation at the
form level and handles scheme detection at object initialisation time
(`S3Filesystem.php` constructor).

**Verified against `docs/3pd-config.md`.** Confirmed:
- DreamHost: "Custom endpoint: see above, must not include the bucket name,
  e.g. `objects-us-east-1.dream.io`" — bare hostname.
- Cloudflare R2: instructions explicitly say "Remove the `https://` prefix
  and the `/something` to get your Custom Endpoint. In this example, it
  would be `0123456789abdef0123456789abdef.r2.cloudflarestorage.com`" —
  bare hostname.

**Final status: DEFERRED (by design).** The finding is incorrect. If the
maintainer later wants defence-in-depth (e.g. reject `169.254.0.0/16` and
RFC1918 ranges by default), that should be a separate proposal — not a
silent re-enabling of `filter="url"`.

---

### #2 — `accesskey` field: claimed missing URL validator (`s3.xml` lines 53–57) — RETRACTED

**Initial finding (MEDIUM).** I claimed the `accesskey` field had its URL
validator stripped and therefore accepted any string.

**Maintainer commentary.** "THIS IS NOT A URL!!! It is the Access Key of S3
authentication."

**Verified.** The maintainer is correct. I conflated the per-field validators
on `customendpoint` and `accesskey`. The `accesskey` field never had URL
validation — it is an AWS access key id, not a URL. The original finding is
retracted.

**Final status: RETRACTED.** If a format validator is later desired
(`[A-Z0-9]{20}` for IAM user keys, `ASIA[A-Z0-9]{16}` for STS/EC2 IMDS
keys), that is a separate enhancement request and must not be confused with
URL validation.

---

### #3 — `Content-Disposition` header not quote-escaped (`S3Filesystem.php:637`)

**Initial finding (LOW).**

```php
$headers['Content-Disposition'] = sprintf('attachment; filename="%s"', basename($name));
```

`basename()` strips directory components but does not escape `"`, `\` or
CR/LF. Header injection into the S3 `PUT` request and from there into object
metadata is theoretically possible.

**Maintainer commentary.** Agreed. Add to the plan for fixing it as per the
suggestion (wrap in `addcslashes($name, "\"\\\r\n")` or switch to the
RFC 6266 `filename*=UTF-8''…` form).

**Final status: FIXED in commit `8fadf02`** (2026-08-26) using the
`addcslashes` form (keeps the existing single-token `filename=` shape
consistent with what we already store).

---

### #4 — `customEndpoint` protocol detection uses `:\` instead of `://` (`S3Filesystem.php:343-356`)

**Initial finding (LOW).**

```php
$protoPos = strpos($customEndpoint, ':\\');
if ($protoPos !== false) { … }
```

The intended separator is `://`. As written, no well-formed `http://…` or
`https://…` URL ever matches the `:\` (colon-backslash) pattern, so the
protocol-detection branch is dead code. The default of `useSSL = true`
saves the day for bare-domain inputs (which is the documented use case).

**Maintainer commentary.** "I really did mean `://`. That was a brainfart.
Please fix that."

**Final status: FIXED in commit `8d3cfdb`** (2026-08-26). The dead-code
state was masked by finding #1 (the documented use case is bare domain,
which never tripped the broken parser).

---

### #5 — `$connection['$region']` typo (`S3Filesystem.php:433`)

**Initial finding (LOW).**

```php
$customRegion  = $connection['$region'] ?? '';
```

The literal key contains a stray `$`. The real XML field is `custom_region`,
so this lookup always fails and the Custom region option in the dropdown is
permanently dead.

**Maintainer commentary.** "A bug indeed. Please fix it."

**Final status: FIXED in commit `f0146b5`** (2026-08-26). Not a security
issue, but a functionality regression worth fixing in the same release
cycle as the security review follow-ups.

---

### #6 — `Filter::filterDirectory()` does not normalise unicode / strip NUL or CRLF (`Filter.php`)

**Initial finding (LOW).**

```php
$directory = str_replace('\\', '/', trim($directory, '/\\'));
while (strpos($directory, '//') !== false) { … }
return trim($directory, '/');
```

Strips leading/trailing slashes and collapses double slashes, but does not
strip embedded NUL bytes, CR/LF, `#`, `?`, or NFD/NFC unicode forms. For
S3 keys (opaque bytes) this is mostly cosmetic, but the directory is later
concatenated into a URL via `getUrl()`, where `#`/`?` would create fragments
/ query strings and CR/LF would break URL serialisation.

**Maintainer commentary.** "Agreed, this is an issue. We need to create a
helper class with a `normaliseUnicodePath(string $path)` method implementing
your suggestion and use it there."

**Final status: FIXED in commit `a38403a`** (2026-08-26). New helper at
`src/Helper/PathNormaliser.php` with the agreed behaviour, wired into
`Filter::filterDirectory()`. ext-intl is optional — the unicode
normalisation step is silently skipped when the extension is missing.
Verified locally with 9 representative inputs (slashes, `#`, `?`,
empty/null, backslashes, double slashes); all return expected values.

---

### #7 — `getUrl()` only URL-encodes spaces, nothing else (`S3Filesystem.php:1363`)

**Initial finding (LOW).** Only `str_replace(" ", "%20", $path)` is
performed before concatenating into the CDN URL. Other special characters
(`#`, `?`, `&`, `%`, non-ASCII) are passed through. Could create malformed
or content-spoofing URLs.

**Maintainer commentary.** "This is intentional. When someone has the
filename `δοκιμή.pdf` they expect to see a URL ending in the
human-readable `.../δοκιμή.pdf` text instead of the full URL-escaped but
human-UNREADABLE `.../%CE%B4%CE%BF%CE%BA%CE%B9%CE%BC%CE%AE.pdf`."

**Final status: DEFERRED (by design).** The plugin is for the public
Media Manager. URL legibility for human-editable content is a deliberate
UX trade-off; the only realistic abuse is somebody using `#` or `?` in a
filename to spoof a fragment or query string in CDN URLs, and Joomla's
upstream `cleanFileName()` already removes those characters. Do not patch.

---

### #8 — EC2 IMDSv2 implementation (`Ec2Metadata.php`)

**Initial finding (INFO, confirmed correct).** Hardcoded endpoint
`http://169.254.169.254`, IMDSv2 session-token flow, 2-second timeouts,
fail-silent, 5-minute credential-expiry buffer, restricted to
`type ∈ {'s3','cloudfront'}` + `signature === 'v4'`. Matches the AWS
security guidance documented in `docs/ec2-iam-roles.md`.

**Maintainer commentary.** None — confirmed as correct.

**Final status: CONFIRMED.** No action required. Keep this section as the
reference for future EC2-metadata-related audits.

---

### #9 — `BucketRule` (`Rule/BucketRule.php`)

**Initial finding (INFO, confirmed correct).** Faithfully implements AWS's
bucket naming rules from the January 2025 update (per CHANGELOG 1.1.4).
The two rules explicitly documented as NO-OP (no adjacent periods, no
IP-format names) are correctly eliminated because dots are disallowed
entirely.

**Maintainer commentary.** None — confirmed as correct.

**Final status: CONFIRMED.** No action required. Future audits should
re-check whenever AWS publishes new bucket-naming restrictions.

---

### #10 — `Preview.php` thumbnail cache path is predictable (`Preview.php:391`)

**Initial finding (INFO).** Thumbnail cache path derives from
`md5($url . '::' . $lastModifiedDate)`. Path is therefore predictable by
anyone who knows the source URL and last-modified date. Cache lives under
`JPATH_ROOT/media/plg_filesystem_s3/cache/`, which is web-accessible by
default.

**Maintainer commentary.** "The Joomla Media Manager only handles **public**
files, viewable by anyone. A predictable preview cache path is not a
security concern at all as a result. The only reason we cache is
performance, not confidentiality."

**Final status: DEFERRED (by design).** Cache contents are public anyway
because Media Manager only stores public files. Do not patch.

---

### #11 — `getResource()` temp file may leak on fatal error (`S3Filesystem.php:1015-1025`)

**Initial finding (INFO).** Tempfile in `$app->get('tmp_path')` is cleaned
in `__destruct()`. On a PHP fatal error, `__destruct()` does not run, so
the file leaks until the OS temp cleaner runs.

**Maintainer commentary.** "Correct, it's a PHP limitation. Users are
expected to periodically clean their temp-folder. However, since we handle
public files only this is not really a security-relevant issue, more of an
annoyance and operational issue (temp directory may start getting full)."

**Final status: DEFERRED (by design).** PHP language limitation, not a
plugin bug. Operational concern only.

---

### #12 — `serialize()` for cache salt (`S3Filesystem.php:413`)

**Initial finding (INFO, confirmed correct).**

```php
$this->cacheSalt = hash('md5', serialize($setup ?? []));
```

`$setup` is built entirely from Joomla params (admin-controlled, never
direct user input), so this is **not** a `unserialize()` injection vector.

**Maintainer commentary.** None — confirmed as correct.

**Final status: CONFIRMED.** Worth noting only because future maintainers
sometimes see `serialize()` and reflexively flag it as a deserialisation
risk. There is no issue here.

---

### Open follow-ups — landed 2026-08-26

All four planned follow-ups landed as atomic commits on 2026-08-26.
Commits (newest first):

1. `a38403a` — Add `PathNormaliser` helper; route `Filter::filterDirectory()` through it (finding #6).
2. `8fadf02` — Escape `Content-Disposition` filename in `createFile` (finding #3).
3. `f0146b5` — Fix custom region lookup typo (`$region` → `custom_region`) (finding #5).
4. `8d3cfdb` — Fix custom endpoint protocol detection (`:\` → `://`) (finding #4).

The commit messages reference this document by section number, so
`git blame` points back to the audit reasoning.

The `s3.xml` version was intentionally not bumped in any of the four
commits — version bumps are part of the Akeeba release workflow
(`phing release`) rather than per-commit. When the next release ships,
the version should move to 1.3.2 (patch) or 1.4.0 (minor) depending on
how other pending work is bundled.