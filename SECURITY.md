# Security Policy

This project detects tampering with WooCommerce offline bank-transfer details.
Treat reports about bypasses, false negatives, credential exposure, or unsafe
restore behavior as security issues.

## Supported versions

| Version | Supported |
| ------- | --------- |
| 0.1.x   | Yes       |

## Reporting a vulnerability

Do **not** open a public issue, and do not include real IBANs, customer data,
database credentials, or SMTP passwords in any report.

Use GitHub's private reporting flow on this repository:
**Security → Report a vulnerability**. If that is unavailable, contact a
maintainer privately through their GitHub profile.

Please include:

- Affected version or commit
- Deployment mode (remote MySQL, tunnel, object cache)
- A minimal reproduction with placeholder accounts
- Impact and which guarantee is broken (detection, alerting, restore, secrecy)

You can expect an acknowledgement as soon as reasonably possible and a status
update after triage. This is a volunteer project, so no fixed response time is
promised.

## Operational security notes

- Use the least-privilege database user described in the README.
- Keep `.env`, the real `config/expected-accounts.json`, and database dumps out
  of version control and off the web root.
- The watchdog cannot defend against an attacker with host, database admin, or
  WordPress admin access; it is a detection and recovery control, not a
  prevention boundary.
- Alerts may contain bank details. Masking is enabled by default; avoid
  `ALERT_FULL_IBAN=true` on shared channels.
