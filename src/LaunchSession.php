<?php

/**
 * An authorized SMART launch: its stored state plus a FHIR client.
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
 * An authorized launch: its stored record plus a FHIR client for the launching server.
 */
final class LaunchSession
{
    /**
     * @param array<string, mixed> $record
     */
    private function __construct(
        public readonly string $launchId,
        public readonly array $record,
        public readonly FhirClient $fhir,
        public readonly string $patientId
    ) {
    }

    /**
     * Resolves ?lid= to an authorized launch, or returns an error message.
     *
     * @return self|string
     */
    public static function resolve(mixed $launchId): self|string
    {
        $record = LaunchStore::get($launchId);
        if (!is_string($launchId) || $record === null) {
            return 'This launch is no longer active. Launch the app again from the EHR.';
        }
        $token = is_array($record['token'] ?? null) ? $record['token'] : null;
        if ($token === null || !is_string($token['access_token'] ?? null)) {
            return 'This launch was never authorized. Launch the app again from the EHR.';
        }
        if ((int)($record['expires_at'] ?? 0) <= time()) {
            return 'The access token for this launch has expired. Launch the app again from the EHR.';
        }
        $patientId = $token['patient'] ?? null;
        if (!is_string($patientId) || $patientId === '') {
            return 'The server did not supply a patient in the launch context.';
        }

        return new self(
            $launchId,
            $record,
            new FhirClient(new HttpClient(Config::verifyTls()), (string)$record['issuer'], $token['access_token']),
            $patientId
        );
    }

    /** @return array<string, mixed> */
    public function token(): array
    {
        return (array)$this->record['token'];
    }

    /** @return list<string> */
    public function editableIds(): array
    {
        $ids = $this->record['editable_ids'] ?? [];

        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }

    public function questionnaireId(): ?string
    {
        $id = $this->record['questionnaire_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * OpenEMR version from the server's CapabilityStatement software, read once per launch.
     * Empty when the server is not OpenEMR or does not say.
     */
    public function openemrVersion(): string
    {
        $cached = $this->record['openemr_version'] ?? null;
        if (is_string($cached)) {
            return $cached;
        }
        $version = '';
        try {
            $software = $this->fhir->capabilities()['software'] ?? null;
            if (
                is_array($software) && is_string($software['version'] ?? null)
                && is_string($software['name'] ?? null) && stripos($software['name'], 'openemr') !== false
            ) {
                $version = trim($software['version']);
            }
        } catch (FhirException | \RuntimeException) {
            // Not worth failing the workspace over; the runtime just omits the version tag.
        }
        $this->remember(['openemr_version' => $version]);

        return $version;
    }

    public function csrf(): string
    {
        return (string)($this->record['csrf'] ?? '');
    }

    /** @param array<string, mixed> $values */
    public function remember(array $values): void
    {
        LaunchStore::update($this->launchId, $values);
    }

    /**
     * Patient's responses to one questionnaire, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function responsesFor(string $questionnaireId): array
    {
        try {
            $responses = $this->fhir->search('QuestionnaireResponse', [
                'patient' => $this->patientId,
                '_count' => '100',
            ]);
        } catch (FhirException) {
            // Servers that only support subject= for this resource.
            $responses = $this->fhir->search('QuestionnaireResponse', [
                'subject' => 'Patient/' . $this->patientId,
                '_count' => '100',
            ]);
        }
        $matching = array_values(array_filter(
            $responses,
            fn(array $response) => Refs::idFrom($response['questionnaire'] ?? null, 'Questionnaire') === $questionnaireId
                && Refs::idFrom($response['subject']['reference'] ?? null, 'Patient') === $this->patientId
        ));
        usort(
            $matching,
            static fn(array $a, array $b) => strcmp((string)($b['authored'] ?? ''), (string)($a['authored'] ?? ''))
        );

        return $matching;
    }
}
