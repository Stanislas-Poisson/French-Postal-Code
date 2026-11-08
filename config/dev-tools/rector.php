<?php

declare(strict_types=1);
use Zairakai\LaravelDevTools\Rector\RectorBaseConfig;

/**
 * Resolve Rector base config location dynamically.
 *
 * This file MUST:
 * - locate rector.base.php
 * - require it
 * - return a RectorConfig closure
 */
$fqcn           = RectorBaseConfig::class;
$projectRoot    = dirname(__DIR__, 2);
$currentFile    = realpath(__FILE__);
$baseConfigPath = null;

/**
 * Possible locations of rector.base.php.
 */
$possibleBaseConfigs = [
    // Installed as dev tool
    $projectRoot . '/vendor/zairakai/laravel-dev-tools/config/rector.base.php',

    // Package / testbench context
    dirname($projectRoot, 2) . '/vendor/zairakai/laravel-dev-tools/config/rector.base.php',

    // Local override (optional)
    $projectRoot . '/config/rector.base.php',
];

foreach ($possibleBaseConfigs as $path) {
    if (! is_file($path)) {
        continue;
    }

    if (realpath($path) === $currentFile) {
        continue;
    }

    $baseConfigPath = realpath($path);

    break;
}

if (null === $baseConfigPath) {
    throw new RuntimeException('Unable to locate rector.base.php');
}

/**
 * Load base config definition.
 * Must define RectorBaseConfig class.
 */
require_once $baseConfigPath;

if (! class_exists($fqcn, false)) {
    throw new RuntimeException(
        sprintf('RectorBaseConfig not found in "%s"', $baseConfigPath),
    );
}

return $fqcn::configure(
    projectRoot: $projectRoot,
    extraPaths: [],
    extraSkips: [],
);
