<?php

/**
 * PHP syntax check of every project file (cross-platform replacement for `find … -exec php -l`).
 *
 *   php tests/lint.php
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @link      https://github.com/openemr/oe-smart-questionnaire
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$skip = '~[\\\\/](vendor|node_modules|\.git|var)[\\\\/]~';
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$checked = 0;
$failed = [];
foreach ($files as $file) {
    $path = $file->getPathname();
    if (!str_ends_with($path, '.php') || preg_match($skip, $path) === 1) {
        continue;
    }
    $checked++;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);
    if ($code !== 0) {
        $failed[] = implode("\n", $output);
    }
    $output = [];
}
foreach ($failed as $message) {
    fwrite(STDERR, $message . "\n");
}
echo ($failed === [] ? 'OK' : 'FAILED') . " ({$checked} files, " . count($failed) . " with errors)\n";
exit($failed === [] ? 0 : 1);
