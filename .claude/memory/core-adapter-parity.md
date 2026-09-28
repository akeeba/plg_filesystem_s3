# Parity with Joomla's local filesystem adapter

The S3 adapter should behave like core's `LocalAdapter` (`plugins/filesystem/local/src/Adapter/LocalAdapter.php`
in a Joomla site), no stricter and no looser. Security hardening means porting what `LocalAdapter` does
(`checkContent()` / `MediaHelper::canUpload()`, `getSafeName()`, `Path::check()`), not adding policies of our own.

**Why:** the operator decided this while fixing the 2026-09 security audit (`security.md`). Declined as
departures from core: overriding the stored Content-Type of SVG/HTML uploads, percent-encoding every URL
segment (core's `getEncodedPath()` encodes only spaces), and hiding oddly named objects from listings (core
lists whatever is on disk). Users who allow SVG, HTML etc. in the Media options do so knowingly. Weaknesses
that remain are core's to fix; report them upstream.

**How to apply:** before proposing a fix or hardening in `S3Filesystem`, check what `LocalAdapter` does in the
same situation. Match it. If the only fix goes beyond core, present it as a departure and let the operator
decide.
