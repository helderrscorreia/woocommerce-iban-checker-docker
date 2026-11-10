# Contributing

Thanks for taking the time to improve the watchdog. Keep changes focused: this
project does one thing (detect and optionally revert BACS account changes) and
should stay small enough to audit.

## Development setup

Requirements: Docker (and optionally PHP 8.3+ for local unit tests).

```bash
cp .env.example .env
cp config/expected-accounts.example.json config/expected-accounts.json

php tests/selfcheck.php                       # unit checks, no dependencies
docker build -t woocommerce-iban-watchdog .
docker run --rm --entrypoint php woocommerce-iban-watchdog /app/tests/selfcheck.php
```

For an end-to-end run, point `.env` at a disposable WordPress database and set
`AUTO_RESTORE=false` until the baseline is verified.

## Guidelines

- PHP 8.3, no Composer dependencies. Prefer the standard library and the
  existing helpers in `src/lib.php`.
- New non-trivial logic needs a check in `tests/selfcheck.php`.
- Keep the container single-purpose and free of ports or a web UI.
- Never commit real `.env` files, baselines, IBANs, credentials or logs. Use
  the example fixtures.
- Update `README.md` and `.env.example` when configuration changes.
- Run `php -l` on changed PHP files and `sh -n docker-entrypoint.sh` before
  opening a pull request.

## Pull requests

Describe the threat or failure mode being fixed, the behavior change, and how
you tested it. Keep database writes (auto-restore) conservative: validate input,
verify the write, and never restore from an unvalidated baseline.

## Security

Do not report vulnerabilities in public issues. See [SECURITY.md](SECURITY.md).
