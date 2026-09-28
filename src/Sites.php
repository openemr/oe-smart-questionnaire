<?php

/**
 * Named OpenEMR sites from config.php.
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
 * Named OpenEMR sites from config.php, like the API Explorer's $apiSites.
 *
 * A site value is either an OpenEMR base URL (https://host/openemr), from which the FHIR base
 * https://host/openemr/apis/default/fhir is derived, or a FHIR base URL ending in /fhir.
 */
final class Sites
{
    /**
     * A site entry is either a URL string or ['url' => …, 'scope_profile' => 'v1'|'v2'|…].
     *
     * @return array<string, array{name: string, base: string, iss: string, scope_profile: ?string}>
     */
    public static function all(): array
    {
        $configured = Config::all()['sites'] ?? [];
        $sites = [];
        foreach (is_array($configured) ? $configured : [] as $name => $entry) {
            $url = is_array($entry) ? ($entry['url'] ?? null) : $entry;
            if (!is_string($name) || !is_string($url) || trim($url) === '') {
                continue;
            }
            $profile = is_array($entry) && is_string($entry['scope_profile'] ?? null) ? $entry['scope_profile'] : null;
            $sites[$name] = [
                'name' => $name,
                'base' => rtrim(trim($url), '/'),
                'iss' => self::issuerFromUrl($url),
                'scope_profile' => $profile,
            ];
        }

        return $sites;
    }

    public static function issuerFromUrl(string $url): string
    {
        $url = Config::normalizeIssuer($url);

        return preg_match('~/fhir$~i', $url) === 1 ? $url : $url . '/apis/default/fhir';
    }

    /** @return array{name: string, base: string, iss: string, scope_profile: ?string}|null */
    public static function get(string $name): ?array
    {
        return self::all()[$name] ?? null;
    }

    public static function nameForIssuer(string $issuer): ?string
    {
        $issuer = Config::normalizeIssuer($issuer);
        foreach (self::all() as $site) {
            if ($site['iss'] === $issuer) {
                return $site['name'];
            }
        }

        return null;
    }
}
