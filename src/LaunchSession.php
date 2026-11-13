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

    /** OpenEMR tags a patient app's write that waits for staff review with this. */
    public const HELD_TAG_SYSTEM = 'http://open-emr.org/fhir/CodeSystem/patient-write';
    public const HELD_TAG_CODE = 'held-for-review';

    /** What a patient sends, by the resource type it travels as. */
    private const WRITE_WORDS = [
        'QuestionnaireResponse' => 'questionnaire answers',
        'Observation' => 'readings as vital signs',
    ];

    /** @return array<string, mixed> */
    public function token(): array
    {
        return (array)$this->record['token'];
    }

    /** A patient signed in to work on their own record (launch.php?mode=patient). */
    public function isPatient(): bool
    {
        return ($this->record['mode'] ?? null) === 'patient';
    }

    /** A patient opened the app from OpenEMR's patient portal (launch.php?mode=portal). */
    public function isPortalLaunch(): bool
    {
        return ($this->record['client_type'] ?? null) === 'portal';
    }

    /** Signed in through the public patient client (no secret, PKCE only). */
    public function isPublicClient(): bool
    {
        return ($this->record['client_type'] ?? null) === 'public';
    }

    /**
     * For a patient sign-in: why the token has less than was asked for, in words for the
     * patient, or null when it has everything. The server leaves write scopes out when its
     * Patient App FHIR Writes mode does not cover this kind of client, so a public sign-in under
     * "Confidential apps" is told to use the confidential sign-in, not to "leave them checked".
     */
    public function patientGrantNotice(): ?string
    {
        $requested = (string)($this->record['requested_scope'] ?? '');
        $granted = $this->token()['scope'] ?? null;
        if ($requested === '' || !is_string($granted)) {
            return null;
        }
        $missing = ScopeCheck::notGranted($requested, $granted);
        if ($missing === []) {
            return null;
        }
        $writes = ScopeCheck::writes($missing);
        $others = array_values(array_diff($missing, $writes));
        $parts = [];
        // Some sending still allowed: name what is not, without guessing at the practice's setting.
        $stillSends = array_values(array_filter(['QuestionnaireResponse', 'Observation'], fn(string $type): bool => $this->canCreate($type)));
        if ($writes !== [] && $stillSends !== []) {
            $notSent = array_values(array_unique(array_map(
                static fn(string $scope): string => self::WRITE_WORDS[substr(strrchr(ScopeCheck::parse($scope)['key'] ?? '', '/') ?: '/', 1)] ?? $scope,
                $writes
            )));
            $parts[] = 'You can send ' . implode(' and ', array_map(static fn(string $type): string => self::WRITE_WORDS[$type], $stillSends))
                . ', but this sign-in cannot send ' . implode(' or ', $notSent) . '.';
        } elseif ($writes !== []) {
            $parts[] = $this->isPublicClient()
                ? 'You can look, but not send: this practice accepts information only from confidential apps, and you '
                    . 'signed in with the public app. Use the confidential patient sign-in to send.'
                : 'Sending was not allowed at sign-in (a permission was unchecked, or the practice has stopped accepting '
                    . 'information from apps). Sign in again and leave the permissions checked.';
        }
        if ($others !== []) {
            $parts[] = 'Some permissions were not given (' . implode(', ', $others) . '), so parts of this page are hidden.';
        }

        return implode(' ', $parts);
    }

    /** @return list<string> */
    public function grantedScopes(): array
    {
        $scope = $this->token()['scope'] ?? '';

        return is_string($scope) ? (preg_split('/\s+/', trim($scope), -1, PREG_SPLIT_NO_EMPTY) ?: []) : [];
    }

    /**
     * Whether the token lets this app create the type: a patient/ (patient sign-in) or user/
     * scope for it, v1 (.write, .*) or v2 with c.
     */
    public function canCreate(string $type): bool
    {
        return $this->grants($type, static fn(string $perms): bool => in_array($perms, ['write', '*'], true) || str_contains($perms, 'c'));
    }

    /** Whether the token can read the type: v1 (.read, .*) or v2 with r or s. */
    public function canRead(string $type): bool
    {
        return $this->grants($type, static fn(string $perms): bool => in_array($perms, ['read', '*'], true) || strpbrk($perms, 'rs') !== false);
    }

    /** @param callable(string): bool $allows */
    private function grants(string $type, callable $allows): bool
    {
        $context = $this->isPatient() ? 'patient' : 'user';
        $pattern = '~^' . $context . '/' . preg_quote($type, '~') . '\.(read|write|\*|c?r?u?d?s?)(?:\?.*)?$~';
        foreach ($this->grantedScopes() as $scope) {
            if (preg_match($pattern, $scope, $match) === 1 && $match[1] !== '' && $allows($match[1])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a resource is this patient app's write, still waiting for staff review. OpenEMR
     * answers such a write (on create, read and search) with a held-for-review tag; once staff
     * approve it, the same id reads as an ordinary resource in the chart.
     *
     * @param array<string, mixed> $resource
     */
    public static function isHeld(array $resource): bool
    {
        foreach (is_array($resource['meta']['tag'] ?? null) ? $resource['meta']['tag'] : [] as $tag) {
            if (is_array($tag) && ($tag['system'] ?? null) === self::HELD_TAG_SYSTEM && ($tag['code'] ?? null) === self::HELD_TAG_CODE) {
                return true;
            }
        }

        return false;
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
     * All of the patient's responses, newest first, from every page of the search. For a patient
     * app this includes its own writes still held for review: OpenEMR adds those only to a search
     * by _id, patient, subject and _count, so this never asks the server to sort, and sorts here
     * instead.
     *
     * @return list<array<string, mixed>>
     */
    public function allResponses(): array
    {
        try {
            $responses = $this->fhir->searchAll('QuestionnaireResponse', [
                'patient' => $this->patientId,
                '_count' => '100',
            ]);
        } catch (FhirException) {
            // Servers that only support subject= for this resource.
            $responses = $this->fhir->searchAll('QuestionnaireResponse', [
                'subject' => 'Patient/' . $this->patientId,
                '_count' => '100',
            ]);
        }
        $mine = array_values(array_filter(
            $responses,
            fn(array $response) => Refs::idFrom($response['subject']['reference'] ?? null, 'Patient') === $this->patientId
        ));
        $when = static fn(array $r): string => (string)($r['authored'] ?? $r['meta']['lastUpdated'] ?? '');
        usort($mine, static fn(array $a, array $b) => strcmp($when($b), $when($a)));

        return $mine;
    }

    /**
     * Patient's responses to one questionnaire, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function responsesFor(string $questionnaireId): array
    {
        return array_values(array_filter(
            $this->allResponses(),
            static fn(array $response) => Refs::idFrom($response['questionnaire'] ?? null, 'Questionnaire') === $questionnaireId
        ));
    }
}
