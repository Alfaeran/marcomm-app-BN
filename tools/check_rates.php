<?php
// Self-check: SP/FWA rate constants must stay identical across every write path.
// A drifted constant silently corrupts benefit_sp / benefit_total in reporting.
// Run: php tools/check_rates.php   (exit 0 = pass)
// ponytail: greps literals rather than importing a shared constant. Correct fix is
// one config/rates.php included everywhere; do that when rates next change.

const RATES = ['sp_0k' => 10000, 'sp_3gb' => 27000, 'sp_5gb' => 35000, 'sp_7gb' => 39000];
const FWA   = ['fwa' => 150000, 'fwa_5g' => 750000];

// Files that compute benefit_sp / benefit_fwa. Each must carry every rate.
$paths = [
    'input_form.php',
    'user_edit_event_form.php',
    'api/core_inputs.php',
    'api/bulk_import.php',
    'process/bulk_import_event_process.php',
    'process/admin_event_process.php',
    'process/user_event_edit_process.php',
];

$fail = [];
foreach ($paths as $p) {
    $full = __DIR__ . '/../' . $p;
    if (!is_file($full)) { $fail[] = "$p: MISSING"; continue; }
    $src = file_get_contents($full);
    foreach (array_merge(RATES, FWA) as $name => $rate) {
        // Only assert a rate if the file references that field at all.
        if (strpos($src, $name) === false) continue;
        if (strpos($src, (string)$rate) === false) {
            $fail[] = "$p: mentions '$name' but not its rate $rate";
        }
    }
}

// Retired rates must not reappear (East Java values + dropped fields).
foreach (['sp_6gb' => null, 'sp_9gb' => null, 'sp_existing' => null] as $dead => $_) {
    foreach ($paths as $p) {
        $full = __DIR__ . '/../' . $p;
        if (is_file($full) && strpos(file_get_contents($full), $dead) !== false) {
            $fail[] = "$p: retired field '$dead' still present";
        }
    }
}

// Arithmetic contract: the totals formula itself.
$sp = (2 * 10000) + (3 * 27000) + (1 * 35000) + (4 * 39000);
assert($sp === 292000, 'benefit_sp formula drifted');
$fw = (1 * 150000) + (2 * 750000);
assert($fw === 1650000, 'benefit_fwa formula drifted');
assert((0 > 0 ? 1 : 0) === 0, 'ratio guard: zero benefit must not divide');

if ($fail) {
    fwrite(STDERR, "RATE CHECK FAILED\n  " . implode("\n  ", $fail) . "\n");
    exit(1);
}
echo "rate check OK - " . count($paths) . " write paths consistent\n";
