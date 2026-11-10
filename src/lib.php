<?php
declare(strict_types=1);

const IDENTITY_FIELDS  = ['iban', 'bic', 'account_number', 'sort_code'];
const ACCOUNT_FIELDS   = ['account_name', 'account_number', 'sort_code', 'bank_name', 'iban', 'bic'];
const PHP_OPTION_LIMIT = 10; // unserialize max_depth; accounts are flat arrays

function log_line(string $level, string $msg, array $context = []): void
{
    $line = ['time' => gmdate('c'), 'level' => $level, 'msg' => $msg] + $context;
    fwrite(STDERR, json_encode($line, JSON_UNESCAPED_SLASHES) . "\n");
}

function env_value(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function bool_env(string $key, bool $default = false): bool
{
    $value = env_value($key);
    return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOL);
}

function load_config(): array
{
    $required = static function (string $key): string {
        $value = env_value($key);
        if ($value === null) {
            throw new RuntimeException("$key is required");
        }
        return $value;
    };

    $prefix = env_value('DB_TABLE_PREFIX', 'wp_');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $prefix)) {
        throw new RuntimeException('DB_TABLE_PREFIX may only contain letters, digits and underscores');
    }

    $option = env_value('WATCH_OPTION', 'woocommerce_bacs_accounts');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $option)) {
        throw new RuntimeException('WATCH_OPTION may only contain letters, digits and underscores');
    }

    $webhook = (string) env_value('WEBHOOK_URL', '');
    if ($webhook !== '' && !filter_var($webhook, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('WEBHOOK_URL is not a valid URL');
    }

    $smtp = ['enabled' => false, 'from' => '', 'to' => []];
    $smtpHost = env_value('SMTP_HOST');
    if ($smtpHost !== null) {
        $encryption = strtolower((string) env_value('SMTP_ENCRYPTION', 'starttls'));
        if (!in_array($encryption, ['starttls', 'ssl', 'none'], true)) {
            throw new RuntimeException('SMTP_ENCRYPTION must be starttls, ssl or none');
        }
        if (env_value('SMTP_USER') !== null && env_value('SMTP_PASSWORD') === null) {
            throw new RuntimeException('SMTP_PASSWORD is required when SMTP_USER is set');
        }
        $recipients = array_values(array_filter(array_map('trim', explode(',', $required('ALERT_EMAIL_TO')))));
        if ($recipients === []) {
            throw new RuntimeException('ALERT_EMAIL_TO must contain at least one address');
        }
        $smtp = ['enabled' => true, 'from' => $required('SMTP_FROM'), 'to' => $recipients];
    }

    $port = (int) env_value('DB_PORT', '3306');
    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('DB_PORT must be between 1 and 65535');
    }

    $interval = max(5, (int) env_value('CHECK_INTERVAL', '60'));

    $autoRestore = bool_env('AUTO_RESTORE');
    $expectedIbans = null;
    $rawIbans = env_value('EXPECTED_IBANS');
    if ($rawIbans !== null) {
        $expectedIbans = parse_expected_ibans($rawIbans);
        if ($autoRestore) {
            throw new RuntimeException('AUTO_RESTORE must be false when EXPECTED_IBANS is used');
        }
    }

    return [
        'db' => [
            'host' => $required('DB_HOST'),
            'port' => $port,
            'name' => $required('DB_NAME'),
            'user' => $required('DB_USER'),
            'password' => (string) env_value('DB_PASSWORD', ''),
        ],
        'table_prefix' => $prefix,
        'option' => $option,
        'site' => (string) env_value('SITE_NAME', gethostname() ?: 'woocommerce'),
        'expected_ibans' => $expectedIbans,
        'baseline_file' => (string) env_value('BASELINE_FILE', '/config/expected-accounts.json'),
        'state_file' => (string) env_value('STATE_FILE', '/data/state.json'),
        'heartbeat_file' => (string) env_value('HEARTBEAT_FILE', '/tmp/watchdog.heartbeat'),
        'interval' => $interval,
        'auto_restore' => $autoRestore,
        'webhook' => $webhook,
        'full_iban' => bool_env('ALERT_FULL_IBAN'),
        'remind_minutes' => max(0, (int) env_value('ALERT_REMIND_MINUTES', '60')),
        'smtp' => $smtp,
    ];
}

function normalize_account(array $row): array
{
    $clean = static fn (string $value): string => (string) preg_replace('/\s+/', '', strtoupper($value));

    return [
        'iban' => $clean((string) ($row['iban'] ?? '')),
        'bic' => $clean((string) ($row['bic'] ?? '')),
        'account_number' => (string) preg_replace('/[^0-9A-Z]/', '', strtoupper((string) ($row['account_number'] ?? ''))),
        'sort_code' => (string) preg_replace('/\D/', '', (string) ($row['sort_code'] ?? '')),
    ];
}

function canonical_account(array $row): array
{
    $account = [];
    foreach (ACCOUNT_FIELDS as $field) {
        $account[$field] = (string) ($row[$field] ?? '');
    }
    // Preserve extra fields such as country_code instead of dropping them on restore.
    foreach ($row as $field => $value) {
        if (!array_key_exists($field, $account)) {
            $account[$field] = $value;
        }
    }
    return $account;
}

function iban_valid(string $iban): bool
{
    $iban = (string) preg_replace('/\s+/', '', strtoupper($iban));
    if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{1,30}$/', $iban)) {
        return false;
    }

    $rearranged = substr($iban, 4) . substr($iban, 0, 4);
    $remainder = 0;
    foreach (str_split($rearranged) as $char) {
        $digits = ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        foreach (str_split($digits) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }
    }

    return $remainder === 1;
}

/**
 * Parses EXPECTED_IBANS (comma-separated, order matters) into normalized,
 * checksum-validated IBANs. Spaces and case are ignored.
 */
function parse_expected_ibans(string $raw): array
{
    $ibans = [];
    foreach (explode(',', $raw) as $entry) {
        $iban = (string) preg_replace('/\s+/', '', strtoupper($entry));
        if ($iban === '') {
            continue;
        }
        if (!iban_valid($iban)) {
            throw new RuntimeException("EXPECTED_IBANS contains an invalid IBAN: $iban");
        }
        $ibans[] = $iban;
    }

    if ($ibans === []) {
        throw new RuntimeException('EXPECTED_IBANS must contain at least one IBAN');
    }

    return $ibans;
}

/**
 * Loads and validates the trusted baseline. Returns the raw account rows so
 * they can be written back verbatim on restore.
 */
function load_baseline(string $path): array
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("cannot read baseline file: $path");
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || !array_is_list($data)) {
        throw new RuntimeException('baseline must be a JSON array of account objects');
    }
    if ($data === []) {
        throw new RuntimeException('baseline must contain at least one account');
    }

    foreach ($data as $index => $row) {
        if (!is_array($row)) {
            throw new RuntimeException("baseline account #" . ($index + 1) . ' is not an object');
        }
        foreach (ACCOUNT_FIELDS as $field) {
            if (!array_key_exists($field, $row) || !is_string($row[$field])) {
                throw new RuntimeException("baseline account #" . ($index + 1) . " needs a string field '$field'");
            }
        }

        $account = normalize_account($row);
        if ($account['iban'] !== '' && !iban_valid($account['iban'])) {
            throw new RuntimeException("baseline account #" . ($index + 1) . ' has an invalid IBAN checksum');
        }
        if ($account['iban'] === '' && ($account['account_number'] === '' || $account['sort_code'] === '')) {
            throw new RuntimeException("baseline account #" . ($index + 1) . ' needs an IBAN or an account number plus sort code');
        }
    }

    return $data;
}

function parse_option_value(string $raw): array
{
    $value = @unserialize($raw, ['allowed_classes' => false, 'max_depth' => PHP_OPTION_LIMIT]);
    if (!is_array($value)) {
        throw new RuntimeException('option value is not a PHP array');
    }

    $accounts = [];
    foreach ($value as $index => $row) {
        if (!is_array($row)) {
            throw new RuntimeException("option account #" . ((int) $index + 1) . ' is not an array');
        }
        $accounts[] = $row;
    }

    return $accounts;
}

function serialize_accounts(array $accounts): string
{
    return serialize(array_map('canonical_account', $accounts));
}

/**
 * Compares only the security-relevant fields. Account/bank names are labels
 * that admins may edit without turning payments fraudulent.
 */
function compare_accounts(array $baseline, array $actual): array
{
    $expected = array_map('normalize_account', $baseline);
    $found = array_map('normalize_account', $actual);
    $findings = [];

    if (count($expected) !== count($found)) {
        $findings[] = [
            'type' => 'count',
            'expected' => count($expected),
            'actual' => count($found),
        ];
    }

    $limit = min(count($expected), count($found));
    for ($index = 0; $index < $limit; $index++) {
        foreach (IDENTITY_FIELDS as $field) {
            if ($expected[$index][$field] !== $found[$index][$field]) {
                $findings[] = [
                    'type' => 'field',
                    'index' => $index,
                    'field' => $field,
                    'expected' => $expected[$index][$field],
                    'actual' => $found[$index][$field],
                ];
            }
        }
    }

    return $findings;
}

/**
 * IBAN-only comparison for EXPECTED_IBANS mode. Ignores labels, BIC, account
 * number, sort code and country code; order and account count still matter.
 */
function compare_ibans(array $expectedIbans, array $actual): array
{
    $found = array_map(
        static fn (array $row): string => normalize_account($row)['iban'],
        $actual
    );
    $findings = [];

    if (count($expectedIbans) !== count($found)) {
        $findings[] = [
            'type' => 'count',
            'expected' => count($expectedIbans),
            'actual' => count($found),
        ];
    }

    $limit = min(count($expectedIbans), count($found));
    for ($index = 0; $index < $limit; $index++) {
        if ($expectedIbans[$index] !== $found[$index]) {
            $findings[] = [
                'type' => 'field',
                'index' => $index,
                'field' => 'iban',
                'expected' => $expectedIbans[$index],
                'actual' => $found[$index],
            ];
        }
    }

    return $findings;
}

function mask_bank_value(string $value, bool $full): string
{
    if ($value === '') {
        return '(empty)';
    }
    return $full ? $value : '****' . substr($value, -4);
}

function describe_findings(array $findings, bool $full): array
{
    $lines = [];
    foreach ($findings as $finding) {
        switch ($finding['type']) {
            case 'count':
                $lines[] = "account count changed: expected {$finding['expected']}, actual {$finding['actual']}";
                break;
            case 'field':
                $expected = mask_bank_value($finding['expected'], $full);
                $actual = mask_bank_value($finding['actual'], $full);
                $lines[] = 'account #' . ($finding['index'] + 1) . " {$finding['field']} changed: expected $expected, actual $actual";
                break;
            case 'missing_option':
                $lines[] = 'payment account option is missing from wp_options';
                break;
            case 'unreadable_option':
                $lines[] = 'payment account option cannot be parsed: ' . $finding['error'];
                break;
            case 'restore_failed':
                $lines[] = 'restore failed: ' . $finding['error'];
                break;
            default:
                $lines[] = 'unknown finding: ' . json_encode($finding, JSON_UNESCAPED_SLASHES);
        }
    }
    return $lines;
}

function connect_db(array $db): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $db['host'],
        $db['port'],
        $db['name']
    );

    return new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function fetch_option(PDO $pdo, string $prefix, string $option): ?string
{
    $statement = $pdo->prepare("SELECT option_value FROM `{$prefix}options` WHERE option_name = ? LIMIT 1");
    $statement->execute([$option]);
    $value = $statement->fetchColumn();
    return $value === false ? null : (string) $value;
}

function restore_option(PDO $pdo, string $prefix, string $option, string $serialized): void
{
    $statement = $pdo->prepare(
        "INSERT INTO `{$prefix}options` (option_name, option_value, autoload) VALUES (?, ?, 'yes') "
        . 'ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)'
    );
    $statement->execute([$option, $serialized]);
}

function load_state(string $path): array
{
    $state = ['status' => 'ok', 'fingerprint' => '', 'alerted' => false, 'last_alert_at' => 0];
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return $state;
    }
    $stored = json_decode($raw, true);
    if (is_array($stored)) {
        $state = array_merge($state, $stored);
    }
    return $state;
}

function save_state(string $path, array $state): void
{
    $directory = dirname($path);
    if (!is_dir($directory)) {
        @mkdir($directory, 0755, true);
    }
    if (@file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX) === false) {
        log_line('error', 'cannot write state file', ['path' => $path]);
    }
}

function is_discord_webhook(string $url): bool
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    return $host === 'discord.com' || $host === 'discordapp.com'
        || str_ends_with($host, '.discord.com') || str_ends_with($host, '.discordapp.com');
}

/**
 * Discord renders a generic JSON payload as raw text, so send a rich embed.
 */
function discord_payload(array $payload): array
{
    [$color, $emoji, $title] = [
        'tampered' => [0xE01F1F, '🚨', 'Bank details changed'],
        'recovered' => [0x1F9D55, '✅', 'Bank details recovered'],
        'error' => [0xE0A800, '⚠️', 'Watchdog check failing'],
    ][$payload['status']] ?? [0x6B7280, 'ℹ️', ucfirst((string) $payload['status'])];

    $embed = [
        'title' => "$emoji $title",
        'description' => substr((string) $payload['text'], 0, 4096),
        'color' => $color,
        'fields' => [
            ['name' => 'Site', 'value' => (string) $payload['site'], 'inline' => true],
            ['name' => 'Detected', 'value' => (string) $payload['detected_at'], 'inline' => true],
        ],
        'footer' => ['text' => 'woocommerce-iban-watchdog'],
        'timestamp' => $payload['detected_at'],
    ];

    $details = substr(implode("\n", array_map(
        static fn ($line): string => '• ' . $line,
        $payload['findings']
    )), 0, 1024);
    if ($details !== '') {
        $embed['fields'][] = ['name' => 'Details', 'value' => $details, 'inline' => false];
    }
    if (($payload['restored'] ?? false) === true) {
        $embed['fields'][] = ['name' => 'Auto-restore', 'value' => 'Trusted baseline written back', 'inline' => false];
    }

    return [
        'username' => 'IBAN Watchdog',
        'allowed_mentions' => ['parse' => []], // never ping @everyone from a webhook
        'embeds' => [$embed],
    ];
}

function http_post_json(string $url, array $payload): void
{
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'timeout' => 10,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        throw new RuntimeException('webhook request failed');
    }

    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches)) {
            $status = (int) $matches[1];
        }
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("webhook returned HTTP $status");
    }
}

function send_email(array $smtp, string $subject, string $body): void
{
    $headers = [
        'From: ' . $smtp['from'],
        'To: ' . implode(', ', $smtp['to']),
        'Subject: ' . preg_replace('/[\r\n]+/', ' ', $subject),
        'Date: ' . date(DATE_RFC2822),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    $process = proc_open(
        ['msmtp', '-t', '--read-envelope-from'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('cannot start msmtp');
    }

    fwrite($pipes[0], implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n");
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        throw new RuntimeException('msmtp failed: ' . trim($stdout . ' ' . $stderr));
    }
}

/**
 * Sends the alert through every configured channel. Returns true when at
 * least one channel accepted it, or when no channel is configured at all.
 */
function notify(array $config, string $kind, string $text, array $findings, array $meta = []): bool
{
    $payload = [
        'service' => 'woocommerce-iban-watchdog',
        'status' => $kind,
        'site' => $config['site'],
        'text' => $text,
        'detected_at' => gmdate('c'),
        'restored' => (bool) ($meta['restored'] ?? false),
        'findings' => $findings,
    ];

    $configured = 0;
    $delivered = 0;

    if ($config['webhook'] !== '') {
        $configured++;
        try {
            http_post_json(
                $config['webhook'],
                is_discord_webhook($config['webhook']) ? discord_payload($payload) : $payload
            );
            $delivered++;
            log_line('info', 'webhook alert sent', ['kind' => $kind]);
        } catch (Throwable $error) {
            log_line('error', 'webhook alert failed', ['kind' => $kind, 'error' => $error->getMessage()]);
        }
    }

    if ($config['smtp']['enabled']) {
        $configured++;
        try {
            $subject = '[IBAN WATCHDOG] ' . ucfirst($kind) . ' on ' . $config['site'];
            $body = $text . "\n\n" . implode("\n", $findings) . "\n";
            send_email($config['smtp'], $subject, $body);
            $delivered++;
            log_line('info', 'email alert sent', ['kind' => $kind]);
        } catch (Throwable $error) {
            log_line('error', 'email alert failed', ['kind' => $kind, 'error' => $error->getMessage()]);
        }
    }

    if ($configured === 0) {
        log_line('warning', 'no alert channel configured; finding is only in these logs');
        return true;
    }

    return $delivered > 0;
}

function handle_tamper(array $config, array &$state, array $findings, bool $restored): void
{
    $fingerprint = hash('sha256', json_encode($findings, JSON_UNESCAPED_SLASHES));
    $now = time();
    $changed = $state['status'] !== 'tampered' || $state['fingerprint'] !== $fingerprint;
    $repeat = $config['remind_minutes'] > 0
        && ($now - (int) $state['last_alert_at']) >= $config['remind_minutes'] * 60;
    $shouldAlert = $changed || !$state['alerted'] || $repeat;

    $lines = describe_findings($findings, $config['full_iban']);
    log_line('warning', 'BACS account tampering detected', [
        'restored' => $restored,
        'findings' => $lines,
    ]);

    if ($shouldAlert) {
        $text = 'Offline bank-transfer details changed on ' . $config["site"]
            . ($restored ? ' (trusted baseline restored)' : '');
        $delivered = notify($config, 'tampered', $text, $lines, ['restored' => $restored]);
        $state['alerted'] = $delivered;
        if ($delivered) {
            $state['last_alert_at'] = $now;
        }
    }

    $state['status'] = 'tampered';
    $state['fingerprint'] = $fingerprint;
    save_state($config['state_file'], $state);
}

function handle_ok(array $config, array &$state): void
{
    if ($state['status'] !== 'ok') {
        $text = 'Offline bank-transfer details on ' . $config['site'] . ' match the trusted baseline again';
        $delivered = notify($config, 'recovered', $text, ['bank details match the baseline']);
        if (!$delivered) {
            // Keep the old status so the recovery alert is retried next cycle.
            save_state($config['state_file'], $state);
            return;
        }
    }

    $state = ['status' => 'ok', 'fingerprint' => '', 'alerted' => false, 'last_alert_at' => (int) $state['last_alert_at']];
    save_state($config['state_file'], $state);
}

function handle_check_error(array $config, array &$state, Throwable $error, int $failures): void
{
    $fingerprint = hash('sha256', get_class($error) . ':' . $error->getMessage());
    $now = time();
    $changed = $state['status'] !== 'error' || $state['fingerprint'] !== $fingerprint;
    $repeat = $config['remind_minutes'] > 0
        && ($now - (int) $state['last_alert_at']) >= $config['remind_minutes'] * 60;
    $shouldAlert = $changed || !$state['alerted'] || $repeat;

    if ($shouldAlert) {
        $text = 'Cannot check offline bank-transfer details on ' . $config['site']
            . " ($failures consecutive failures): " . $error->getMessage();
        $delivered = notify($config, 'error', $text, [$error->getMessage()]);
        $state['alerted'] = $delivered;
        if ($delivered) {
            $state['last_alert_at'] = $now;
        }
    }

    $state['status'] = 'error';
    $state['fingerprint'] = $fingerprint;
    save_state($config['state_file'], $state);
}
