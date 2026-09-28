<?php

/**
 * Password gate for the administration console (admin.php).
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

use RuntimeException;

/**
 * Password gate for admin.php. The hash lives in var/admin.json, set with
 * `php bin/set-admin-password.php`; with no password set, the admin page stays locked.
 */
final class AdminAuth
{
    private const SESSION_KEY = 'sq_admin';
    private const IDLE_SECONDS = 1800;

    public static function file(): string
    {
        return dirname(Config::string('clients_file')) . '/admin.json';
    }

    public static function isConfigured(): bool
    {
        return self::hash() !== null;
    }

    public static function setPassword(string $password): void
    {
        if (strlen($password) < 12) {
            throw new RuntimeException('Use at least 12 characters.');
        }
        $dir = dirname(self::file());
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}");
        }
        $json = json_encode(['password_hash' => password_hash($password, PASSWORD_DEFAULT)], JSON_THROW_ON_ERROR);
        if (file_put_contents(self::file(), $json, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write ' . self::file());
        }
        @chmod(self::file(), 0600);
    }

    public static function login(string $password): bool
    {
        $hash = self::hash();
        if ($hash === null || !password_verify($password, $hash)) {
            // Flat delay blunts online guessing without extra state.
            usleep(750000);
            return false;
        }
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = ['at' => time(), 'csrf' => bin2hex(random_bytes(32))];

        return true;
    }

    public static function isLoggedIn(): bool
    {
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($state) || (time() - (int)($state['at'] ?? 0)) > self::IDLE_SECONDS) {
            unset($_SESSION[self::SESSION_KEY]);
            return false;
        }
        $_SESSION[self::SESSION_KEY]['at'] = time();

        return true;
    }

    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    public static function csrf(): string
    {
        return (string)($_SESSION[self::SESSION_KEY]['csrf'] ?? '');
    }

    public static function checkCsrf(mixed $token): bool
    {
        return is_string($token) && self::csrf() !== '' && hash_equals(self::csrf(), $token);
    }

    private static function hash(): ?string
    {
        if (!is_file(self::file())) {
            return null;
        }
        $decoded = json_decode((string)file_get_contents(self::file()), true);
        $hash = is_array($decoded) ? ($decoded['password_hash'] ?? null) : null;

        return is_string($hash) && $hash !== '' ? $hash : null;
    }
}
