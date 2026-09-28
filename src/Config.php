<?php

/**
 * Application configuration (config.php) and the registered-client store (clients.json).
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
 * App configuration (config.php) and the registered-client list (clients.json).
 *
 * The client list doubles as the issuer allowlist: the app only launches against a FHIR
 * server it has been registered with, so a forged ?iss= can never receive a code or token.
 */
final class Config
{
    /** @var array<string, mixed>|null */
    private static ?array $config = null;

    /** @return array<string, mixed> */
    public static function all(): array
    {
        if (self::$config === null) {
            $override = getenv('SQ_CONFIG');
            $file = is_string($override) && $override !== '' ? $override : dirname(__DIR__) . '/config.php';
            if (!is_file($file)) {
                throw new RuntimeException('Missing config.php — copy config.sample.php and edit it.');
            }
            $loaded = require $file;
            if (!is_array($loaded)) {
                throw new RuntimeException('config.php must return an array.');
            }
            self::$config = $loaded + [
                'app_name' => 'OpenEMR SMART Questionnaires',
                'sites' => [],
                'contact' => '',
                'verify_tls' => true,
                'clients_file' => dirname(__DIR__) . '/var/clients.json',
                // Scopes come from ScopeProfiles; 'ehr_scopes' / 'standalone_scopes' set in an
                // older config.php still override the v1 profile.
                'default_scope_profile' => 'v1',
                'scope_profiles' => [],
            ];
            if (!is_string(self::$config['app_url'] ?? null) || self::$config['app_url'] === '') {
                throw new RuntimeException('config.php must set app_url (public URL of the public/ directory).');
            }
            self::$config['app_url'] = rtrim(self::$config['app_url'], '/');
        }

        return self::$config;
    }

    public static function string(string $key): string
    {
        $value = self::all()[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    public static function verifyTls(): bool
    {
        return (bool)(self::all()['verify_tls'] ?? true);
    }

    public static function appUrl(string $path = ''): string
    {
        return self::string('app_url') . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }

    public static function redirectUri(): string
    {
        return self::appUrl('callback.php');
    }

    public static function launchUri(): string
    {
        return self::appUrl('launch.php');
    }

    public static function normalizeIssuer(string $issuer): string
    {
        return rtrim(trim($issuer), '/');
    }

    /** @return array<string, array<string, mixed>> issuer => client record */
    public static function clients(): array
    {
        $file = self::string('clients_file');
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string)file_get_contents($file), true);
        if (!is_array($decoded)) {
            return [];
        }
        $clients = [];
        foreach ($decoded as $issuer => $client) {
            if (is_string($issuer) && is_array($client) && is_string($client['client_id'] ?? null)) {
                $clients[self::normalizeIssuer($issuer)] = $client;
            }
        }

        return $clients;
    }

    /** @return array<string, mixed>|null */
    public static function clientFor(string $issuer): ?array
    {
        return self::clients()[self::normalizeIssuer($issuer)] ?? null;
    }

    /** @param array<string, mixed> $client */
    public static function saveClient(string $issuer, array $client): void
    {
        $file = self::string('clients_file');
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}");
        }
        $clients = self::clients();
        $clients[self::normalizeIssuer($issuer)] = $client;
        $json = json_encode($clients, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($file, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException("Cannot write {$file}");
        }
        @chmod($file, 0600);
    }

    public static function removeClient(string $issuer): void
    {
        $clients = self::clients();
        unset($clients[self::normalizeIssuer($issuer)]);
        $json = json_encode($clients === [] ? new \stdClass() : $clients, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents(self::string('clients_file'), $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Cannot write ' . self::string('clients_file'));
        }
    }

    /**
     * Origins allowed to frame the app: itself plus every registered EHR.
     *
     * @return list<string>
     */
    public static function frameAncestors(): array
    {
        $origins = [];
        foreach (array_keys(self::clients()) as $issuer) {
            $parts = parse_url($issuer);
            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                $origins[] = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            }
        }

        return array_values(array_unique($origins));
    }
}
