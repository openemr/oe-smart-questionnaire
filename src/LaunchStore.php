<?php

/**
 * Per-launch SMART state kept in the PHP session.
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
 * Per-launch state kept in the PHP session, keyed by an opaque launch id.
 *
 * Keying by launch (not one global slot) lets a clinician have several patients' assessments
 * open in different EHR tabs without one launch overwriting another's token or context.
 */
final class LaunchStore
{
    private const KEY = 'sq_launches';
    private const STATE_KEY = 'sq_states';
    private const MAX_LAUNCHES = 12;
    private const MAX_AGE = 12 * 3600;

    /** @param array<string, mixed> $record */
    public static function create(array $record): string
    {
        self::prune();
        $launchId = SmartClient::base64Url(random_bytes(18));
        $record['created'] = time();
        $record['csrf'] = bin2hex(random_bytes(32));
        $_SESSION[self::KEY][$launchId] = $record;

        return $launchId;
    }

    /** @return array<string, mixed>|null */
    public static function get(mixed $launchId): ?array
    {
        if (!is_string($launchId) || $launchId === '') {
            return null;
        }
        $record = $_SESSION[self::KEY][$launchId] ?? null;

        return is_array($record) ? $record : null;
    }

    /** @param array<string, mixed> $values */
    public static function update(string $launchId, array $values): void
    {
        if (isset($_SESSION[self::KEY][$launchId]) && is_array($_SESSION[self::KEY][$launchId])) {
            $_SESSION[self::KEY][$launchId] = array_merge($_SESSION[self::KEY][$launchId], $values);
        }
    }

    public static function bindState(string $state, string $launchId): void
    {
        $_SESSION[self::STATE_KEY][$state] = $launchId;
    }

    /** One-shot: an OAuth state value can be redeemed exactly once. */
    public static function consumeState(mixed $state): ?string
    {
        if (!is_string($state) || $state === '') {
            return null;
        }
        $launchId = $_SESSION[self::STATE_KEY][$state] ?? null;
        unset($_SESSION[self::STATE_KEY][$state]);

        return is_string($launchId) ? $launchId : null;
    }

    private static function prune(): void
    {
        $launches = is_array($_SESSION[self::KEY] ?? null) ? $_SESSION[self::KEY] : [];
        $launches = array_filter(
            $launches,
            static fn($record) => is_array($record) && (time() - (int)($record['created'] ?? 0)) < self::MAX_AGE
        );
        uasort($launches, static fn($a, $b) => (int)$b['created'] <=> (int)$a['created']);
        $_SESSION[self::KEY] = array_slice($launches, 0, self::MAX_LAUNCHES - 1, true);

        $live = array_flip(array_keys($_SESSION[self::KEY]));
        $states = is_array($_SESSION[self::STATE_KEY] ?? null) ? $_SESSION[self::STATE_KEY] : [];
        $_SESSION[self::STATE_KEY] = array_filter(
            $states,
            static fn($launchId) => is_string($launchId) && isset($live[$launchId])
        );
    }
}
