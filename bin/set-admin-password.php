<?php

/**
 * Set the password for public/admin.php.
 *   php bin/set-admin-password.php            (prompts)
 *   php bin/set-admin-password.php --password=…
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @link      https://github.com/openemr/oe-smart-questionnaire
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

use OpenEMR\SmartQuestionnaire\AdminAuth;

$options = getopt('', ['password:']);
$password = is_array($options) && is_string($options['password'] ?? null) ? $options['password'] : null;
if ($password === null) {
    fwrite(STDOUT, 'New admin password (12+ characters): ');
    if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
        @shell_exec('stty -echo');
    }
    $password = trim((string)fgets(STDIN));
    if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
        @shell_exec('stty echo');
    }
    fwrite(STDOUT, "\n");
}

try {
    AdminAuth::setPassword($password);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
fwrite(STDOUT, 'Admin password saved to ' . AdminAuth::file() . "\n");
