<?php

declare(strict_types=1);

if (extension_loaded('pcov') && getenv('COVERAGE_DIR')) {
    pcov\start();
}

