<?php
$files = glob(__DIR__ . '/../modules/*/*.php');
echo "Checking DIV tag balance across all module pages...\n";
$issuesFound = 0;

foreach ($files as $f) {
    $content = file_get_contents($f);
    if (strpos($content, 'includes/footer.php') === false) {
        continue;
    }
    
    // Strip comments to avoid counting divs inside comments
    $clean = preg_replace('/<!--.*?-->/s', '', $content);
    // Strip PHP blocks to avoid counting in strings
    $clean = preg_replace('/<\?php.*?\?>/s', '', $clean);
    
    $opens = preg_match_all('/<div[\s>]/i', $clean);
    $closes = preg_match_all('/<\/div>/i', $clean);
    
    // In our layout:
    // header.php opens <div class="app-wrapper"> (1 open)
    // page opens <div class="app-main"> and <div class="app-content"> (2 opens)
    // footer.php has: </div> <!-- end app-main --> </div> <!-- end app-wrapper --> (2 closes)
    // Therefore, inside the page file:
    // It has (N opens, N - 1 closes) => diff (opens - closes) MUST EQUAL 1!
    // If diff == 0, it means the page closed app-main prematurely!
    $diff = $opens - $closes;
    if ($diff !== 1) {
        $relPath = str_replace(realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR, '', realpath($f));
        echo "MISMATCH: $relPath -> opens: $opens, closes: $closes, diff: $diff (EXPECTED: 1)\n";
        $issuesFound++;
    }
}

if ($issuesFound === 0) {
    echo "ALL PAGES BALANCED PERFECTLY!\n";
} else {
    echo "Found $issuesFound pages with div mismatches.\n";
}
