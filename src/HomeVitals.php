<?php

/**
 * Home vital signs: the Questionnaire a patient records home readings with, and the mapping of
 * its answers onto vital-sign Observations.
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

use InvalidArgumentException;

/**
 * OpenEMR takes only QuestionnaireResponse from patient apps today: its Vital Signs setting for
 * patient apps exists but says "Not accepted yet in this version", and the server publishes no
 * patient/Observation write scope. So readings go to the care team as answers to the Home vital
 * signs Questionnaire, held for staff review. Each item carries the LOINC code and UCUM unit of
 * its vital sign, so when a server grants patient/Observation.c the same answers are sent as
 * vital-sign Observations instead (toObservations()), with no change to the form.
 *
 * The definition is docs/home-vital-signs.questionnaire.json. A practice adds it to OpenEMR once
 * (the API Explorer's "Seed Home vital signs Questionnaire" does it); the app finds it by title
 * and its canonical. OpenEMR publishes its own url on create (a client url is ignored, as FHIR
 * create requires), so the canonical also travels as an identifier, which OpenEMR keeps.
 */
final class HomeVitals
{
    public const URL = 'http://open-emr.org/fhir/Questionnaire/home-vital-signs';
    public const TITLE = 'Home vital signs';
    public const IDENTIFIER_SYSTEM = 'urn:ietf:rfc:3986';

    private const CATEGORY = [[
        'coding' => [[
            'system' => 'http://terminology.hl7.org/CodeSystem/observation-category',
            'code' => 'vital-signs',
            'display' => 'Vital Signs',
        ]],
    ]];

    /**
     * The Questionnaire as the server has it, or null when the practice has not added it.
     *
     * @return array<string, mixed>|null
     */
    public static function find(FhirClient $fhir): ?array
    {
        foreach ($fhir->search('Questionnaire', ['title' => self::TITLE]) as $questionnaire) {
            if (self::identifies($questionnaire) && is_string($questionnaire['id'] ?? null)) {
                return $questionnaire;
            }
        }

        return null;
    }

    /**
     * Whether a Questionnaire is Home vital signs: its title, and the canonical as its url or as an
     * identifier (system urn:ietf:rfc:3986) -- the url alone does not survive a create on OpenEMR.
     *
     * @param array<string, mixed> $questionnaire
     */
    public static function identifies(array $questionnaire): bool
    {
        if (($questionnaire['title'] ?? null) !== self::TITLE) {
            return false;
        }
        if (($questionnaire['url'] ?? null) === self::URL) {
            return true;
        }
        foreach (is_array($questionnaire['identifier'] ?? null) ? $questionnaire['identifier'] : [] as $identifier) {
            if (is_array($identifier) && ($identifier['system'] ?? null) === self::IDENTIFIER_SYSTEM && ($identifier['value'] ?? null) === self::URL) {
                return true;
            }
        }

        return false;
    }

    /**
     * The bundled definition, for rendering the form when only Observations will be sent.
     *
     * @return array<string, mixed>
     */
    public static function bundled(): array
    {
        $file = dirname(__DIR__) . '/docs/home-vital-signs.questionnaire.json';
        $decoded = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Vital-sign Observations for the readings in a response: one per answered coded item, and one
     * blood pressure panel with systolic and diastolic as components. They are sent as
     * preliminary: a patient's reading, which OpenEMR also forces on a patient app's vital sign.
     * Keyed by the linkId they come from (the panel by its group's), so a retry after a partial
     * send can leave out the readings already saved. A value outside its item's minValue/maxValue
     * is refused.
     *
     * @param array<string, mixed> $questionnaire
     * @param array<string, mixed> $response
     * @return array<string, array<string, mixed>>
     * @throws InvalidArgumentException when nothing is entered or a value is out of range
     */
    public static function toObservations(array $questionnaire, array $response, string $patientId): array
    {
        $definitions = self::definitionsByLinkId(is_array($questionnaire['item'] ?? null) ? $questionnaire['item'] : []);
        $answers = self::answersByLinkId(is_array($response['item'] ?? null) ? $response['item'] : []);

        $taken = $answers['taken']['valueDateTime'] ?? null;
        $effective = is_string($taken) && $taken !== '' ? $taken : gmdate('Y-m-d\TH:i:s\Z');
        $note = is_string($answers['notes']['valueString'] ?? null) && trim($answers['notes']['valueString']) !== ''
            ? [['text' => trim($answers['notes']['valueString'])]]
            : null;
        $base = static fn(array $code): array => [
            'resourceType' => 'Observation',
            'status' => 'preliminary',
            'category' => self::CATEGORY,
            'code' => $code,
            'subject' => ['reference' => 'Patient/' . $patientId],
            'effectiveDateTime' => $effective,
        ];

        $observations = [];
        $components = [];
        $panel = null;
        $panelKey = null;
        foreach ($definitions as $linkId => $definition) {
            $quantity = isset($answers[$linkId]) ? self::quantity($answers[$linkId], $definition['item']) : null;
            $code = self::code($definition['item']);
            if ($quantity === null || $code === null) {
                continue;
            }
            $parentCode = $definition['parent'] !== null ? self::code($definition['parent']) : null;
            if ($parentCode !== null && self::isLoinc($parentCode, '85354-9')) {
                $panel ??= $parentCode;
                $panelKey ??= is_string($definition['parent']['linkId'] ?? null) ? $definition['parent']['linkId'] : 'bp';
                $components[] = ['code' => $code, 'valueQuantity' => $quantity];
                continue;
            }
            $observations[$linkId] = $base($code) + ['valueQuantity' => $quantity];
        }
        if ($panel !== null && $panelKey !== null) {
            $observations = [$panelKey => $base($panel) + ['component' => $components]] + $observations;
        }
        if ($observations === []) {
            throw new InvalidArgumentException('Enter at least one reading.');
        }
        // The patient's note goes with the readings once, not on every one of them.
        if ($note !== null) {
            $observations[array_key_first($observations)]['note'] = $note;
        }

        return $observations;
    }

    /**
     * Every non-group item by linkId, with the group it sits in.
     *
     * @param array<mixed> $items
     * @param array<mixed>|null $parent
     * @return array<string, array{item: array<mixed>, parent: array<mixed>|null}>
     */
    private static function definitionsByLinkId(array $items, ?array $parent = null): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['linkId'] ?? null)) {
                continue;
            }
            if (($item['type'] ?? null) === 'group') {
                $out += self::definitionsByLinkId(is_array($item['item'] ?? null) ? $item['item'] : [], $item);
                continue;
            }
            $out[$item['linkId']] = ['item' => $item, 'parent' => $parent];
        }

        return $out;
    }

    /**
     * The first answer of every answered item, by linkId, however deep.
     *
     * @param array<mixed> $items
     * @return array<string, array<mixed>>
     */
    private static function answersByLinkId(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $answer = is_array($item['answer'][0] ?? null) ? $item['answer'][0] : null;
            if ($answer !== null && is_string($item['linkId'] ?? null)) {
                $out[$item['linkId']] = $answer;
            }
            $out += self::answersByLinkId(is_array($item['item'] ?? null) ? $item['item'] : []);
        }

        return $out;
    }

    /**
     * The answer as a UCUM quantity, or null when it is not a number or the item has no unit.
     *
     * @param array<string, mixed> $answer
     * @param array<string, mixed> $item
     * @return ?array<string, mixed>
     * @throws InvalidArgumentException when the value is outside the item's minValue/maxValue
     */
    private static function quantity(array $answer, array $item): ?array
    {
        $value = $answer['valueInteger'] ?? $answer['valueDecimal'] ?? ($answer['valueQuantity']['value'] ?? null);
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        $unit = null;
        $min = null;
        $max = null;
        foreach (is_array($item['extension'] ?? null) ? $item['extension'] : [] as $extension) {
            if (!is_array($extension)) {
                continue;
            }
            $url = $extension['url'] ?? null;
            $bound = $extension['valueInteger'] ?? $extension['valueDecimal'] ?? null;
            if ($url === 'http://hl7.org/fhir/StructureDefinition/questionnaire-unit' && is_array($extension['valueCoding'] ?? null)) {
                $unit = $extension['valueCoding'];
            } elseif ($url === 'http://hl7.org/fhir/StructureDefinition/minValue' && (is_int($bound) || is_float($bound))) {
                $min = $bound;
            } elseif ($url === 'http://hl7.org/fhir/StructureDefinition/maxValue' && (is_int($bound) || is_float($bound))) {
                $max = $bound;
            }
        }
        if (($min !== null && $value < $min) || ($max !== null && $value > $max)) {
            $label = is_string($item['text'] ?? null) ? $item['text'] : (string)($item['linkId'] ?? 'A reading');
            throw new InvalidArgumentException(sprintf(
                '%s must be between %s and %s.',
                $label,
                $min !== null ? (string)$min : 'any',
                $max !== null ? (string)$max : 'any'
            ));
        }
        if (!is_array($unit) || !is_string($unit['code'] ?? null)) {
            return null;
        }

        return [
            'value' => $value,
            'unit' => (string)($unit['display'] ?? $unit['code']),
            'system' => (string)($unit['system'] ?? 'http://unitsofmeasure.org'),
            'code' => $unit['code'],
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return ?array{coding: list<array<string, mixed>>, text?: string}
     */
    private static function code(array $item): ?array
    {
        $codings = array_values(array_filter(is_array($item['code'] ?? null) ? $item['code'] : [], 'is_array'));
        if ($codings === []) {
            return null;
        }
        $code = ['coding' => $codings];
        if (is_string($item['text'] ?? null)) {
            $code['text'] = $item['text'];
        }

        return $code;
    }

    /** @param array{coding: list<array<string, mixed>>} $code */
    private static function isLoinc(array $code, string $loinc): bool
    {
        foreach ($code['coding'] as $coding) {
            if (($coding['system'] ?? null) === 'http://loinc.org' && ($coding['code'] ?? null) === $loinc) {
                return true;
            }
        }

        return false;
    }
}
