<?php
/**
 * Verifies the compiled Tailwind stylesheet actually contains every utility
 * class the app references. Run after any `npm run build:css`.
 *
 * Exit 0 = every referenced class has a rule in assets/css/tailwind.css.
 * Exit 1 = at least one class was purged; the report lists them.
 *
 * COVERAGE BOUNDARY: only classes appearing inside a literal class="..."
 * attribute are checked. Classes that live only in a JS object or PHP variable
 * (e.g. the colorMap `btn: 'bg-emerald-600'` in input_form.php) are NOT
 * verified here -- deleting such a rule from the CSS would still report PASS.
 * Those are covered by the safelist in tailwind.config.js instead.
 *
 * ponytail: regex extraction, the same heuristic Tailwind's own scanner uses.
 * Upgrade to a headless-browser pixel diff against the old CDN render if
 * styling ever visibly drifts.
 */
$root = str_replace('\\', '/', dirname(__DIR__));
$css  = @file_get_contents($root . '/assets/css/tailwind.css');
if ($css === false) {
    fwrite(STDERR, "FAIL: assets/css/tailwind.css not found. Run: npm run build:css\n");
    exit(1);
}

// Non-Tailwind classes: hand-written CSS, vendor libraries, app conventions.
$ignore_prefix = [
    'fa-', 'fas', 'far', 'fab', 'select2', 'leaflet', 'toast', 'progress',
    'skeleton', 'spinner', 'rank-', 'nav-link', 'glass-card', 'stat-card',
    'bg-blob', 'blob-', 'pulse-red', 'modal-overlay', 'sidebar', 'filter-chip',
    'sort-icon', 'sortable', 'search-match', 'search-result-count', 'selected-tag',
    'remove-tag', 'multi-select-dropdown', 'message-box-overlay', 'badge-online',
    'badge-offline', 'photo-thumbnail', 'form-checkbox', 'active', 'chartjs',
    // No animate-* / fade-* / zoom-* / slide-* entries here on purpose:
    // tailwindcss-animate supplies those, and animate-shake is defined in
    // theme.extend of tailwind.config.js, so all of them stay under
    // verification.
    // Plugins never installed / classes removed in Tailwind v2. Dead under the
    // CDN as well, kept here so the check does not flag pre-existing breakage.
    'prose', 'focus:shadow-outline',
    // Hand-written CSS: per-page <style> blocks and tools/password_hasher/style.css.
    'metric-card', 'counters', 'simple-card', 'step-number', 'step-card',
    'download-btn', 'custom-scrollbar', 'request-btn', 'form-section-title',
    'login-card', 'input-group', 'input-icon', 'responsive-table',
    'clear-search', 'selected-items', 'dropdown-content', 'subtitle',
    'textarea', 'form-group', 'manual-mode', 'select-input', 'secondary-inputs',
    'db-', 'strength-', 'result-', 'hash-', 'sql-box', 'copy-badge',
    'btn-secondary', 'btn-export', 'input-small',
];

// JS hooks: `class="... foo-checkbox"` selectors, never styled.
$ignore_suffix = ['-checkbox'];

// Extractor artifacts: PHP/JS keywords caught by the class="" regex.
$ignore_exact = ['echo', 'if', 'fa'];

$files = [];
$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($rii as $f) {
    $p = str_replace('\\', '/', $f->getPathname());
    if (strpos($p, '/vendor/') !== false) continue;
    if (strpos($p, '/node_modules/') !== false) continue;
    if (preg_match('/\.(php|js)$/i', $p)) $files[] = $p;
}

$classes = [];
foreach ($files as $p) {
    $src = file_get_contents($p);
    if (!preg_match_all('/class\s*=\s*["\']([^"\']*)["\']/i', $src, $m)) continue;
    foreach ($m[1] as $blob) {
        // Drop PHP/JS interpolation, keep the literal tokens surrounding it.
        $blob = preg_replace('/<\?php.*?\?>|<\?=.*?\?>|\$\{[^}]*\}/s', ' ', $blob);
        foreach (preg_split('/\s+/', $blob) as $c) {
            if ($c !== '') $classes[$c] = $p;
        }
    }
}

$missing = [];
foreach ($classes as $c => $where) {
    if (in_array($c, $ignore_exact, true)) continue;
    foreach ($ignore_prefix as $ip) {
        if (strpos($c, $ip) === 0) continue 2;
    }
    foreach ($ignore_suffix as $is) {
        if (substr($c, -strlen($is)) === $is) continue 2;
    }
    if (!preg_match('/^[a-z!-]/i', $c)) continue;
    // Tailwind escapes : / . [ ] ( ) # % , in the selectors it generates.
    $esc = preg_replace('/([:\/.\[\]()#%,])/', '\\\\$1', $c);
    if (strpos($css, '.' . $esc) === false) $missing[$c] = $where;
}

$total = count($classes);
if (!$missing) {
    echo "PASS: all {$total} referenced classes present in compiled CSS.\n";
    exit(0);
}
echo "FAIL: " . count($missing) . " of {$total} referenced classes MISSING from compiled CSS.\n\n";
foreach ($missing as $c => $where) {
    printf("  %-44s first seen: %s\n", $c, str_replace($root . '/', '', $where));
}
echo "\nFix: add to safelist in tailwind.config.js, or to \$ignore_prefix if not a Tailwind class.\n";
exit(1);
