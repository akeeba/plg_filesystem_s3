# Threat model: what the plugin does and does not defend against

- **Super Users are fully trusted.** Anything only a Super User controls (the connections, the storage
  service, the endpoint, the CDN URL and its scheme, Media Manager options such as upload size limits) is
  not an attack surface. "A Super User can configure it insecurely" is not a finding.
- **The storage service and CDN are trusted.** A hostile or compromised S3 service or CDN is out of scope.
- **Showing more to Super Users is acceptable** where it helps troubleshooting, e.g. raw error details when
  Site Debug is on.
- **Do not prune the thumbnail cache by age.** There is no reliable signal for "no longer used": `atime` is
  usually not recorded (`noatime` is the Linux default on SSDs), and an old thumbnail may still be in heavy
  use.

**Why:** the operator's rulings on the 2026-09 security audit (`security.md`): L3 invalid, L4 not an issue,
L7's pruning declined, L1 keeps raw errors for Super Users with Site Debug on.

**How to apply:** before reporting or fixing a security issue, check whether it needs a Super User or a
hostile storage service/CDN to exploit. If so, it is not a finding. See also
[core-adapter-parity.md](core-adapter-parity.md).
