## Summary

<!-- What changes, and which failure mode or threat does it address? -->

## Testing

<!-- Commands run, and what you verified. Include DB/webhook tests if applicable. -->

- [ ] `php tests/selfcheck.php` passes
- [ ] `php -l` passes on changed files
- [ ] `sh -n docker-entrypoint.sh` passes
- [ ] README and `.env.example` updated for configuration changes
- [ ] No real credentials, IBANs, or customer data included

## Checklist

- [ ] Change is focused and does not add unused options or dependencies
- [ ] Auto-restore behavior was considered (validation, verification, failure alerts)
- [ ] New non-trivial logic has a check in `tests/selfcheck.php`
