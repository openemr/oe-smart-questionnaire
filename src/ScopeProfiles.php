<?php

/**
 * Named scope sets (v1, v2, custom) that clients are registered with.
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
 * Named scope sets a client can be registered with.
 *
 *  - v1 (default): QuestionnaireResponse as v1 .read + .write. Works with current OpenEMR.
 *  - v2: QuestionnaireResponse as SMART v2 .rs + .cu. Needs an OpenEMR whose consent screen
 *        keeps v2 reads and writes as separate scopes; on an unfixed OpenEMR the consent screen
 *        merges them into one scope its approval check drops, and saves fail with 401.
 *
 * Each client record stores the exact scopes it was registered with, and launches request those,
 * so a later change of profile or config never breaks an existing registration.
 */
final class ScopeProfiles
{
    public const DEFAULT = 'v1';

    private const BASE = 'openid fhirUser %s user/Patient.rs user/Questionnaire.rs';

    /** @return array<string, array{label: string, ehr: string, standalone: string, needs_consent_fix: bool}> */
    public static function all(): array
    {
        $profiles = [
            'v1' => self::build(
                'SMART v1 read/write for QuestionnaireResponse — works with current OpenEMR',
                'user/QuestionnaireResponse.read user/QuestionnaireResponse.write',
                false
            ),
            'v2' => self::build(
                'SMART v2 (.rs + .cu) for QuestionnaireResponse — needs the OpenEMR consent-screen fix',
                'user/QuestionnaireResponse.rs user/QuestionnaireResponse.cu',
                true
            ),
        ];

        // Legacy config keys from earlier versions override the v1 profile.
        foreach (['ehr' => 'ehr_scopes', 'standalone' => 'standalone_scopes'] as $field => $key) {
            if (Config::string($key) !== '') {
                $profiles['v1'][$field] = self::normalize(Config::string($key));
            }
        }

        // config.php 'scope_profiles' may add profiles or override the built-in ones.
        $configured = Config::all()['scope_profiles'] ?? [];
        foreach (is_array($configured) ? $configured : [] as $name => $profile) {
            if (!is_string($name) || !is_array($profile) || !is_string($profile['ehr'] ?? null)) {
                continue;
            }
            $profiles[$name] = [
                'label' => is_string($profile['label'] ?? null) ? $profile['label'] : $name,
                'ehr' => self::normalize($profile['ehr']),
                'standalone' => self::normalize(is_string($profile['standalone'] ?? null)
                    ? $profile['standalone']
                    : str_replace(' launch ', ' launch/patient ', ' ' . $profile['ehr'] . ' ')),
                'needs_consent_fix' => (bool)($profile['needs_consent_fix']
                    ?? ScopeCheck::consentMergeProblems($profile['ehr']) !== []),
            ];
        }

        return $profiles;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    /** @return array{label: string, ehr: string, standalone: string, needs_consent_fix: bool} */
    public static function get(string $name): array
    {
        $profiles = self::all();
        if (!isset($profiles[$name])) {
            throw new RuntimeException("Unknown scope profile '{$name}'. Available: " . implode(', ', array_keys($profiles)) . '.');
        }

        return $profiles[$name];
    }

    public static function defaultName(): string
    {
        $configured = Config::string('default_scope_profile');

        return $configured !== '' && isset(self::all()[$configured]) ? $configured : self::DEFAULT;
    }

    /**
     * The profile a site should register with: its own setting, else the default.
     *
     * @param array<string, mixed>|null $site
     */
    public static function forSite(?array $site): string
    {
        $name = is_array($site) ? ($site['scope_profile'] ?? null) : null;

        return is_string($name) && isset(self::all()[$name]) ? $name : self::defaultName();
    }

    /**
     * Scopes to request at launch for a registered client: the ones stored at registration,
     * falling back to the client's profile, then the site's.
     *
     * @param array<string, mixed>|null $client
     * @param array<string, mixed>|null $site
     * @return array{ehr: string, standalone: string, profile: string}
     */
    public static function forClient(?array $client, ?array $site): array
    {
        $profileName = is_string($client['scope_profile'] ?? null) && isset(self::all()[$client['scope_profile']])
            ? $client['scope_profile']
            : self::forSite($site);
        $profile = self::get($profileName);

        return [
            'ehr' => is_string($client['ehr_scopes'] ?? null) && $client['ehr_scopes'] !== '' ? $client['ehr_scopes'] : $profile['ehr'],
            'standalone' => is_string($client['standalone_scopes'] ?? null) && $client['standalone_scopes'] !== ''
                ? $client['standalone_scopes']
                : $profile['standalone'],
            'profile' => $profileName,
        ];
    }

    /** Everything a client must be registered for: EHR and standalone scopes together. */
    public static function union(string $ehr, string $standalone): string
    {
        return implode(' ', array_values(array_unique(array_merge(
            preg_split('/\s+/', $ehr, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            preg_split('/\s+/', $standalone, -1, PREG_SPLIT_NO_EMPTY) ?: []
        ))));
    }

    /** @return array{label: string, ehr: string, standalone: string, needs_consent_fix: bool} */
    private static function build(string $label, string $questionnaireResponse, bool $needsFix): array
    {
        return [
            'label' => $label,
            'ehr' => sprintf(self::BASE, 'launch') . ' ' . $questionnaireResponse,
            'standalone' => sprintf(self::BASE, 'launch/patient') . ' ' . $questionnaireResponse,
            'needs_consent_fix' => $needsFix,
        ];
    }

    private static function normalize(string $scopes): string
    {
        return implode(' ', preg_split('/\s+/', trim($scopes), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
