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
        $scope = ScopeProfiles::union($profile['ehr'], $profile['standalone']);
        $configuration = (new SmartClient($this->http))->discover($issuer);

        // Split v2 read/write scopes only survive an OpenEMR with the consent-screen fix. A profile
        // that says it needs the fix is allowed through (with a note); anything else is refused.
        $merges = ScopeCheck::consentMergeProblems($scope);
        if ($merges !== [] && !$profile['needs_consent_fix']) {
            throw new RuntimeException('Not registered: ' . implode(' ', self::describeMerges($merges)));
        }
        $advertised = ScopeCheck::advertised($configuration);
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
        $endpoint = $registrationEndpoint ?: ($configuration['registration_endpoint'] ?? null);
        if (!is_string($endpoint) || $endpoint === '') {
            // OpenEMR publishes registration in its OAuth2 discovery, not smart-configuration;
            // the endpoint sits beside authorize.
            $endpoint = (string)preg_replace('~/authorize/?$~', '/registration', (string)$configuration['authorization_endpoint']);
        }

        $clientName = self::clientName($siteName);
        $contact = Config::string('contact') !== '' ? Config::string('contact') : 'admin@example.org';
        $response = $this->http->request('POST', $endpoint, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], json_encode($this->payload($clientName, $contact, $scope), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
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
        ], static fn($value) => $value !== null && $value !== '');
        Config::saveClient($issuer, $record);
        if ($merges !== []) {
            $record['note'] = self::consentFixNote($merges);
        }

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
            $scope = ScopeProfiles::union($scopes['ehr'], $scopes['standalone']);
            $profile = ScopeProfiles::get($scopes['profile']);
            $lines[] = 'Scope profile: ' . $scopes['profile'] . ($client === null ? ' (would be used to register)' : '')
                . ' — ' . $scope;
            $merges = ScopeCheck::consentMergeProblems($scope);
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
        $lines[] = 'Whether it is enabled is only visible in the EHR (OpenEMR: Admin > System > API Clients).';

        return ['ok' => $ok, 'lines' => $lines];
    }
}
