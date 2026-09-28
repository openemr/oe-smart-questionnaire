<?php

/**
 * Minimal cURL HTTP client.
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
 * Minimal cURL wrapper — the app deliberately has no Composer dependencies.
 */
final class HttpClient
{
    public function __construct(private readonly bool $verifyTls = true)
    {
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if ($method === '') {
            throw new RuntimeException('HTTP method is required.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException("Cannot initialize request to {$url}");
        }
        $responseHeaders = [];
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($raw)) {
            throw new RuntimeException("{$method} {$url} failed: {$error}");
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $raw];
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function getJson(string $url, array $headers = []): array
    {
        $response = $this->request('GET', $url, $headers + ['Accept' => 'application/json']);
        $decoded = json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($decoded)) {
            throw new RuntimeException("GET {$url} returned HTTP {$response['status']}");
        }

        return $decoded;
    }
}
