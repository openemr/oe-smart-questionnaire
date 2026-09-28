<?php

/**
 * Bootstrap: PSR-4 autoloader for OpenEMR\SmartQuestionnaire and the h() escaper.
 * The app has no runtime Composer dependencies; every entry point requires this file.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @link      https://github.com/openemr/oe-smart-questionnaire
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'OpenEMR\\SmartQuestionnaire\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

/**
 * HTML-escape for text and attribute contexts.
 */
function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
