<?php

/**
 * Builds the QuestionnaireResponse the app writes from what the browser submits.
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
 * Turns a browser-submitted QuestionnaireResponse into the resource the app will write.
 *
 * The launch owns patient, questionnaire and which existing responses may be edited; the
 * browser only contributes answers and the requested status.
 */
final class ResponseStamper
{
    public const ALLOWED_STATUSES = ['in-progress', 'completed', 'amended'];

    /**
     * @param array<string, mixed> $submitted What the browser sent.
     * @param list<string> $editableIds Responses this launch loaded or created.
     * @param array<string, mixed>|null $stored The server's current copy when editing (re-read, never trusted from the client).
     * @return array<string, mixed>
     */
    public static function stamp(
        array $submitted,
        string $patientId,
        string $questionnaireId,
        array $editableIds,
        ?array $stored = null
    ): array {
        if (($submitted['resourceType'] ?? null) !== 'QuestionnaireResponse') {
            throw new InvalidArgumentException('Body must be a QuestionnaireResponse.');
        }
        $id = is_string($submitted['id'] ?? null) && $submitted['id'] !== '' ? $submitted['id'] : null;
        if ($id !== null && !in_array($id, $editableIds, true)) {
            throw new InvalidArgumentException('That QuestionnaireResponse is not part of this launch.');
        }
        if ($id !== null && $stored === null) {
            throw new InvalidArgumentException('The stored QuestionnaireResponse is required to update it.');
        }
        if ($stored !== null) {
            if (($stored['id'] ?? null) !== $id) {
                throw new InvalidArgumentException('Stored response does not match the submitted id.');
            }
            $storedSubject = is_array($stored['subject'] ?? null) ? ($stored['subject']['reference'] ?? null) : null;
            if (Refs::idFrom($storedSubject, 'Patient') !== $patientId) {
                throw new InvalidArgumentException('That QuestionnaireResponse belongs to a different patient.');
            }
            if (Refs::idFrom($stored['questionnaire'] ?? null, 'Questionnaire') !== $questionnaireId) {
                throw new InvalidArgumentException('That QuestionnaireResponse answers a different Questionnaire.');
            }
        }

        $resource = ['resourceType' => 'QuestionnaireResponse'];
        if ($id !== null) {
            $resource['id'] = $id;
        }
        // Keep the stored reference on edit (it may pin a version); otherwise a relative
        // reference the launching server resolves on its own.
        $resource['questionnaire'] = $stored !== null && is_string($stored['questionnaire'] ?? null)
            ? $stored['questionnaire']
            : 'Questionnaire/' . $questionnaireId;

        $status = is_string($submitted['status'] ?? null) ? $submitted['status'] : 'in-progress';
        $resource['status'] = in_array($status, self::ALLOWED_STATUSES, true) ? $status : 'in-progress';
        $resource['subject'] = ['reference' => 'Patient/' . $patientId];
        $resource['authored'] = gmdate('Y-m-d\TH:i:s\Z');

        // Server-side context on an existing response is carried from the stored copy only.
        foreach (['encounter', 'author', 'source', 'identifier', 'basedOn', 'partOf'] as $carried) {
            if ($stored !== null && array_key_exists($carried, $stored)) {
                $resource[$carried] = $stored[$carried];
            }
        }
        if (isset($submitted['item']) && is_array($submitted['item'])) {
            $resource['item'] = array_values($submitted['item']);
        }

        return $resource;
    }
}
