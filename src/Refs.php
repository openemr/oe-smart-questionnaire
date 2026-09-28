<?php

/**
 * FHIR reference and SMART launch-context parsing.
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
 * FHIR reference and launch-context parsing.
 */
final class Refs
{
    /**
     * Id from a relative, absolute, or versioned reference/canonical of the given type.
     * "Questionnaire/abc", "https://x/fhir/Questionnaire/abc/_history/2", "…/Questionnaire/abc|3.0".
     */
    public static function idFrom(mixed $reference, string $type): ?string
    {
        if (!is_string($reference) || $reference === '') {
            return null;
        }
        $reference = preg_replace('~\|[^/]*$~', '', $reference) ?? $reference;
        $reference = preg_replace('~/_history/[^/]+$~', '', $reference) ?? $reference;
        $pattern = '~(?:^|/)' . preg_quote($type, '~') . '/([A-Za-z0-9\-.]{1,64})$~';

        return preg_match($pattern, $reference, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * Pull Questionnaire / QuestionnaireResponse ids from a SMART token response.
     * fhirContext items may be {reference}, {canonical}, {type, reference}, or legacy strings.
     *
     * @param array<string, mixed> $token
     * @return array{questionnaire: ?string, response: ?string}
     */
    public static function launchContext(array $token): array
    {
        $context = $token['fhirContext'] ?? [];
        if (is_string($context)) {
            $context = json_decode($context, true);
        }
        $found = ['questionnaire' => null, 'response' => null];
        foreach (is_array($context) ? $context : [] as $item) {
            $candidates = is_array($item)
                ? [$item['reference'] ?? null, $item['canonical'] ?? null]
                : [$item];
            foreach ($candidates as $candidate) {
                $found['questionnaire'] ??= self::idFrom($candidate, 'Questionnaire');
                $found['response'] ??= self::idFrom($candidate, 'QuestionnaireResponse');
            }
        }

        return $found;
    }

    /**
     * appContext is a string per SMART 2.x; OpenEMR sends JSON in it.
     *
     * @param array<string, mixed> $token
     * @return array<string, mixed>
     */
    public static function appContext(array $token): array
    {
        $raw = $token['appContext'] ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
