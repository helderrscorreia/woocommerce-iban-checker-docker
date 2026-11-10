<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

try {
    $config = load_config();
    $pdo = connect_db($config['db']);
    $raw = fetch_option($pdo, $config['table_prefix'], $config['option']);
    if ($raw === null) {
        throw new RuntimeException("option '{$config['option']}' not found in {$config['table_prefix']}options");
    }

    $accounts = array_map('canonical_account', parse_option_value($raw));
    echo json_encode(
        $accounts,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'export failed: ' . $error->getMessage() . "\n");
    exit(1);
}
