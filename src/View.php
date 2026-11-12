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

    /**
     * A FHIR date or dateTime for people: "Today 3:05 pm", "Yesterday 9:12 am", "Mar 3, 2026".
     * A date without a time shows no time; an unreadable value is returned as is.
     */
    public static function when(string $value, ?int $now = null): string
    {
        $time = strtotime($value);
        if ($value === '' || $time === false) {
            return $value;
        }
        $now ??= time();
        $hasTime = str_contains($value, 'T');
        $day = date('Y-m-d', $time);
        $label = match ($day) {
            date('Y-m-d', $now) => 'Today',
            date('Y-m-d', strtotime('-1 day', $now)) => 'Yesterday',
            default => date('M j, Y', $time),
        };
        if (!$hasTime) {
            return $label;
        }

        return $label . ' ' . date('g:i a', $time);
    }

    /**
     * The bar across the top of a patient's pages: who is signed in, where, through which kind of
     * client, a way home (when not on the home page) and sign out.
     */
    public static function patientBar(LaunchSession $session, string $patientName, bool $onHome, string $meta = ''): string
    {
        $host = (string)(parse_url((string)$session->record['issuer'], PHP_URL_HOST) ?: '');
        $kind = $session->isPublicClient()
            ? '<span class="badge badge-light border" title="Signed in with the public patient client: no secret, PKCE only">public app</span>'
            : '<span class="badge badge-light border" title="Signed in with the confidential client: this app holds a client secret">confidential app</span>';

        return '<nav class="sq-banner sq-patient-bar border rounded px-3 py-2 mb-3 d-flex flex-wrap align-items-center justify-content-between">'
            . '<div class="mr-3"><strong>' . h($patientName) . '</strong>'
            . ($meta !== '' ? '<span class="small text-muted ml-2">' . h($meta) . '</span>' : '')
            . '<div class="small text-muted">Signed in to ' . h($host) . ' ' . $kind . '</div></div>'
            . '<div class="d-flex align-items-center">'
            . ($onHome ? '' : '<a class="btn btn-sm btn-link" href="home.php?' . h(http_build_query(['lid' => $session->launchId])) . '">My health</a>')
            . '<form method="post" action="signout.php" class="mb-0">'
            . '<input type="hidden" name="lid" value="' . h($session->launchId) . '">'
            . '<input type="hidden" name="csrf" value="' . h($session->csrf()) . '">'
            . '<button type="submit" class="btn btn-sm btn-outline-secondary">Sign out</button></form>'
            . '</div></nav>';
    }

    /**
     * The first paragraph of a long text, cut at a word near $max characters. Questionnaire
     * descriptions can be pages of clinician instructions (PHQ-9's is), too long for a list.
     */
    public static function excerpt(string $text, int $max = 160): string
    {
        $paragraphs = preg_split('/\R/u', trim($text), 2);
        $first = trim(is_array($paragraphs) ? $paragraphs[0] : $text);
        if (mb_strlen($first) <= $max) {
            return $first;
        }
        $cut = mb_substr($first, 0, $max);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > $max / 2 ? mb_substr($cut, 0, $space) : $cut, " ,.;:") . '…';
    }
}
