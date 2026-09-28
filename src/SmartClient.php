<?php

/**
 * SMART App Launch client: discovery, PKCE authorize URL and code exchange.
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
 * SMART App Launch 2.x client: discovery, PKCE authorize, and code exchange.
 */
final class SmartClient
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /** @return array<string, mixed> */
    public function discover(string $issuer): array
    {
        $configuration = $this->http->getJson(Config::normalizeIssuer($issuer) . '/.well-known/smart-configuration');
        foreach (['authorization_endpoint', 'token_endpoint'] as $required) {
            if (!is_string($configuration[$required] ?? null) || $configuration[$required] === '') {
                throw new RuntimeException("SMART configuration for {$issuer} omits {$required}.");
            }
        }

        return $configuration;
    }

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function newVerifier(): string
    {
        return self::base64Url(random_bytes(64));
    }

    public static function challengeFor(string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public static function authorizeUrl(
        array $configuration,
        string $clientId,
        string $issuer,
        string $scope,
        string $state,
        string $verifier,
        ?string $launch
    ): string {
        $params = [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => Config::redirectUri(),
            'scope' => $scope,
            'state' => $state,
            'aud' => Config::normalizeIssuer($issuer),
            'code_challenge' => self::challengeFor($verifier),
            'code_challenge_method' => 'S256',
        ];
        if ($launch !== null && $launch !== '') {
            $params['launch'] = $launch;
        }
        $endpoint = (string)$configuration['authorization_endpoint'];

        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($params);
    }

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    public function exchangeCode(array $configuration, array $client, string $code, string $verifier): array
    {
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => Config::redirectUri(),
            'code_verifier' => $verifier,
            'client_id' => (string)$client['client_id'],
        ];
        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ];
        $secret = $client['client_secret'] ?? null;
        if (is_string($secret) && $secret !== '') {
            if (($client['token_endpoint_auth_method'] ?? 'client_secret_post') === 'client_secret_basic') {
                $headers['Authorization'] = 'Basic '
                    . base64_encode(rawurlencode((string)$client['client_id']) . ':' . rawurlencode($secret));
            } else {
                $form['client_secret'] = $secret;
            }
        }
        $response = $this->http->request(
            'POST',
            (string)$configuration['token_endpoint'],
            $headers,
            http_build_query($form)
        );
        $decoded = json_decode($response['body'], true);
        if (
            $response['status'] < 200 || $response['status'] >= 300 || !is_array($decoded)
            || !is_string($decoded['access_token'] ?? null)
        ) {
            $reason = is_array($decoded)
                ? (string)($decoded['error_description'] ?? $decoded['error'] ?? 'no access_token')
                    . (is_string($decoded['hint'] ?? null) && $decoded['hint'] !== '' ? ' — ' . $decoded['hint'] : '')
                : 'unreadable response';
            throw new RuntimeException("Token request failed (HTTP {$response['status']}): {$reason}");
        }

        return $decoded;
    }
}
