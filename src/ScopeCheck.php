<?php

/**
 * Scope validation against a server's advertised scopes and OpenEMR consent behavior.
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
 * Checks requested scopes against a server's advertised scopes_supported before registering,
 * so an unsupported scope is named up front instead of surfacing as a bare invalid_scope.
 *
 * Mirrors OpenEMR's rule: a requested resource scope is valid when ONE advertised scope for the
 * same context/resource contains all its permissions. Reads and writes are advertised separately
 * (user/X.rs and user/X.cud), so a combined user/X.crus fits neither and is rejected.
 */
final class ScopeCheck
{
    /**
     * @param array<string, mixed> $smartConfiguration
     * @return list<string>|null advertised scopes, or null when the server doesn't publish them
     */
    public static function advertised(array $smartConfiguration): ?array
    {
        $raw = $smartConfiguration['scopes_supported'] ?? null;
        if (!is_array($raw)) {
            return null;
        }
        // OpenEMR nests the list one level deep (["scopes_supported" => [[...]]]); flatten either shape.
        $flat = [];
        array_walk_recursive($raw, static function ($scope) use (&$flat): void {
            if (is_string($scope) && $scope !== '') {
                $flat[] = $scope;
            }
        });

        return $flat === [] ? null : array_values(array_unique($flat));
    }

    /**
     * @param list<string> $advertised
     * @return array<string, list<string>> unsupported requested scope => advertised scopes for that resource
     */
    public static function unsupported(string $requested, array $advertised): array
    {
        $byResource = [];
        $plain = [];
        foreach ($advertised as $scope) {
            $parsed = self::parse($scope);
            if ($parsed === null) {
                $plain[$scope] = true;
            } elseif ($parsed['restriction'] === '') {
                $byResource[$parsed['key']][] = ['scope' => $scope, 'perms' => $parsed['perms']];
            }
        }

        $problems = [];
        foreach (preg_split('/\s+/', $requested, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $scope) {
            $parsed = self::parse($scope);
            if ($parsed === null) {
                if (!isset($plain[$scope])) {
                    $problems[$scope] = [];
                }
                continue;
            }
            $candidates = $byResource[$parsed['key']] ?? [];
            $fits = false;
            foreach ($candidates as $candidate) {
                if (array_diff(str_split($parsed['perms']), str_split($candidate['perms'])) === []) {
                    $fits = true;
                    break;
                }
            }
            if (!$fits) {
                $problems[$scope] = array_column($candidates, 'scope');
            }
        }

        return $problems;
    }

    /**
     * Scopes an OpenEMR consent screen would merge and then drop.
     *
     * The consent form rebuilds each resource card's v2 actions into ONE scope string, but the
     * server keeps an approved scope only when a single requested scope contains it. So several
     * v2 scopes for one resource (e.g. .rs + .cu, required because registration rejects .crus)
     * come back as one merged scope that fits none of them, and the token loses that resource.
     * v1 .read/.write are rebuilt separately and survive.
     *
     * @return array<string, list<string>> context/resource => the v2 scopes requested for it
     */
    public static function consentMergeProblems(string $requested): array
    {
        $v2ByResource = [];
        foreach (preg_split('/\s+/', $requested, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $scope) {
            $parsed = self::parse($scope);
            if ($parsed === null || $parsed['restriction'] !== '' || preg_match('~\.(read|write|\*)$~', $scope) === 1) {
                continue;
            }
            $v2ByResource[$parsed['key']][] = $scope;
        }
        $problems = [];
        foreach ($v2ByResource as $key => $scopes) {
            if (count($scopes) < 2) {
                continue;
            }
            $union = implode('', array_unique(array_merge(...array_map(
                static fn(string $scope) => str_split((self::parse($scope)['perms'] ?? '')),
                $scopes
            ))));
            $containedInOne = false;
            foreach ($scopes as $scope) {
                if (array_diff(str_split($union), str_split((self::parse($scope)['perms'] ?? ''))) === []) {
                    $containedInOne = true;
                }
            }
            if (!$containedInOne) {
                $problems[$key] = $scopes;
            }
        }

        return $problems;
    }

    /**
     * Scopes the app asked for that the token did not grant.
     *
     * @return list<string>
     */
    public static function notGranted(string $requested, string $granted): array
    {
        $grantedList = preg_split('/\s+/', $granted, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $missing = array_keys(self::unsupported($requested, $grantedList));

        // A server may legitimately omit these from the returned scope string.
        return array_values(array_diff($missing, ['openid', 'fhirUser', 'launch', 'launch/patient']));
    }

    /**
     * "user/QuestionnaireResponse.cu" → key "user/QuestionnaireResponse", perms "cu".
     * v1 .read/.write/.* are normalized to their v2 permission sets.
     *
     * @return array{key: string, perms: string, restriction: string}|null null for non-resource scopes
     */
    public static function parse(string $scope): ?array
    {
        if (preg_match('~^(patient|user|system)/([A-Za-z*]+)\.([a-z*]+)(\?.*)?$~', $scope, $m) !== 1) {
            return null;
        }
        $perms = match ($m[3]) {
            'read' => 'rs',
            'write' => 'cud',
            '*' => 'cruds',
            default => $m[3],
        };

        return ['key' => $m[1] . '/' . $m[2], 'perms' => $perms, 'restriction' => $m[4] ?? ''];
    }

    /**
     * Human-readable report lines for unsupported scopes.
     *
     * @param array<string, list<string>> $problems
     * @return list<string>
     */
    public static function describe(array $problems): array
    {
        $lines = [];
        foreach ($problems as $scope => $offered) {
            $lines[] = $offered === []
                ? "{$scope} — not offered by this server."
                : "{$scope} — not offered as one scope; the server offers: " . implode(', ', $offered)
                    . '. Request those separately (e.g. reads and writes as two scopes).';
        }

        return $lines;
    }
}
