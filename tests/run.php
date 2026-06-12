<?php

declare(strict_types=1);

$root = dirname(__DIR__);

require $root . '/src/bootstrap.php';
require $root . '/tests/TestHarness.php';
require $root . '/tests/UnitTests.php';
require $root . '/tests/E2ETests.php';

$coverageEnabled = extension_loaded('pcov') && in_array('--coverage', $argv, true);

if ($coverageEnabled) {
    pcov\start();
}

$test = new TestHarness();

run_unit_tests($test, $root);
run_e2e_tests($test, $root);

$coverage = [];

if ($coverageEnabled) {
    pcov\stop();
    $coverage = pcov\collect(pcov\all);
    $coverage = merge_coverage($coverage, collect_child_coverage($root . '/tests/.runtime/e2e/coverage'));
    $report = coverage_report($coverage, $root . '/src');
    echo "\nCoverage\n";
    echo sprintf(
        "%s/%s executable lines covered (%0.2f%%)\n",
        $report['covered'],
        $report['total'],
        $report['percent'],
    );

    foreach ($report['files'] as $file => $stats) {
        echo sprintf(
            "  %s: %s/%s (%0.2f%%)\n",
            str_replace($root . DIRECTORY_SEPARATOR, '', $file),
            $stats['covered'],
            $stats['total'],
            $stats['percent'],
        );

        if (in_array('--missing', $argv, true) && $stats['missing'] !== []) {
            echo '    missing: ' . implode(', ', $stats['missing']) . "\n";
        }
    }
}

echo "\nAssertions: " . $test->assertions() . "\n";

if ($test->failures() > 0) {
    echo $test->failures() . " failure(s)\n";
    exit(1);
}

echo "All tests passed.\n";

/**
 * @param array<string, array<int, int>> $coverage
 * @return array<string, array<int, int>>
 */
function collect_child_coverage(string $dir): array
{
    $coverage = [];

    if (!is_dir($dir)) {
        return $coverage;
    }

    foreach (glob($dir . DIRECTORY_SEPARATOR . 'coverage-*.json') ?: [] as $file) {
        $decoded = json_decode(file_get_contents($file) ?: '{}', true);

        if (is_array($decoded)) {
            $coverage = merge_coverage($coverage, $decoded);
        }
    }

    return $coverage;
}

/**
 * @param array<string, array<int|string, int>> $left
 * @param array<string, array<int|string, int>> $right
 * @return array<string, array<int, int>>
 */
function merge_coverage(array $left, array $right): array
{
    $merged = normalize_coverage($left);

    foreach (normalize_coverage($right) as $file => $lines) {
        $merged[$file] ??= [];

        foreach ($lines as $line => $hits) {
            $merged[$file][$line] = max($merged[$file][$line] ?? -1, $hits);
        }
    }

    return $merged;
}

/**
 * @param array<string, array<int|string, int>> $coverage
 * @return array<string, array<int, int>>
 */
function normalize_coverage(array $coverage): array
{
    $normalized = [];

    foreach ($coverage as $file => $lines) {
        if (!is_array($lines)) {
            continue;
        }

        $path = realpath((string) $file) ?: (string) $file;
        $normalized[$path] ??= [];

        foreach ($lines as $line => $hits) {
            if (!is_numeric($line) || !is_int($hits)) {
                continue;
            }

            $lineNumber = (int) $line;
            $normalized[$path][$lineNumber] = max($normalized[$path][$lineNumber] ?? -1, $hits);
        }
    }

    return $normalized;
}

/**
 * @param array<string, array<int, int>> $coverage
 * @return array{covered: int, total: int, percent: float, files: array<string, array{covered: int, total: int, percent: float, missing: list<int>}>}
 */
function coverage_report(array $coverage, string $srcDir): array
{
    $files = [];
    $total = 0;
    $covered = 0;
    $srcDir = realpath($srcDir) ?: $srcDir;

    foreach ($coverage as $file => $lines) {
        $realFile = realpath($file) ?: $file;

        if (!str_starts_with($realFile, $srcDir)) {
            continue;
        }

        $ignored = ignored_coverage_lines($realFile);
        foreach (array_keys($ignored) as $line) {
            unset($lines[$line]);
        }

        $fileTotal = count($lines);
        $fileCovered = 0;
        $missing = [];

        foreach ($lines as $line => $hits) {
            if ($hits > 0) {
                $fileCovered++;
            } else {
                $missing[] = (int) $line;
            }
        }
        sort($missing);

        $files[$realFile] = [
            'covered' => $fileCovered,
            'total' => $fileTotal,
            'percent' => $fileTotal > 0 ? ($fileCovered / $fileTotal) * 100 : 100.0,
            'missing' => $missing,
        ];
        $total += $fileTotal;
        $covered += $fileCovered;
    }

    ksort($files);

    return [
        'covered' => $covered,
        'total' => $total,
        'percent' => $total > 0 ? ($covered / $total) * 100 : 100.0,
        'files' => $files,
    ];
}

/**
 * @return array<int, true>
 */
function ignored_coverage_lines(string $file): array
{
    $ignored = [];
    $inBlock = false;
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];

    foreach ($lines as $index => $line) {
        $lineNumber = $index + 1;

        if (str_contains($line, '@coverage-ignore-start')) {
            $inBlock = true;
            $ignored[$lineNumber] = true;
            continue;
        }

        if (str_contains($line, '@coverage-ignore-end')) {
            $ignored[$lineNumber] = true;
            $inBlock = false;
            continue;
        }

        if ($inBlock || str_contains($line, '@coverage-ignore-line')) {
            $ignored[$lineNumber] = true;
        }
    }

    return $ignored;
}
