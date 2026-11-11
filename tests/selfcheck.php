<?php
declare(strict_types=1);

require __DIR__ . '/../src/lib.php';

$failed = 0;
function check(bool $condition, string $message): void
{
    global $failed;
    if ($condition) {
        echo "ok - $message\n";
        return;
    }
    $failed++;
    echo "FAIL - $message\n";
}

function baseline_fixture(): array
{
    return [[
        'account_name' => 'Example Shop',
        'account_number' => '12345678',
        'sort_code' => '12-34-56',
        'bank_name' => 'Example Bank',
        'iban' => 'DE89370400440532013000',
        'bic' => 'COBADEFFXXX',
    ]];
}

function write_temp_json(array $data): string
{
    $path = tempnam(sys_get_temp_dir(), 'iban-baseline-');
    file_put_contents($path, json_encode($data));
    register_shutdown_function(static fn () => @unlink($path));
    return $path;
}

// IBAN validation
check(iban_valid('DE89370400440532013000'), 'valid DE IBAN');
check(iban_valid('gb82 west 1234 5698 7654 32'), 'valid GB IBAN with spaces and lowercase');
check(!iban_valid('DE89370400440532013001'), 'invalid IBAN checksum is rejected');
check(!iban_valid('not-an-iban'), 'garbage is rejected');
check(!iban_valid(''), 'empty IBAN is rejected');

// Normalisation
$normal = normalize_account(['iban' => ' de89 3704 0044 0532 0130 00 ', 'sort_code' => '12-34-56', 'account_number' => '12 34']);
check($normal['iban'] === 'DE89370400440532013000', 'IBAN normalisation strips spaces and upper-cases');
check($normal['sort_code'] === '123456', 'sort code normalisation keeps digits only');
check($normal['account_number'] === '1234', 'account number normalisation keeps alphanumerics only');

// Serialisation round-trip
$fixture = baseline_fixture();
$roundTrip = parse_option_value(serialize_accounts($fixture));
check($roundTrip === $fixture, 'serialize/parse round-trip preserves account rows');
check(serialize_accounts($fixture) === serialize($fixture), 'serialized format matches native PHP serialize');

$withCountry = $fixture;
$withCountry[0]['country_code'] = 'PT';
$restored = parse_option_value(serialize_accounts($withCountry));
check(($restored[0]['country_code'] ?? null) === 'PT', 'extra fields such as country_code survive restore');

// Comparison
$same = $fixture;
$same[0]['account_name'] = 'Renamed Shop';
$same[0]['bank_name'] = 'Other Bank';
check(compare_accounts($fixture, $same) === [], 'label-only changes do not alert');

$changed = $fixture;
$changed[0]['iban'] = 'GB82WEST12345698765432';
$findings = compare_accounts($fixture, $changed);
check(count($findings) === 1 && $findings[0]['field'] === 'iban', 'changed IBAN is reported');

$added = $fixture;
$added[] = $fixture[0];
$findings = compare_accounts($fixture, $added);
check(count($findings) === 1 && $findings[0]['type'] === 'count', 'added account is reported');

$formatChanged = $fixture;
$formatChanged[0]['sort_code'] = '123456';
check(compare_accounts($fixture, $formatChanged) === [], 'sort code formatting differences do not alert');

// Baseline loading
check(load_baseline(write_temp_json($fixture)) === $fixture, 'valid baseline loads');

$invalidIban = $fixture;
$invalidIban[0]['iban'] = 'DE89370400440532013001';
try {
    load_baseline(write_temp_json($invalidIban));
    check(false, 'baseline with invalid IBAN is rejected');
} catch (RuntimeException) {
    check(true, 'baseline with invalid IBAN is rejected');
}

$missingField = $fixture;
unset($missingField[0]['bic']);
try {
    load_baseline(write_temp_json($missingField));
    check(false, 'baseline with missing field is rejected');
} catch (RuntimeException) {
    check(true, 'baseline with missing field is rejected');
}

try {
    load_baseline(write_temp_json([]));
    check(false, 'empty baseline is rejected');
} catch (RuntimeException) {
    check(true, 'empty baseline is rejected');
}

// EXPECTED_IBANS mode
$expectedIbans = parse_expected_ibans(' pt50 0002 0123 1234 5678 9015 4 , DE89370400440532013000 ');
check($expectedIbans === ['PT50000201231234567890154', 'DE89370400440532013000'], 'EXPECTED_IBANS parses, normalizes and keeps order');
check(parse_expected_ibans('PT50 0002 0123 1234 5678 9015 4,,') === ['PT50000201231234567890154'], 'empty EXPECTED_IBANS entries are ignored');

try {
    parse_expected_ibans('PT50000201231234567890155');
    check(false, 'EXPECTED_IBANS with invalid checksum is rejected');
} catch (RuntimeException) {
    check(true, 'EXPECTED_IBANS with invalid checksum is rejected');
}

try {
    parse_expected_ibans(' , ');
    check(false, 'empty EXPECTED_IBANS is rejected');
} catch (RuntimeException) {
    check(true, 'empty EXPECTED_IBANS is rejected');
}

$ptAccount = [[
    'account_name' => 'Parábola do Lápis, LDA',
    'account_number' => '',
    'sort_code' => '',
    'bank_name' => 'MONTEPIO',
    'iban' => 'PT50 0002 0123 1234 5678 9015 4',
    'bic' => '',
    'country_code' => 'PT',
]];
check(compare_ibans(['PT50000201231234567890154'], $ptAccount) === [], 'matching IBANs do not alert');
$ptTampered = $ptAccount;
$ptTampered[0]['iban'] = 'GB82WEST12345698765432';
$findings = compare_ibans(['PT50000201231234567890154'], $ptTampered);
check(count($findings) === 1 && $findings[0]['field'] === 'iban', 'changed IBAN alerts in IBAN-only mode');
$findings = compare_ibans(['PT50000201231234567890154'], []);
check(count($findings) === 1 && $findings[0]['type'] === 'count', 'removed account alerts in IBAN-only mode');
$ptLabelChanged = $ptAccount;
$ptLabelChanged[0]['account_name'] = 'Other Name';
$ptLabelChanged[0]['bic'] = 'BCOMPTPL';
check(compare_ibans(['PT50000201231234567890154'], $ptLabelChanged) === [], 'IBAN-only mode ignores labels and BIC');

// Malformed option values
foreach (['', 'b:0;', 's:3:"abc";', 'garbage'] as $bad) {
    try {
        parse_option_value($bad);
        check(false, "malformed option value '$bad' is rejected");
    } catch (RuntimeException) {
        check(true, "malformed option value '$bad' is rejected");
    }
}

// Alert rendering masks bank details by default
$lines = describe_findings(compare_accounts($fixture, $changed), false);
check(str_contains($lines[0], '****5432') && !str_contains($lines[0], 'GB82WEST12345698765432'), 'findings mask bank details by default');
$lines = describe_findings(compare_accounts($fixture, $changed), true);
check(str_contains($lines[0], 'GB82WEST12345698765432'), 'findings can include full values when enabled');

// Discord webhooks get an embed; other endpoints keep the generic JSON payload
$alert = [
    'service' => 'woocommerce-iban-watchdog',
    'status' => 'tampered',
    'site' => 'shop.example',
    'text' => 'Offline bank-transfer details changed on shop.example',
    'detected_at' => '2026-01-01T00:00:00+00:00',
    'restored' => true,
    'findings' => ['account #1 iban changed: expected ****3000, actual ****5432'],
];
$discord = discord_payload($alert);
check(count($discord['embeds']) === 1 && $discord['embeds'][0]['color'] === 0xE01F1F, 'Discord tamper alert uses the red embed');
check(str_contains($discord['embeds'][0]['fields'][2]['value'], '• account #1 iban changed'), 'Discord embed renders findings as bullet lines');
check($discord['embeds'][0]['fields'][3]['name'] === 'Auto-restore', 'Discord embed mentions auto-restore');
check($discord['allowed_mentions'] === ['parse' => []], 'Discord payload never mentions roles or everyone');
check(discord_payload(['status' => 'recovered'] + $alert)['embeds'][0]['color'] === 0x1F9D55, 'Discord recovery alert uses the green embed');
check(is_discord_webhook('https://discord.com/api/webhooks/1/token'), 'Discord webhook URL is detected');
check(is_discord_webhook('https://ptb.discord.com/api/webhooks/1/token'), 'Discord subdomain URL is detected');
check(!is_discord_webhook('https://hooks.slack.com/services/x'), 'non-Discord webhook keeps the generic payload');

// IBANs in gateway instructions text
check(find_ibans_in_text('Pay to DE89 3704 0044 0532 0130 00 please') === ['DE89370400440532013000'], 'spaced IBAN found in text');
check(find_ibans_in_text('ref DE89370400440532013001') === [], 'bad-checksum IBAN in text is ignored');
$settings = serialize(['description' => 'Pay by transfer', 'instructions' => 'IBAN: GB82 WEST 1234 5698 7654 32']);
check(check_settings_text($settings, ['GB82WEST12345698765432']) === [], 'trusted IBAN in instructions passes');
check(check_settings_text($settings, ['DE89370400440532013000'])[0]['type'] === 'foreign_iban_in_text', 'untrusted IBAN in instructions is flagged');
check(check_settings_text('garbage', [])[0]['type'] === 'unreadable_option', 'unparseable gateway settings are flagged');

exit($failed === 0 ? 0 : 1);
