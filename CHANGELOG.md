# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-10

### Added

- Docker Compose watchdog for `woocommerce_bacs_accounts`
- IBAN checksum validation and identity-field comparison (IBAN, BIC, account
  number, sort code, account count/order)
- Webhook and SMTP email alerts with masked bank details, deduplication, and
  recovery notifications
- Optional auto-restore with post-write verification for changed, missing, or
  unparsable option values
- Read-only JSON baseline, live reload, and `export-baseline.php` helper
- Healthcheck, retry/backoff on database failures, and assert-based self-checks
