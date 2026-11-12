<?php

/**
 * Read-only views of the signed-in patient's record for the patient pages.
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

final class PatientRecord
{
    /**
     * The patient's vital signs, newest first, as display rows. OpenEMR returns the panel and
     * each member; the panel (85353-1) only repeats its members, so it is left out.
     *
     * @return list<array{when: string, name: string, value: string}>
     */
    public static function vitalSigns(LaunchSession $session, int $limit = 15): array
    {
        $observations = $session->fhir->search('Observation', [
            'patient' => $session->patientId,
            'category' => 'vital-signs',
            '_count' => '200',
        ]);
        $rows = [];
        foreach ($observations as $observation) {
            if (self::hasCode($observation, '85353-1')) {
                continue;
            }
            $value = self::value($observation);
            if ($value === '') {
                continue;
            }
            $rows[] = [
                'when' => (string)($observation['effectiveDateTime'] ?? $observation['effectivePeriod']['start'] ?? ''),
                'name' => self::conceptText($observation['code'] ?? null),
                'value' => $value,
            ];
        }
        usort($rows, static fn(array $a, array $b) => strcmp($b['when'], $a['when']));

        return array_slice($rows, 0, $limit);
    }

    /**
     * Names of the patient's resources of a type (allergies, conditions), for a short list.
     *
     * @return list<string>
     */
    public static function names(LaunchSession $session, string $type): array
    {
        $names = [];
        foreach ($session->fhir->search($type, ['patient' => $session->patientId, '_count' => '100']) as $resource) {
            $name = self::conceptText($resource['code'] ?? null);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    public static function conceptText(mixed $concept): string
    {
        if (!is_array($concept)) {
            return '';
        }
        if (is_string($concept['text'] ?? null) && $concept['text'] !== '') {
            return $concept['text'];
        }
        foreach (is_array($concept['coding'] ?? null) ? $concept['coding'] : [] as $coding) {
            if (is_array($coding) && is_string($coding['display'] ?? null) && $coding['display'] !== '') {
                return $coding['display'];
            }
        }

        return '';
    }

    /** @param array<string, mixed> $observation */
    private static function value(array $observation): string
    {
        $components = is_array($observation['component'] ?? null) ? $observation['component'] : [];
        if ($components !== []) {
            // Blood pressure reads as systolic/diastolic.
            $systolic = $diastolic = null;
            foreach ($components as $component) {
                if (!is_array($component)) {
                    continue;
                }
                if (self::hasCode($component, '8480-6')) {
                    $systolic = $component['valueQuantity'] ?? null;
                } elseif (self::hasCode($component, '8462-4')) {
                    $diastolic = $component['valueQuantity'] ?? null;
                }
            }
            if (is_array($systolic) && is_array($diastolic)) {
                return self::number($systolic['value'] ?? null) . '/' . self::number($diastolic['value'] ?? null)
                    . ' ' . (string)($systolic['unit'] ?? 'mmHg');
            }
        }
        $quantity = $observation['valueQuantity'] ?? null;
        if (is_array($quantity) && isset($quantity['value'])) {
            return trim(self::number($quantity['value']) . ' ' . (string)($quantity['unit'] ?? $quantity['code'] ?? ''));
        }

        return '';
    }

    private static function number(mixed $value): string
    {
        return is_int($value) || is_float($value) ? rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.') : '?';
    }

    /** @param array<string, mixed> $resource */
    private static function hasCode(array $resource, string $loinc): bool
    {
        foreach (is_array($resource['code']['coding'] ?? null) ? $resource['code']['coding'] : [] as $coding) {
            if (is_array($coding) && ($coding['code'] ?? null) === $loinc) {
                return true;
            }
        }

        return false;
    }
}
