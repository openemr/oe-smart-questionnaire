<?php

/**
 * Shared page chrome, error pages and JSON responses.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @link      https://github.com/openemr/oe-smart-questionnaire
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\SmartQuestionnaire;

/**
 * Page chrome shared by the entry points.
 */
final class View
{
    public static function header(string $title): void
    {
        Session::securityHeaders();
        header('Content-Type: text/html; charset=utf-8');
        $appName = Config::string('app_name');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . h($title . ' · ' . $appName) . '</title>'
            . '<link rel="stylesheet" href="assets/vendor/bootstrap-4.6.2.min.css">'
            . '<link rel="stylesheet" href="assets/runtime/openemr_questionnaire.css">'
            . '<link rel="stylesheet" href="assets/app.css">'
            . '</head><body><main class="container-fluid py-3">';
    }

    public static function footer(): void
    {
        echo '</main></body></html>';
    }

    public static function error(int $status, string $title, string $message): never
    {
        http_response_code($status);
        self::header($title);
        echo '<div class="alert alert-danger"><h1 class="h5">' . h($title) . '</h1><p class="mb-0">'
            . h($message) . '</p></div>';
        self::footer();
        exit;
    }

    /** @param array<string, mixed> $payload */
    public static function json(int $status, array $payload): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }
}
