<?php

declare(strict_types=1);

if (!extension_loaded('pcov') || !getenv('COVERAGE_DIR')) {
    return;
}

pcov\stop();
$coverage = pcov\collect(pcov\all);
$dir = (string) getenv('COVERAGE_DIR');

if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

file_put_contents(
    $dir . DIRECTORY_SEPARATOR . 'coverage-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.json',
    json_encode($coverage, JSON_UNESCAPED_SLASHES)
);

pcov\clear();

