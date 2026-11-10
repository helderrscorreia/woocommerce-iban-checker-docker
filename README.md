<div align="center">

# 🐕‍🦺 WooCommerce IBAN Watchdog

**Catch tampered bank details before customers pay the wrong account.**

[![Docker](https://img.shields.io/badge/docker-compose-2496ED?logo=docker&logoColor=white)](docker-compose.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![PHP](https://img.shields.io/badge/php-8.2%2B-777BB4?logo=php&logoColor=white)](Dockerfile)
[![Portainer](https://img.shields.io/badge/Portainer-ready-13bef9?logo=portainer&logoColor=white)](docker-compose.yml)

A tiny Docker Compose service that watches the bank details of WooCommerce
offline bank-transfer payments (BACS) and alerts the moment they change — the
usual signature of a skimmer or compromised admin account redirecting customer
payments to an attacker's IBAN.

[Quick start](#-quick-start) · [Alert channels](#-alert-channels) · [Configuration](#-configuration) · [Known limits](#-known-limits)

</div>

---

## 📖 What it does

- Polls the remote WordPress database every **60 seconds** (configurable).
- Compares `wp_options → woocommerce_bacs_accounts` against a **trusted baseline**.
- Sends a **webhook** and/or **email** alert on any change.
- Optionally **auto-restores** the trusted accounts and verifies the result.

> 🎯 It needs no plugin on the WordPress side and exposes no web server.

---

## 🔍 What gets compared

| Field | Checked | Notes |
| --- | :---: | --- |
| `iban` | ✅ | Checksum-validated in the baseline |
| `bic` | ✅ | |
| `account_number` | ✅ | |
| `sort_code` | ✅ | |
| Account count & order | ✅ | Index-based, reordering triggers an alert |
| `account_name` | ❌ | Label — safe to edit without an alert |
| `bank_name` | ❌ | Label — safe to edit without an alert |

### Alerts fire when

- a security-relevant field changes,
- an account is added, removed or reordered,
- the option row is missing,
- the option value can't be parsed.

### Alerts are deduplicated

One alert per new finding, an optional reminder (`ALERT_REMIND_MINUTES`), plus a
recovery message when the site goes back to normal. State survives restarts.

### Failures never trigger a restore

After three consecutive database failures an error alert is sent and the
container reports **unhealthy**.

---

## 🚀 Quick start

```bash
cp .env.example .env
cp config/expected-accounts.example.json config/expected-accounts.json
$EDITOR .env                      # database credentials and alert channels
docker compose build
```

Capture the current live accounts as the trusted baseline, then **review it**:

```bash
docker compose run --rm --entrypoint php iban-watchdog /app/src/export-baseline.php \
  > /tmp/expected-accounts.json
mv /tmp/expected-accounts.json config/expected-accounts.json
cat config/expected-accounts.json
```

Start with `AUTO_RESTORE=false` and watch the logs:

```bash
docker compose up -d
docker compose logs -f
```

Change a test account in WooCommerce (or the DB) to see the alert. Once the
baseline and alerting are trusted, set `AUTO_RESTORE=true`.

> ⚠️ The real `config/expected-accounts.json` is **gitignored**. Create it before
> the first `docker compose up` — bind-mounting a missing file makes Docker
> create a directory in its place.

---

## 🔔 Alert channels

Set **at least one**. With none configured, findings only appear in `docker logs`.

| Channel | Variables | Notes |
| --- | --- | --- |
| **Webhook** | `WEBHOOK_URL` | Generic JSON POST — Slack, Discord, ntfy, Gotify or a custom endpoint |
| **Email** | `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION`, `SMTP_FROM`, `ALERT_EMAIL_TO`, `SMTP_USER`, `SMTP_PASSWORD` | The entrypoint writes `/etc/msmtprc`; PHP calls `msmtp -t` |

### Webhook payload

```json
{
  "service": "woocommerce-iban-watchdog",
  "status": "tampered",
  "site": "shop.example.com",
  "text": "IBAN changed for account 1",
  "detected_at": "2026-01-01T12:00:00+00:00",
  "restored": false,
  "findings": []
}
```

### SMTP encryption modes

| Value | Use with |
| --- | --- |
| `starttls` | port 587 (default) |
| `ssl` | implicit TLS, port 465 |
| `none` | plain SMTP — trusted local relays only |

> 🛡️ Bank details are **masked to the last four characters** by default. Set
> `ALERT_FULL_IBAN=true` only if your alert destination is trusted.

---

## 🎯 IBAN-only mode

If you only need to watch the account IBAN(s), skip the JSON baseline:

```env
EXPECTED_IBANS=PT50000201231234567890154
AUTO_RESTORE=false
```

- Comma-separate multiple IBANs, in the same order as the WooCommerce accounts.
- Spaces and lowercase are accepted and normalized.
- Only IBANs are compared; labels, BIC, account number, sort code and
  `country_code` are ignored.
- Account count and order still matter.
- Takes precedence over `BASELINE_FILE`.
- `AUTO_RESTORE` must remain `false` — the watchdog refuses to start if both are set.

Use the file baseline instead when you also want BIC / account-number / sort-code
checks or automatic recovery.

---

## ⚙️ Configuration

All options live in `.env` (see [`.env.example`](.env.example)).

| Variable | Default | Description |
| --- | --- | --- |
| `DB_HOST` | — | MySQL host (`host.docker.internal` for a local server) |
| `DB_PORT` | `3306` | MySQL port |
| `DB_NAME` | — | Database name |
| `DB_USER` | — | Dedicated watchdog DB user |
| `DB_PASSWORD` | — | Password for that user |
| `DB_TABLE_PREFIX` | `wp_` | WordPress `$table_prefix` |
| `SITE_NAME` | hostname | Friendly name in alerts |
| `WATCH_OPTION` | `woocommerce_bacs_accounts` | `wp_options` row to watch |
| `EXPECTED_IBANS` | — | IBAN-only mode (comma-separated) |
| `BASELINE_FILE` | `/config/expected-accounts.json` | JSON baseline path (file mode) |
| `CONFIG_DIR` | `./config` | Host directory bind-mounted to `/config` |
| `CHECK_INTERVAL` | `60` | Seconds between checks |
| `AUTO_RESTORE` | `false` | Write the baseline back on mismatch (file mode only) |
| `WEBHOOK_URL` | — | Webhook endpoint |
| `ALERT_FULL_IBAN` | `false` | Disable IBAN masking |
| `ALERT_REMIND_MINUTES` | `60` | Re-alert interval for unchanged findings (`0` = only on change) |
| `SMTP_*` | — | SMTP host, port, encryption, user, password, from |
| `ALERT_EMAIL_TO` | — | Comma-separated recipients |
| `STATE_FILE` | `/data/state.json` | Dedupe state (named volume) |
| `HEARTBEAT_FILE` | `/tmp/watchdog.heartbeat` | Updated after every successful cycle |

---

## 🐳 Portainer

Deploy as a stack from this Git repository (Compose path `docker-compose.yml`) and
set the configuration in Portainer's environment-variable UI. Every option from
`.env.example` is mapped through `environment:` in the compose file, so **no
`.env` file is required** in the checkout.

- **IBAN-only mode** — set `EXPECTED_IBANS` and leave `AUTO_RESTORE=false`.
- **File mode** — place `expected-accounts.json` on the Docker host and set
  `CONFIG_DIR` to its directory (defaults to `./config`).
- A private Git repository needs its own Portainer credential or deploy key.
- Portainer on Docker Standalone can build the image from the checkout. On
  Swarm, `build` is unsupported — publish or pre-build the image and change
  `build: .` to an `image:` reference.
- Portainer environment variables are stored in Portainer's database and are
  visible to its administrators, just like a `.env` file.

---

## 🔐 Database permissions

Use a **dedicated user**, not the WordPress admin user.

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

---

## ♻️ Auto-restore

Auto-restore is available in **file-baseline mode only** (not with
`EXPECTED_IBANS`). With `AUTO_RESTORE=true` the watchdog rewrites the full
baseline row (including labels) after any mismatch — changed fields,
added/removed accounts, a deleted option row, or an unparsable option value —
and re-reads the option to verify. The alert says whether the restore
succeeded.

- Legitimate account changes must be made in the **baseline file first**.
- The baseline is re-read every cycle, so edits apply without a restart.
- If the site uses a persistent object cache (Redis/Memcached), flush it after a
  restore so WordPress stops serving the cached option.
- If the option row was deleted, restore needs the `INSERT` grant.

---

## 🛠️ Operations

```bash
docker compose ps                 # health status
docker compose logs -f            # JSON logs
docker compose restart
```

The container is **healthy** while a successful check happened within
`3 × CHECK_INTERVAL`. Use `ALERT_REMIND_MINUTES=0` to alert only on state
changes (recovery and new findings always alert immediately).

---

## 🧪 Testing

```bash
php tests/selfcheck.php

# or inside the built image:
docker build -t woocommerce-iban-watchdog .
docker run --rm --entrypoint php woocommerce-iban-watchdog /app/tests/selfcheck.php
```

---

## ⚠️ Known limits

- **Detection window** — a fraudulent IBAN can receive payments for up to one
  polling interval (60 s by default) before it is detected and reverted.
  Payments already made to a fraudulent account are not recovered.
- **Not prevention** — an attacker with database, admin or host access can change
  the baseline, disable the container, or delete alerts. Restrict the watchdog
  DB user and keep the baseline file out of the web root and version control.
- **BACS only** — custom gateways that store bank details elsewhere are not
  checked. Point `WATCH_OPTION` at their option only if the value has the same
  row structure.
- **Ordering matters** — reordering accounts in WooCommerce triggers an alert
  (the baseline is index-based).
- **Direct DB writes bypass WordPress caches** — see the auto-restore note.
- This is a watchdog, not an audit of past orders. Check recent orders manually
  whenever an alert fires.

---

## 🤝 Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for setup, testing, and pull request
guidelines. Please follow the [Code of Conduct](CODE_OF_CONDUCT.md).

## 🛡️ Security

Report vulnerabilities privately as described in [SECURITY.md](SECURITY.md).
Never open a public issue containing real bank details or credentials.

## 📄 License

[MIT](LICENSE)

---

<div align="center">
<sub>Made for shops that would rather not learn about skimming from a customer.</sub>
</div>
