<?php

/**
 * Session cookie and security-header setup shared by every entry point.
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
 * Session and response-header setup shared by every entry point.
 *
 * The app is usually opened inside the EHR (an iframe or dialog on another site), so the
 * session cookie must be SameSite=None; Secure or the browser drops it after the OAuth
 * redirect and the launch is lost.
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        // Scope the cookie to the URL actually serving the request, not app_url: the app can
        // also be reached at another path or over plain http (e.g. http://localhost/…/public/),
        // where a Secure cookie for app_url's path is never sent back and every login is lost.
        $plainHttp = empty($_SERVER['HTTPS']) || strtolower((string)$_SERVER['HTTPS']) === 'off';
        $forwardedHttps = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $secure = str_starts_with(Config::appUrl(), 'https://') && (!$plainHttp || $forwardedHttps);
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? null;
        $path = is_string($scriptName) && str_starts_with($scriptName, '/')
            ? dirname($scriptName)
            : parse_url(Config::appUrl(), PHP_URL_PATH);
        session_name('oe_smart_questionnaire');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => (is_string($path) && $path !== '' ? rtrim(str_replace('\\', '/', $path), '/') : '') . '/',
            'secure' => $secure,
            'httponly' => true,
            // None requires Secure; fall back to Lax for plain-http local development.
            'samesite' => $secure ? 'None' : 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        session_start();
    }

    public static function securityHeaders(): void
    {
        $ancestors = implode(' ', array_merge(["'self'"], Config::frameAncestors()));
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data:; connect-src 'self'; form-action 'self'; base-uri 'none'; object-src 'none'; "
            . "frame-ancestors {$ancestors}");
    }
}
