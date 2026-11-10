# WooCommerce IBAN Watchdog

Small Docker Compose service that watches the bank details of WooCommerce
offline bank-transfer payments (BACS) and alerts when they change — the usual
signature of a skimmer or compromised admin account redirecting customer
payments to an attacker's IBAN.

It polls the remote WordPress database every 60 seconds, compares
`wp_options → woocommerce_bacs_accounts` with a trusted baseline, and sends a
webhook and/or email alert on any change. Optional auto-restore writes the
trusted accounts back and verifies the result.

## How it works

- Reads the option with a prepared `SELECT` and parses the PHP-serialized value
  (no plugin on the WordPress side, no web server exposure).
- Compares only security-relevant fields: `iban`, `bic`, `account_number`,
  `sort_code`, plus the number and order of accounts. `account_name` and
  `bank_name` are labels and may be edited without an alert.
- Alerts on: changed field, added/removed/reordered account, missing option,
  unparsable option value. IBANs in the baseline are checksum-validated.
- Alerts are deduplicated: one alert per new finding, an optional reminder
  (`ALERT_REMIND_MINUTES`), plus a recovery message. State survives restarts.
- Database errors never trigger a restore. After three consecutive failures an
  error alert is sent and the container reports unhealthy.

## Quick start

```bash
cp .env.example .env
cp config/expected-accounts.example.json config/expected-accounts.json
$EDITOR .env                      # database credentials and alert channels
docker compose build

# Capture the current live accounts as the trusted baseline, then review it:
docker compose run --rm --entrypoint php iban-watchdog /app/src/export-baseline.php \
  > /tmp/expected-accounts.json
mv /tmp/expected-accounts.json config/expected-accounts.json
cat config/expected-accounts.json

# Start with AUTO_RESTORE=false and watch the logs:
docker compose up -d
docker compose logs -f
```

Change a test account in WooCommerce (or the DB) to see the alert. When the
baseline and alerting are trusted, set `AUTO_RESTORE=true`.

> The real `config/expected-accounts.json` is gitignored. Create it before the
> first `docker compose up`; bind-mounting a missing file makes Docker create a
> directory in its place.

## Alert channels

Set at least one. With none configured, findings only appear in `docker logs`.

- **Webhook**: `WEBHOOK_URL` receives a JSON POST
  (`service`, `status`, `site`, `text`, `detected_at`, `restored`, `findings`).
  Works with Slack, Discord, ntfy, Gotify or a custom endpoint.
- **Email**: set `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION`
  (`starttls`/`ssl`/`none`), `SMTP_FROM`, `ALERT_EMAIL_TO` and, if your provider
  needs it, `SMTP_USER`/`SMTP_PASSWORD`. The entrypoint writes `/etc/msmtprc`;
  PHP calls `msmtp -t`.

Bank details are masked to the last four characters by default. Set
`ALERT_FULL_IBAN=true` only if your alert destination is trusted.

## Database permissions

Use a dedicated user, not the WordPress admin user.

```sql
CREATE USER 'iban_watchdog'@'%' IDENTIFIED BY 'strong-password';
-- alert-only mode:
GRANT SELECT ON wordpress.wp_options TO 'iban_watchdog'@'%';
-- add these only for AUTO_RESTORE=true:
GRANT UPDATE, INSERT ON wordpress.wp_options TO 'iban_watchdog'@'%';
```

Nothing outside `wp_options` is read or written. If MySQL only listens on the
Docker host, uncomment `extra_hosts` in `docker-compose.yml` and set
`DB_HOST=host.docker.internal`.

## Auto-restore

With `AUTO_RESTORE=true` the watchdog rewrites the full baseline row (including
labels) after any mismatch — changed fields, added/removed accounts, a deleted
option row, or an unparsable option value — and re-reads the option to verify.
The alert says whether the restore succeeded.

- Legitimate account changes must be made in the baseline file first.
- The baseline is re-read every cycle, so edits apply without a restart.
- If the site uses a persistent object cache (Redis/Memcached), flush it after a
  restore so WordPress stops serving the cached option.
- If the option row was deleted, restore needs the `INSERT` grant.

## Operations

```bash
docker compose ps                 # health status
docker compose logs -f            # JSON logs
docker compose restart
```

The container is healthy while a successful check happened within
`3 × CHECK_INTERVAL`. Use `ALERT_REMIND_MINUTES=0` to alert only on state
changes (recovery and new findings always alert immediately).

## Testing

```bash
php tests/selfcheck.php
# or inside the built image:
docker build -t woocommerce-iban-watchdog .
docker run --rm --entrypoint php woocommerce-iban-watchdog /app/tests/selfcheck.php
```

## Known limits

- **Detection window**: a fraudulent IBAN can receive payments for up to one
  polling interval (60s by default) before it is detected and reverted. Payments
  already made to a fraudulent account are not recovered.
- **Not prevention**: an attacker with database, admin or host access can change
  the baseline, disable the container, or delete alerts. Restrict the watchdog
  DB user and keep the baseline file out of the web root and version control.
- **BACS only**: custom gateways that store bank details elsewhere are not
  checked. Point `WATCH_OPTION` at their option only if the value has the same
  row structure.
- **Ordering matters**: reordering accounts in WooCommerce triggers an alert
  (the baseline is index-based).
- **Direct DB writes bypass WordPress caches**; see the auto-restore note.
- This is a watchdog, not an audit of past orders. Check recent orders manually
  whenever an alert fires.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for setup, testing, and pull request
guidelines. Please follow the [Code of Conduct](CODE_OF_CONDUCT.md).

## Security

Report vulnerabilities privately as described in [SECURITY.md](SECURITY.md).
Never open a public issue containing real bank details or credentials.

## License

[MIT](LICENSE)

