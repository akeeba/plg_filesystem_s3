# Threat model: what the plugin does and does not defend against

- **Super Users are fully trusted.** Anything only a Super User controls (the connections, the storage
  service, the endpoint, the CDN URL and its scheme, Media Manager options such as upload size limits) is
  not an attack surface. "A Super User can configure it insecurely" is not a finding.
- **The storage service and CDN are trusted.** A hostile or compromised S3 service or CDN is out of scope.
- **Showing more to Super Users is acceptable** where it helps troubleshooting, e.g. raw error details when
  Site Debug is on.
- **The Media Manager is for public files, by Joomla's design.** A bucket or endpoint that is not meant to hold
  public files is a misconfiguration, not a vulnerability.
- **Joomla's own Media Manager design is not ours to fix**: every connection is offered to every com_media
  user, and Super Users see plugin settings, secret key included, as with any Joomla extension.
- **Downgrades must stay allowed** (`$allowDowngrades = true`): it is the only way back from a dev release to
  a stable one. Blocking them would be a major bug.
- **v2 signatures must stay selectable**: some third-party S3-compatible services do not support v4.
- **`akeeba/s3` at `dev-development` on the development branch is intended.** It is pinned to a tagged
  release when releasing (the `akeeba-deps` skill).
- **The cache salt includes the credentials** (`md5(serialize($setup))`): a known issue with no practical
  fix, since a separate salt would need a new plugin setting.
- **Do not prune the thumbnail cache by age.** There is no reliable signal for "no longer used": `atime` is
  usually not recorded (`noatime` is the Linux default on SSDs), and an old thumbnail may still be in heavy
  use.

**Why:** the operator's rulings on the 2026-09 security audit (`security.md`): L3 invalid, L4 not an issue,
L7's pruning declined, L1 keeps raw errors for Super Users with Site Debug on, I1–I8 not issues or not
fixable.

**How to apply:** before reporting or fixing a security issue, check whether it needs a Super User or a
hostile storage service/CDN to exploit. If so, it is not a finding. See also
[core-adapter-parity.md](core-adapter-parity.md).
