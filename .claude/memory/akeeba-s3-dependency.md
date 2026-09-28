# Fixing bugs in the akeeba/s3 dependency

Bugs in S3 signing, host names, pre-signed URLs or `Configuration` belong upstream in akeeba/s3
(`akeeba/s3`, relative to the common projects root), not in a plugin workaround.

**Workflow the operator expects:** red/green unit test upstream (`composer test`) → commit → push to
`development` → wait 20 seconds → `composer update akeeba/s3` here → confirm `composer.lock` names the new commit
and the vendored code contains the change → red/green test here too. Show the red here against the *old*
vendored copy before updating (for an already-updated lock: `git stash push composer.lock && composer install`, run,
then restore).

**Drift tests in this repository** go through `UnitTest/Stubs/curl-recorder.php`: it shadows `curl_setopt()`,
`curl_exec()` and `curl_getinfo()` in the `Akeeba\S3` namespace, records the options akeeba/s3 really sets, and can
play a canned S3 response through its callbacks. Test observable behaviour (headers, TLS options, messages), never
akeeba/s3's private methods, so refactors upstream do not break the guard.

**Gotchas:**
- Library files exit silently (`defined('AKEEBAENGINE') || die()`), and so does anything `_JEXEC`-guarded:
  a script or PHPUnit that stops with no output is usually a missing constant.
- `Configuration::setSignatureMethod('v2')` empties the region; set the region after it if you switch back.
- Pinning to a tagged release happens only at release time (`akeeba-deps` skill); `dev-development` is intended.

**Why:** M3, L1, L5 and known issues #2 and #12 were fixed this way in 2026-09, at the operator's request.

**How to apply:** when a fix touches akeeba/s3's behaviour, follow the workflow above; ask before pushing if the
operator has not already asked for it in this piece of work.
