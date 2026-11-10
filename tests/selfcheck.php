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

exit($failed === 0 ? 0 : 1);
