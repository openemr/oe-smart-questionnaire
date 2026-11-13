<?php

/**
 * Bearer-token FHIR R4 client for the launching server.
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
 * Bearer-token FHIR R4 client bound to the launching server's base URL (the SMART iss).
 */
final class FhirClient
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl,
        private readonly string $accessToken
    ) {
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /** @return array<string, mixed> */
    public function read(string $type, string $id): array
    {
        return $this->send('GET', $type . '/' . rawurlencode($id));
    }

    /**
     * The server's CapabilityStatement (GET [base]/metadata).
     *
     * @return array<string, mixed>
     */
    public function capabilities(): array
    {
        return $this->send('GET', 'metadata');
    }

    /**
     * @param array<string, string> $params
     * @return list<array<string, mixed>>
     */
    public function search(string $type, array $params): array
    {
        return self::entries($this->send('GET', $type . ($params === [] ? '' : '?' . http_build_query($params))), $type);
    }

    /**
     * Every page of a search: follows the Bundle's next links, which carry the original search
     * parameters, up to $maxPages. A next link that leaves this server's FHIR base is not followed,
     * so the access token is never sent elsewhere.
     *
     * @param array<string, string> $params
     * @return list<array<string, mixed>>
     */
    public function searchAll(string $type, array $params, int $maxPages = 20): array
    {
        $path = $type . ($params === [] ? '' : '?' . http_build_query($params));
        $resources = [];
        for ($page = 0; $page < $maxPages && $path !== null; $page++) {
            $bundle = $this->send('GET', $path);
            array_push($resources, ...self::entries($bundle, $type));
            $path = $this->nextPath($bundle, $path);
        }

        return $resources;
    }

    /**
     * The resources of the type in a search Bundle.
     *
     * @param array<string, mixed> $bundle
     * @return list<array<string, mixed>>
     */
    private static function entries(array $bundle, string $type): array
    {
        $resources = [];
        foreach (is_array($bundle['entry'] ?? null) ? $bundle['entry'] : [] as $entry) {
            $resource = is_array($entry) ? ($entry['resource'] ?? null) : null;
            if (is_array($resource) && ($resource['resourceType'] ?? null) === $type) {
                $resources[] = $resource;
            }
        }

        return $resources;
    }

    /**
     * The Bundle's next link as a path under this server's FHIR base, or null when there is none
     * or it points somewhere else.
     *
     * @param array<string, mixed> $bundle
     * @param string $currentPath the path (under the base) of the request that returned $bundle
     */
    private function nextPath(array $bundle, string $currentPath): ?string
    {
        foreach (is_array($bundle['link'] ?? null) ? $bundle['link'] : [] as $link) {
            $url = is_array($link) && ($link['relation'] ?? null) === 'next' ? ($link['url'] ?? null) : null;
            if (is_string($url) && $url !== '') {
                return self::pathUnderBase($this->baseUrl, $currentPath, $url);
            }
        }

        return null;
    }

    /**
     * Resolves a Bundle link (absolute, root-relative, relative, or query-only: FHIR's
     * Bundle.link.url is a uri) against the request it came from, and returns it as a path under
     * the FHIR base -- or null when it leaves the base, so the token is never sent elsewhere.
     */
    public static function pathUnderBase(string $baseUrl, string $currentPath, string $url): ?string
    {
        $base = rtrim($baseUrl, '/');
        $current = (string)strtok($base . '/' . $currentPath, '?');
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) === 1) {
            $absolute = $url;
        } elseif (str_starts_with($url, '//')) {
            $absolute = (string)parse_url($base, PHP_URL_SCHEME) . ':' . $url;
        } elseif (str_starts_with($url, '/')) {
            $origin = preg_match('~^[a-z][a-z0-9+.-]*://[^/?#]+~i', $base, $m) === 1 ? $m[0] : '';
            $absolute = $origin . $url;
        } elseif (str_starts_with($url, '?')) {
            $absolute = $current . $url;
        } else {
            $absolute = substr($current, 0, (int)strrpos($current, '/') + 1) . $url;
        }
        $prefix = $base . '/';
        if (!str_starts_with($absolute, $prefix) || strlen($absolute) === strlen($prefix)) {
            return null;
        }
        $path = substr($absolute, strlen($prefix));
        // A dot segment could climb out of the base after the prefix check.
        $segments = explode('/', (string)strtok($path, '?'));

        return in_array('..', $segments, true) || in_array('.', $segments, true) ? null : $path;
    }

    /**
     * POST (create) or PUT (update). Returns the saved resource when the server echoes it,
     * otherwise a stub carrying the id taken from the Location header.
     *
     * @param array<string, mixed> $resource
     * @return array<string, mixed>
     */
    public function save(array $resource): array
    {
        $type = (string)$resource['resourceType'];
        $id = is_string($resource['id'] ?? null) && $resource['id'] !== '' ? $resource['id'] : null;
        $method = $id === null ? 'POST' : 'PUT';
        $path = $id === null ? $type : $type . '/' . rawurlencode($id);
        $response = $this->send($method, $path, $resource, $location);

        if (($response['resourceType'] ?? null) === $type && is_string($response['id'] ?? null)) {
            return $response;
        }
        $savedId = $id ?? Refs::idFrom($location, $type);
        if ($savedId === null) {
            // OpenEMR's create echoes {"uuid": "..."} rather than the resource.
            $savedId = is_string($response['uuid'] ?? null) ? $response['uuid'] : (is_string($response['id'] ?? null) ? $response['id'] : null);
        }

        return ['id' => $savedId] + $resource;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?array $body = null, ?string &$location = null): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->accessToken,
            'Accept' => 'application/fhir+json',
        ];
        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/fhir+json';
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        try {
            $response = $this->http->request($method, $this->baseUrl . '/' . $path, $headers, $payload);
        } catch (\RuntimeException $exception) {
            // No response (connection refused, timeout): fail the way an HTTP error does, so callers
            // such as a partial vitals send can still report what was saved before it.
            throw new FhirException("{$method} {$path} failed: no response from the server.", 0, $exception);
        }
        $location = $response['headers']['location'] ?? $response['headers']['content-location'] ?? null;
        $decoded = $response['body'] !== '' ? json_decode($response['body'], true) : [];

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new FhirException(
                "{$method} {$path} failed (HTTP {$response['status']}): " . self::diagnostics($decoded, $response['body']),
                $response['status']
            );
        }

        return is_array($decoded) ? $decoded : [];
    }

    private static function diagnostics(mixed $decoded, string $raw): string
    {
        if (is_array($decoded)) {
            $issues = is_array($decoded['issue'] ?? null) ? $decoded['issue'] : [];
            $messages = [];
            foreach ($issues as $issue) {
                $text = is_array($issue) ? ($issue['diagnostics'] ?? $issue['details']['text'] ?? null) : null;
                if (is_string($text) && $text !== '') {
                    $messages[] = $text;
                }
            }
            if ($messages !== []) {
                return implode('; ', $messages);
            }
            if (is_array($decoded['validationErrors'] ?? null) && $decoded['validationErrors'] !== []) {
                $flat = [];
                array_walk_recursive($decoded['validationErrors'], static function ($value, $key) use (&$flat): void {
                    $flat[] = (is_string($key) ? $key . ': ' : '') . (is_scalar($value) ? (string)$value : '');
                });
                return 'validation failed — ' . implode('; ', $flat);
            }
            foreach (['message', 'error_description', 'error'] as $key) {
                if (is_string($decoded[$key] ?? null) && $decoded[$key] !== '') {
                    return $decoded[$key];
                }
            }
        }

        return $raw !== '' ? substr(strip_tags($raw), 0, 300) : 'no response body';
    }
}
