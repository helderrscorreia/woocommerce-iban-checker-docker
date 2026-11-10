<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

function timestamp(): string
{
    return gmdate('c');
}

function run_watchdog(array $config): never
{
    log_line('info', 'watchdog starting', [
        'site' => $config['site'],
        'option' => $config['option'],
        'interval_seconds' => $config['interval'],
        'auto_restore' => $config['auto_restore'],
        'webhook' => $config['webhook'] !== '',
        'email' => $config['smtp']['enabled'],
    ]);

    // Fail fast on a broken baseline: never run blind.
    load_baseline($config['baseline_file']);

    $state = load_state($config['state_file']);
    $pdo = null;
    $failures = 0;

    while (true) {
        $started = microtime(true);
        try {
            // Re-read each cycle so baseline edits apply without a restart.
            $baseline = load_baseline($config['baseline_file']);
            $pdo ??= connect_db($config['db']);
            $raw = fetch_option($pdo, $config['table_prefix'], $config['option']);

            $findings = [];
            $restored = false;

            if ($raw === null) {
                $findings = [['type' => 'missing_option']];
            } else {
                try {
                    $findings = compare_accounts($baseline, parse_option_value($raw));
                } catch (Throwable $error) {
                    $findings = [['type' => 'unreadable_option', 'error' => $error->getMessage()]];
                }
            }

            if ($findings !== [] && $config['auto_restore']) {
                try {
                    restore_option(
                        $pdo,
                        $config['table_prefix'],
                        $config['option'],
                        serialize_accounts($baseline)
                    );
                    $rawAfter = fetch_option($pdo, $config['table_prefix'], $config['option']);
                    $afterFindings = $rawAfter === null
                        ? [['type' => 'missing_option']]
                        : compare_accounts($baseline, parse_option_value($rawAfter));
                    if ($afterFindings === []) {
                        $restored = true;
                        log_line('warning', 'trusted baseline written back and verified');
                    } else {
                        $findings[] = ['type' => 'restore_failed', 'error' => 'option still differs after write'];
                    }
                } catch (Throwable $error) {
                    $findings[] = ['type' => 'restore_failed', 'error' => $error->getMessage()];
                }
            }

            $failures = 0;
            if ($findings !== []) {
                handle_tamper($config, $state, $findings, $restored);
            } else {
                handle_ok($config, $state);
            }

            @file_put_contents($config['heartbeat_file'], timestamp());
        } catch (Throwable $error) {
            $pdo = null;
            $failures++;
            log_line('error', 'check failed', [
                'failures' => $failures,
                'error' => get_class($error) . ': ' . $error->getMessage(),
            ]);
            if ($failures >= 3) {
                try {
                    handle_check_error($config, $state, $error, $failures);
                } catch (Throwable $alertError) {
                    log_line('error', 'could not record/alert check failure', ['error' => $alertError->getMessage()]);
                }
            }
        }

        $elapsed = microtime(true) - $started;
        $sleep = $failures > 1
            ? min(600, $config['interval'] * $failures)
            : $config['interval'];
        sleep((int) max(1, $sleep - $elapsed));
    }
}

try {
    run_watchdog(load_config());
} catch (Throwable $error) {
    log_line('error', 'watchdog cannot start', [
        'error' => get_class($error) . ': ' . $error->getMessage(),
    ]);
    exit(1);
}
