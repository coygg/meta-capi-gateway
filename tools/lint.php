<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$targets = [
    $root . '/config',
    $root . '/public',
    $root . '/src',
    $root . '/tests',
];

$files = [];

foreach ($targets as $target) {
    if (!is_dir($target)) {
        continue;
    }

    $directory = new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS);
    $filter = new RecursiveCallbackFilterIterator(
        $directory,
        static fn (SplFileInfo $file): bool => $file->getFilename() !== '.runtime'
    );
    $iterator = new RecursiveIteratorIterator(
        $filter
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }

        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}

sort($files);

if ($files === []) {
    fwrite(STDERR, "No PHP files found to lint.\n");
    exit(1);
}

$failures = 0;

foreach ($files as $file) {
    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file);
    exec($command, $output, $status);

    if ($status !== 0) {
        $failures++;
        echo implode(PHP_EOL, $output) . PHP_EOL;
    }
}

if ($failures > 0) {
    fwrite(STDERR, sprintf("%d PHP file(s) failed linting.\n", $failures));
    exit(1);
}

echo sprintf("Linted %d PHP file(s).\n", count($files));
