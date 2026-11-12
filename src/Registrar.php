<?php

/**
 * OAuth2 dynamic client registration, registration checks and secret repair.
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
 * OAuth2 dynamic client registration (RFC 7591) of this app with a SMART/OpenEMR server,
 * and a check of an existing registration (RFC 7592 read).
 *
 * The app registers as a confidential client: it keeps tokens server-side, and OpenEMR only
 * grants user/ scopes to confidential clients. OpenEMR creates new clients disabled, and has
 * no API to delete one, so re-registering leaves the previous client for an admin to disable.
 *
 * The same client also carries the patient sign-in scopes the server publishes
 * (ScopeProfiles::patientScopesFor()). A confidential client is the kind OpenEMR's
 * Patient App FHIR Writes mode lets write in both its "Confidential apps" and
 * "Confidential and public apps" settings.
 *
 * A second, public client can be registered beside it for patient sign-in only
 * (registerPublic()): no secret, PKCE only, the way a phone or browser app signs in. OpenEMR
 * gives a public client patient/ scopes only, enables one without launch automatically, and
 * lets it write only in the "Confidential and public apps" mode; in "Confidential apps" it
 * drops the write scopes from the token, and the app shows the patient a read-only home.
 *
 * A third, confidential client can be registered for the patient portal (registerPortal()):
 * OpenEMR's portal lists it under Health Apps and launches it with the portal patient. It has
 * `launch` and patient/ scopes only, and Config::portalLaunchUri() as its launch URI.
 */
final class Registrar
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    public static function clientName(?string $siteName): string
    {
        return Config::string('app_name') . ($siteName !== null && $siteName !== '' ? " ({$siteName})" : '');
    }

    /** Everything a client with this profile must be registered for (EHR + standalone launch). */
    public static function scopes(?string $profileName = null): string
    {
        $profile = ScopeProfiles::get($profileName ?? ScopeProfiles::defaultName());

        return ScopeProfiles::union($profile['ehr'], $profile['standalone']);
    }

    /**
     * Resolves the profile to use: explicit choice, else the site's, else the default.
     */
    public static function resolveProfile(?string $profileName, ?string $siteName): string
    {
        if ($profileName !== null && $profileName !== '') {
            ScopeProfiles::get($profileName); // throws on an unknown name

            return $profileName;
        }

        return ScopeProfiles::forSite($siteName !== null ? Sites::get($siteName) : null);
    }

    /**
     * What a v2 profile means on an OpenEMR without the consent-screen fix.
     *
     * @param array<string, list<string>> $merges
     */
    public static function consentFixNote(array $merges): string
    {
        return 'Registered with v2 scopes (' . implode('; ', array_map(static fn($scopes) => implode(' + ', $scopes), $merges)) . '). '
            . 'This needs an OpenEMR whose consent screen keeps v2 reads and writes separate. On an OpenEMR without that fix '
            . 'the consent screen merges them into one scope that is then dropped: the app warns "The server did not grant…" '
            . 'and saves fail with 401. Re-register with the v1 profile for such a server.';
    }

    /**
     * @param array<string, list<string>> $merges
     * @return list<string>
     */
    private static function describeMerges(array $merges): array
    {
        $lines = [];
        foreach ($merges as $key => $scopes) {
            $lines[] = implode(' + ', $scopes) . ' — OpenEMR\'s consent screen merges these into one scope that its '
                . "approval check then drops, so the token would get no {$key} access. Use {$key}.read and {$key}.write, "
                . 'or register with the v2 scope profile once the OpenEMR consent fix is installed.';
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    public function payload(string $clientName, string $contact, string $scope): array
    {
        return [
            'application_type' => 'private',
            'client_name' => $clientName,
            'contacts' => [$contact],
            'redirect_uris' => [Config::redirectUri()],
            'post_logout_redirect_uris' => [Config::launchUri()],
            // OpenEMR stores initiate_login_uri as the app's SMART launch URI.
            'initiate_login_uri' => Config::launchUri(),
            'launch_uris' => [Config::launchUri()],
            'grant_types' => ['authorization_code'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'client_secret_post',
            'scope' => $scope,
        ];
    }

    /**
     * A public client for patient sign-in: no secret, no launch URI (no EHR launch), patient/
     * scopes only. token_endpoint_auth_method is left out: OpenEMR does not accept "none", and
     * application_type "public" is what makes it issue no secret.
     *
     * @return array<string, mixed>
     */
    public function publicPayload(string $clientName, string $contact, string $scope): array
    {
        return [
            'application_type' => 'public',
            'client_name' => $clientName,
            'contacts' => [$contact],
            'redirect_uris' => [Config::redirectUri()],
            'post_logout_redirect_uris' => [Config::launchUri()],
            'grant_types' => ['authorization_code'],
            'response_types' => ['code'],
            'scope' => $scope,
        ];
    }

    /**
     * The patient portal client: confidential, launched by OpenEMR's portal at portalLaunchUri().
     * OpenEMR's portal offers a client with `launch`, a patient/ scope, no user/ or system/ scope
     * and a launch URI.
     *
     * @return array<string, mixed>
     */
    public function portalPayload(string $clientName, string $contact, string $scope): array
    {
        return [
            'application_type' => 'private',
            'client_name' => $clientName,
            'contacts' => [$contact],
            'redirect_uris' => [Config::redirectUri()],
            'post_logout_redirect_uris' => [Config::launchUri()],
            'initiate_login_uri' => Config::portalLaunchUri(),
            'launch_uris' => [Config::portalLaunchUri()],
            'grant_types' => ['authorization_code'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'client_secret_post',
            'scope' => $scope,
        ];
    }

    /**
     * Patient sign-in scopes for a portal launch: `launch` in place of `launch/patient`, so the
     * server takes the patient from the portal's launch rather than its own patient context.
     */
    public static function portalScopes(string $patientScope): string
    {
        $scopes = preg_split('/\s+/', $patientScope, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $scopes = array_map(static fn(string $scope): string => $scope === 'launch/patient' ? 'launch' : $scope, $scopes);

        return implode(' ', array_values(array_unique($scopes)));
    }

    public static function portalClientName(?string $siteName): string
    {
        return self::clientName($siteName) . ' - patient portal';
    }

    /**
     * Registers the patient portal client for an already registered server and saves it in that
     * server's record. Registering again replaces it (the old one stays in OpenEMR to disable).
     *
     * @return array<string, mixed>
     */
    public function registerPortal(string $issuer, ?string $siteName, ?string $registrationEndpoint = null): array
    {
        $issuer = Config::normalizeIssuer($issuer);
        $confidential = Config::clientFor($issuer);
        if ($confidential === null) {
            throw new RuntimeException("Register the confidential client for {$issuer} first; the patient portal client is kept beside it.");
        }
        $siteName ??= is_string($confidential['site'] ?? null) ? $confidential['site'] : null;
        $configuration = (new SmartClient($this->http))->discover($issuer);
        $advertised = ScopeCheck::advertised($configuration);
        $patientScope = ScopeProfiles::patientScopesFor($advertised);
        if ($patientScope === null) {
            throw new RuntimeException('Not registered: ' . self::patientNote(''));
        }
        if ($advertised !== null && !in_array('launch', $advertised, true)) {
            throw new RuntimeException('Not registered: the server does not publish the launch scope.');
        }
        $portalScope = self::portalScopes($patientScope);
        $clientName = self::portalClientName($siteName);
        $decoded = $this->post(
            self::registrationEndpoint($configuration, $registrationEndpoint),
            $this->portalPayload($clientName, self::contact(), $portalScope)
        );
        $previous = Config::portalClientFor($issuer);
        $record = array_filter([
            'client_id' => $decoded['client_id'],
            'client_secret' => $decoded['client_secret'] ?? null,
            'client_name' => $clientName,
            'token_endpoint_auth_method' => 'client_secret_post',
            'registration_access_token' => $decoded['registration_access_token'] ?? null,
            'registration_client_uri' => $decoded['registration_client_uri'] ?? null,
            'registered' => gmdate('c'),
            'replaced_client_id' => $previous['client_id'] ?? null,
            'patient_scopes' => $portalScope,
        ], static fn($value) => $value !== null && $value !== '');
        Config::savePortalClient($issuer, $record);
        $record['note'] = self::portalNote($portalScope);

        return $record;
    }

    /** What the portal client needs before patients see it. */
    public static function portalNote(string $portalScope): string
    {
        $canWrite = str_contains(' ' . $portalScope . ' ', ' patient/QuestionnaireResponse.cu ');

        return 'Patient portal client registered. Enable it in OpenEMR (Admin > System > API Clients); the patient portal '
            . 'then shows it under Health Apps. It needs an OpenEMR with the portal SMART launcher (openemr/openemr#7115). '
            . ($canWrite
                ? 'Patients who open it can send questionnaires and home vital signs, held for staff review.'
                : 'The server offers no patient write scopes now, so patients can read only.');
    }

    public static function publicClientName(?string $siteName): string
    {
        return self::clientName($siteName) . ' - patient, public';
    }

    /**
     * Registers the public patient client for an already registered server and saves it in that
     * server's record. Registering again replaces it (the old one stays in OpenEMR to disable).
     *
     * @return array<string, mixed>
     */
    public function registerPublic(string $issuer, ?string $siteName, ?string $registrationEndpoint = null): array
    {
        $issuer = Config::normalizeIssuer($issuer);
        $confidential = Config::clientFor($issuer);
        if ($confidential === null) {
            throw new RuntimeException("Register the confidential client for {$issuer} first; the public patient client is kept beside it.");
        }
        $siteName ??= is_string($confidential['site'] ?? null) ? $confidential['site'] : null;
        $configuration = (new SmartClient($this->http))->discover($issuer);
        $patientScope = ScopeProfiles::patientScopesFor(ScopeCheck::advertised($configuration));
        if ($patientScope === null) {
            throw new RuntimeException('Not registered: ' . self::patientNote(''));
        }
        $clientName = self::publicClientName($siteName);
        $decoded = $this->post(
            self::registrationEndpoint($configuration, $registrationEndpoint),
            $this->publicPayload($clientName, self::contact(), $patientScope)
        );
        $previous = Config::publicClientFor($issuer);
        $record = array_filter([
            'client_id' => $decoded['client_id'],
            'client_name' => $clientName,
            'token_endpoint_auth_method' => 'none',
            'registration_access_token' => $decoded['registration_access_token'] ?? null,
            'registration_client_uri' => $decoded['registration_client_uri'] ?? null,
            'registered' => gmdate('c'),
            'replaced_client_id' => $previous['client_id'] ?? null,
            'patient_scopes' => $patientScope,
        ], static fn($value) => $value !== null && $value !== '');
        Config::savePublicClient($issuer, $record);
        $record['note'] = self::publicNote($patientScope)
            . (is_string($decoded['client_secret'] ?? null) && $decoded['client_secret'] !== ''
                ? ' Warning: the server issued a secret, so it registered a confidential client; this app does not use it.'
                : '');

        return $record;
    }

    /** What the public client can do, given its patient scopes. */
    public static function publicNote(string $patientScope): string
    {
        $canWrite = str_contains(' ' . $patientScope . ' ', ' patient/QuestionnaireResponse.cu ');

        return 'Public patient client registered. OpenEMR enables a patient-only public client by itself; if it shows '
            . 'disabled under Admin > System > API Clients, enable it. '
            . ($canWrite
                ? 'Patients signed in with it can send questionnaires and home vital signs only while Patient App FHIR Writes '
                    . 'is set to "Confidential and public apps"; in "Confidential apps" the server leaves the write scopes out '
                    . 'and the app shows them a read-only home.'
                : 'The server offers no patient write scopes now, so patients can read only.');
    }

    private static function contact(): string
    {
        return Config::string('contact') !== '' ? Config::string('contact') : 'admin@example.org';
    }

    /** @param array<string, mixed> $configuration */
    private static function registrationEndpoint(array $configuration, ?string $override): string
    {
        $endpoint = $override ?: ($configuration['registration_endpoint'] ?? null);
        if (!is_string($endpoint) || $endpoint === '') {
            // OpenEMR publishes registration in its OAuth2 discovery, not smart-configuration;
            // the endpoint sits beside authorize.
            $endpoint = (string)preg_replace('~/authorize/?$~', '/registration', (string)$configuration['authorization_endpoint']);
        }

        return $endpoint;
    }

    /**
     * POSTs a registration and returns the server's answer, which has a client_id.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(string $endpoint, array $payload): array
    {
        $response = $this->http->request('POST', $endpoint, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $decoded = json_decode($response['body'], true);
        if (
            $response['status'] < 200 || $response['status'] >= 300
            || !is_array($decoded) || !is_string($decoded['client_id'] ?? null)
        ) {
            $reason = is_array($decoded)
                ? (string)($decoded['error_description'] ?? $decoded['error'] ?? $decoded['message'] ?? 'no client_id')
                    // OAuth servers put the specifics here, e.g. "Check the `user/X.crus` scope".
                    . (is_string($decoded['hint'] ?? null) && $decoded['hint'] !== '' ? ' — ' . $decoded['hint'] : '')
                : substr(strip_tags($response['body']), 0, 300);
            throw new RuntimeException("Registration at {$endpoint} failed (HTTP {$response['status']}): {$reason}");
        }

        return $decoded;
    }

    /**
     * Registers with the server at $issuer, saves the credentials, and returns the saved record.
     *
     * @return array<string, mixed>
     */
    public function register(
        string $issuer,
        ?string $siteName,
        ?string $registrationEndpoint = null,
        ?string $profileName = null
    ): array {
        $issuer = Config::normalizeIssuer($issuer);
        $profileName = self::resolveProfile($profileName, $siteName);
        $profile = ScopeProfiles::get($profileName);
        $clinicianScope = ScopeProfiles::union($profile['ehr'], $profile['standalone']);
        $configuration = (new SmartClient($this->http))->discover($issuer);

        // Split v2 read/write scopes only survive an OpenEMR with the consent-screen fix. A profile
        // that says it needs the fix is allowed through (with a note); anything else is refused.
        // Patient sign-in scopes are left out: the server publishes patient writes only as v2 .cu,
        // so they are split by necessity, and are kept only where the server offers them.
        $merges = ScopeCheck::consentMergeProblems($clinicianScope);
        if ($merges !== [] && !$profile['needs_consent_fix']) {
            throw new RuntimeException('Not registered: ' . implode(' ', self::describeMerges($merges)));
        }
        $advertised = ScopeCheck::advertised($configuration);
        $patientScope = ScopeProfiles::patientScopesFor($advertised) ?? '';
        $scope = ScopeProfiles::union($clinicianScope, $patientScope);
        if ($advertised !== null) {
            $problems = ScopeCheck::unsupported($scope, $advertised);
            if ($problems !== []) {
                throw new RuntimeException(
                    "Not registered: the server does not accept these {$profileName}-profile scopes — "
                    . implode(' ', ScopeCheck::describe($problems))
                    . ' Choose another scope profile or adjust scope_profiles in config.php.'
                );
            }
        }
        $clientName = self::clientName($siteName);
        $decoded = $this->post(
            self::registrationEndpoint($configuration, $registrationEndpoint),
            $this->payload($clientName, self::contact(), $scope)
        );

        $previous = Config::clientFor($issuer);
        $record = array_filter([
            'client_id' => $decoded['client_id'],
            'client_secret' => $decoded['client_secret'] ?? null,
            'client_name' => $clientName,
            'site' => $siteName,
            'token_endpoint_auth_method' => 'client_secret_post',
            'registration_access_token' => $decoded['registration_access_token'] ?? null,
            'registration_client_uri' => $decoded['registration_client_uri'] ?? null,
            'registered' => gmdate('c'),
            'replaced_client_id' => $previous['client_id'] ?? null,
            // Launches request exactly what was registered, whatever the config says later.
            'scope_profile' => $profileName,
            'ehr_scopes' => $profile['ehr'],
            'standalone_scopes' => $profile['standalone'],
            'patient_scopes' => $patientScope,
            // The public and portal patient clients are separate registrations; re-registering this one keeps them.
            'public' => $previous['public'] ?? null,
            'portal' => $previous['portal'] ?? null,
        ], static fn($value) => $value !== null && $value !== '');
        Config::saveClient($issuer, $record);
        $notes = $merges !== [] ? [self::consentFixNote($merges)] : [];
        $notes[] = self::patientNote($patientScope);
        $record['note'] = implode(' ', $notes);

        return $record;
    }

    /**
     * RFC 7592 read of the client's registration, authenticated with the registration token.
     *
     * @param array<string, mixed> $client
     * @return array{registration: array<string, mixed>, error: ?string}
     */
    public function readRegistration(array $client): array
    {
        try {
            $response = $this->http->request('GET', (string)$client['registration_client_uri'], [
                'Authorization' => 'Bearer ' . $client['registration_access_token'],
                'Accept' => 'application/json',
            ]);
        } catch (\Throwable $exception) {
            return ['registration' => [], 'error' => 'Could not read the registration: ' . $exception->getMessage()];
        }
        $decoded = json_decode($response['body'], true);
        if ($response['status'] === 200 && is_array($decoded) && ($decoded['client_id'] ?? null) === $client['client_id']) {
            return ['registration' => $decoded, 'error' => null];
        }
        $reason = is_array($decoded) ? (string)($decoded['error_description'] ?? $decoded['message'] ?? $decoded['error'] ?? '') : '';
        if (stripos($reason, 'decrypt') !== false) {
            return ['registration' => [], 'error' => "The server cannot decrypt client {$client['client_id']}'s stored secret "
                . '(its encryption keys changed since registration), so it can never authenticate. Register again.'];
        }

        return ['registration' => [], 'error' => "The server no longer recognizes client {$client['client_id']} (HTTP {$response['status']}"
            . ($reason !== '' ? ": {$reason}" : '') . '). Register again.'];
    }

    /**
     * Re-syncs the saved secret from the server's registration (authenticated with the
     * registration token, so it is the server's own current value).
     *
     * @return string what was done
     */
    public function repairSecret(string $issuer): string
    {
        $issuer = Config::normalizeIssuer($issuer);
        $client = Config::clientFor($issuer);
        if ($client === null) {
            throw new RuntimeException('Not registered with this server.');
        }
        if (!is_string($client['registration_client_uri'] ?? null) || !is_string($client['registration_access_token'] ?? null)) {
            throw new RuntimeException('This client was recorded manually; re-enter its secret with "Record a client" instead.');
        }
        $read = $this->readRegistration($client);
        if ($read['error'] !== null) {
            throw new RuntimeException($read['error']);
        }
        $secret = $read['registration']['client_secret'] ?? null;
        if (!is_string($secret) || $secret === '') {
            throw new RuntimeException('The server did not return a client secret.');
        }
        if (is_string($client['client_secret'] ?? null) && hash_equals($secret, $client['client_secret'])) {
            return "Client {$client['client_id']}: the saved secret already matches the server.";
        }
        $client['client_secret'] = $secret;
        $client['secret_repaired'] = gmdate('c');
        Config::saveClient($issuer, $client);

        return "Client {$client['client_id']}: saved secret updated from the server.";
    }

    /**
     * What patient sign-in will be able to do with the patient scopes a client was registered with.
     */
    public static function patientNote(string $patientScope): string
    {
        if ($patientScope === '') {
            return 'Patient sign-in is not available: the server publishes no launch/patient and patient/Patient.rs '
                . 'scopes (is the FHIR API on?).';
        }
        $writes = [];
        foreach (['QuestionnaireResponse' => 'questionnaires and home vital signs', 'Observation' => 'vital-sign Observations'] as $type => $what) {
            if (str_contains(' ' . $patientScope . ' ', " patient/{$type}.cu ")) {
                $writes[] = $what;
            }
        }

        return 'Patient sign-in registered. ' . ($writes === []
            ? 'Patients can read but not send anything: turn on Patient App FHIR Writes and its QuestionnaireResponse '
                . 'setting in OpenEMR (Admin > Config > Connectors), then register again.'
            : 'Patients can send ' . implode(' and ', $writes) . ', held for staff review.');
    }

    /**
     * Records a client registered by hand in the EHR (e.g. OpenEMR Admin > System > API Clients).
     *
     * @return array<string, mixed>
     */
    public static function recordExisting(
        string $issuer,
        ?string $siteName,
        string $clientId,
        ?string $clientSecret,
        ?string $profileName = null
    ): array {
        $profileName = self::resolveProfile($profileName, $siteName);
        $profile = ScopeProfiles::get($profileName);
        $record = array_filter([
            'client_id' => trim($clientId),
            'client_secret' => $clientSecret !== null && trim($clientSecret) !== '' ? trim($clientSecret) : null,
            'client_name' => self::clientName($siteName),
            'site' => $siteName,
            'token_endpoint_auth_method' => 'client_secret_post',
            'registered' => gmdate('c'),
            'recorded_manually' => true,
            // Must match the scopes the client was given in OpenEMR, or launches fail invalid_scope.
            'scope_profile' => $profileName,
            'ehr_scopes' => $profile['ehr'],
            'standalone_scopes' => $profile['standalone'],
            'public' => Config::clientFor($issuer)['public'] ?? null,
            'portal' => Config::clientFor($issuer)['portal'] ?? null,
        ], static fn($value) => $value !== null && $value !== '');
        Config::saveClient($issuer, $record);

        return $record;
    }

    /**
     * Checks discovery and, when the registration management URI is known, that the server still
     * has the client. Never throws; returns lines for display.
     *
     * @param array<string, mixed>|null $client
     * @return array{ok: bool, lines: list<string>}
     */
    public function verify(string $issuer, ?array $client): array
    {
        $lines = [];
        $ok = true;
        try {
            $configuration = (new SmartClient($this->http))->discover($issuer);
            $lines[] = 'SMART discovery OK — authorize: ' . $configuration['authorization_endpoint'];
            $lines[] = 'Token endpoint: ' . $configuration['token_endpoint'];
            $methods = $configuration['code_challenge_methods_supported'] ?? [];
            if (is_array($methods) && !in_array('S256', $methods, true)) {
                $lines[] = 'Warning: server does not advertise PKCE S256.';
            }
            $siteName = Sites::nameForIssuer($issuer);
            $scopes = ScopeProfiles::forClient($client, $siteName !== null ? Sites::get($siteName) : null);
            $scope = ScopeProfiles::union($scopes['ehr'], $scopes['standalone'], $scopes['patient']);
            $profile = ScopeProfiles::get($scopes['profile']);
            $lines[] = 'Scope profile: ' . $scopes['profile'] . ($client === null ? ' (would be used to register)' : '')
                . ' — ' . ScopeProfiles::union($scopes['ehr'], $scopes['standalone']);
            $merges = ScopeCheck::consentMergeProblems(ScopeProfiles::union($scopes['ehr'], $scopes['standalone']));
            if ($merges !== [] && $profile['needs_consent_fix']) {
                $lines[] = 'Note: ' . self::consentFixNote($merges);
            } elseif ($merges !== []) {
                $ok = false;
                array_push($lines, ...self::describeMerges($merges));
            }
            $advertised = ScopeCheck::advertised($configuration);
            if ($advertised === null) {
                $lines[] = 'The server does not publish scopes_supported; scopes could not be checked.';
            } else {
                $problems = ScopeCheck::unsupported($scope, $advertised);
                if ($problems === []) {
                    $lines[] = 'All configured scopes are offered by the server.';
                } else {
                    $ok = false;
                    $lines[] = 'Scopes the server will reject:';
                    array_push($lines, ...ScopeCheck::describe($problems));
                }
                // Patient sign-in: what this client has, against what the server offers today.
                $offered = ScopeProfiles::patientScopesFor($advertised) ?? '';
                if ($client !== null && $scopes['patient'] === '') {
                    $lines[] = 'Patient sign-in: this client was registered before patient sign-in existed. Register again to offer it.';
                } elseif ($client !== null && $offered !== '' && $offered !== $scopes['patient']) {
                    $lines[] = 'Patient sign-in: the server now offers ' . $offered . ' but this client has ' . $scopes['patient']
                        . '. Register again to pick up the change (e.g. Patient App FHIR Writes turned on or off).';
                } else {
                    $lines[] = 'Patient sign-in: ' . self::patientNote($client !== null ? $scopes['patient'] : $offered);
                }
            }
        } catch (\Throwable $exception) {
            return ['ok' => false, 'lines' => ['SMART discovery failed: ' . $exception->getMessage()]];
        }

        if ($client === null) {
            return ['ok' => false, 'lines' => [...$lines, 'Not registered with this server yet.']];
        }
        if (!is_string($client['registration_client_uri'] ?? null) || !is_string($client['registration_access_token'] ?? null)) {
            $lines[] = 'Client ' . $client['client_id'] . ' was recorded manually; its registration cannot be read back.';
            return ['ok' => $ok, 'lines' => $lines];
        }
        $read = $this->readRegistration($client);
        if ($read['error'] !== null) {
            return ['ok' => false, 'lines' => [...$lines, $read['error']]];
        }
        $server = $read['registration'];
        $lines[] = 'Registration confirmed: ' . ($server['client_name'] ?? $client['client_id'])
            . ' (' . ($server['application_type'] ?? 'unknown type') . ').';

        // The token endpoint authenticates with this secret; a mismatch fails every launch with
        // "Client authentication failed" after the consent screen.
        $serverSecret = $server['client_secret'] ?? null;
        $savedSecret = $client['client_secret'] ?? null;
        if (is_string($serverSecret) && $serverSecret !== '') {
            if (!is_string($savedSecret) || !hash_equals($serverSecret, $savedSecret)) {
                $ok = false;
                $lines[] = "The saved client secret does not match the server's, so token requests fail with "
                    . '"Client authentication failed". Use Repair secret (or: php bin/register.php --site=<name> --repair).';
            } else {
                $lines[] = 'Client secret matches the server.';
            }
        }

        $redirects = is_array($server['redirect_uris'] ?? null) ? $server['redirect_uris'] : [];
        if ($redirects !== [] && !in_array(Config::redirectUri(), $redirects, true)) {
            $ok = false;
            $lines[] = 'The server has redirect URI ' . implode(', ', $redirects) . ' but this app now uses '
                . Config::redirectUri() . ' (app_url changed?). Re-register.';
        }
        array_push($lines, ...$this->verifyPublic($issuer, $ok));
        array_push($lines, ...$this->verifyPortal($issuer, $ok));
        $lines[] = 'Whether they are enabled is only visible in the EHR (OpenEMR: Admin > System > API Clients).';

        return ['ok' => $ok, 'lines' => $lines];
    }

    /**
     * Lines about the patient portal client; clears $ok on a problem.
     *
     * @return list<string>
     */
    private function verifyPortal(string $issuer, bool &$ok): array
    {
        $portal = Config::portalClientFor($issuer);
        if ($portal === null) {
            return ['Patient portal client: not registered (optional; register it to launch the app from the patient portal).'];
        }
        if (!is_string($portal['registration_client_uri'] ?? null) || !is_string($portal['registration_access_token'] ?? null)) {
            return ['Patient portal client ' . $portal['client_id'] . ': its registration cannot be read back.'];
        }
        $read = $this->readRegistration($portal);
        if ($read['error'] !== null) {
            $ok = false;
            return ['Patient portal client: ' . $read['error']];
        }
        $server = $read['registration'];
        $lines = ['Patient portal client confirmed: ' . $portal['client_id'] . ' — ' . (string)($portal['patient_scopes'] ?? '')];
        $serverSecret = $server['client_secret'] ?? null;
        $savedSecret = $portal['client_secret'] ?? null;
        if (is_string($serverSecret) && $serverSecret !== '' && (!is_string($savedSecret) || !hash_equals($serverSecret, $savedSecret))) {
            $ok = false;
            $lines[] = 'The patient portal client\'s saved secret does not match the server\'s. Register the portal client again.';
        }
        $redirects = is_array($server['redirect_uris'] ?? null) ? $server['redirect_uris'] : [];
        if ($redirects !== [] && !in_array(Config::redirectUri(), $redirects, true)) {
            $ok = false;
            $lines[] = 'The patient portal client has redirect URI ' . implode(', ', $redirects) . ' but this app now uses '
                . Config::redirectUri() . '. Register it again.';
        }

        return $lines;
    }

    /**
     * Lines about the public patient client; clears $ok on a problem.
     *
     * @return list<string>
     */
    private function verifyPublic(string $issuer, bool &$ok): array
    {
        $public = Config::publicClientFor($issuer);
        if ($public === null) {
            return ['Public patient client: not registered (optional; register it to try patient sign-in as a public app).'];
        }
        if (!is_string($public['registration_client_uri'] ?? null) || !is_string($public['registration_access_token'] ?? null)) {
            return ['Public patient client ' . $public['client_id'] . ': its registration cannot be read back.'];
        }
        $read = $this->readRegistration($public);
        if ($read['error'] !== null) {
            $ok = false;
            return ['Public patient client: ' . $read['error']];
        }
        $server = $read['registration'];
        if (is_string($server['client_secret'] ?? null) && $server['client_secret'] !== '') {
            $ok = false;
            return ['Public patient client ' . $public['client_id'] . ' has a secret on the server, so the server treats it '
                . 'as confidential. Register the public client again.'];
        }
        $lines = ['Public patient client confirmed: ' . $public['client_id'] . ' (no secret; PKCE only) — '
            . (string)($public['patient_scopes'] ?? '')];
        $redirects = is_array($server['redirect_uris'] ?? null) ? $server['redirect_uris'] : [];
        if ($redirects !== [] && !in_array(Config::redirectUri(), $redirects, true)) {
            $ok = false;
            $lines[] = 'The public patient client has redirect URI ' . implode(', ', $redirects) . ' but this app now uses '
                . Config::redirectUri() . '. Register it again.';
        }

        return $lines;
    }
}
