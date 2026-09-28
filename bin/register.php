<?php

/**
 * Command-line client registration (same operations as public/admin.php).
 *
 *   php bin/register.php --list                       sites from config.php and their status
 *   php bin/register.php --all                        register every unregistered site
 *   php bin/register.php --site=localhost             register (or re-register) one site
 *   php bin/register.php --iss=https://…/fhir         register with a FHIR base not in config
 *   php bin/register.php --site=localhost --check     verify discovery, scopes, secret and redirect URI
 *   php bin/register.php --site=localhost --repair    re-sync a saved secret that no longer matches
 *   php bin/register.php --site=localhost --client-id=… [--client-secret=…]
 *                                                     record a client made in OpenEMR's UI
 *   [--scopes=v1|v2]                                  scope profile (default: the site's, else v1);
 *                                                     v2 needs the OpenEMR consent-screen fix
 *   [--registration-endpoint=…]                       override the registration endpoint
 *
 * OpenEMR registers new clients disabled: enable them under Admin > System > API Clients.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @link      https://github.com/openemr/oe-smart-questionnaire
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

use OpenEMR\SmartQuestionnaire\Config;
use OpenEMR\SmartQuestionnaire\HttpClient;
use OpenEMR\SmartQuestionnaire\Registrar;
use OpenEMR\SmartQuestionnaire\Sites;

$options = getopt('', ['iss:', 'site:', 'all', 'list', 'check', 'repair', 'scopes:', 'client-id:', 'client-secret:', 'registration-endpoint:', 'help']);
$options = is_array($options) ? $options : [];
$usage = static function (int $code): never {
    $text = <<<TXT
Usage:
  php bin/register.php --list
  php bin/register.php --all
  php bin/register.php --site=<name> | --iss=<FHIR base URL>  [--check | --repair] [--scopes=v1|v2] [--registration-endpoint=<url>]
  php bin/register.php --site=<name> | --iss=<FHIR base URL>  --client-id=<id> [--client-secret=<secret>]

TXT;
    fwrite($code === 0 ? STDOUT : STDERR, $text);
    exit($code);
};
if (isset($options['help'])) {
    $usage(0);
}

$registrar = new Registrar(new HttpClient(Config::verifyTls()));
$endpointOverride = is_string($options['registration-endpoint'] ?? null) ? $options['registration-endpoint'] : null;
$profileOption = is_string($options['scopes'] ?? null) ? $options['scopes'] : null;
$report = static function (array $record, string $label): void {
    fwrite(STDOUT, "✓ {$label}: client {$record['client_id']} (" . ($record['scope_profile'] ?? '?') . " scopes)\n");
    if (isset($record['note'])) {
        fwrite(STDOUT, "  note: {$record['note']}\n");
    }
    if (isset($record['replaced_client_id'])) {
        fwrite(STDOUT, "  replaces {$record['replaced_client_id']} — disable the old client in OpenEMR\n");
    }
};

if (isset($options['list'])) {
    $clients = Config::clients();
    foreach (Sites::all() as $site) {
        $client = $clients[$site['iss']] ?? null;
        $profile = \OpenEMR\SmartQuestionnaire\ScopeProfiles::forClient($client, $site)['profile'];
        fwrite(STDOUT, str_pad($site['name'], 24) . ($client === null ? 'not registered' : 'client ' . $client['client_id'])
            . " ({$profile} scopes)\n    {$site['iss']}\n");
    }
    foreach (array_diff(array_keys($clients), array_column(Sites::all(), 'iss')) as $issuer) {
        fwrite(STDOUT, str_pad('(no site)', 24) . 'client ' . $clients[$issuer]['client_id'] . "\n    {$issuer}\n");
    }
    fwrite(STDOUT, "Launch URI:   " . Config::launchUri() . "\nRedirect URI: " . Config::redirectUri() . "\n");
    foreach (\OpenEMR\SmartQuestionnaire\ScopeProfiles::all() as $name => $profile) {
        fwrite(STDOUT, "Scope profile {$name}: " . Registrar::scopes($name) . "\n    {$profile['label']}\n");
    }
    exit(0);
}

if (isset($options['all'])) {
    $failed = false;
    foreach (Sites::all() as $site) {
        if (Config::clientFor($site['iss']) !== null) {
            fwrite(STDOUT, "- {$site['name']}: already registered\n");
            continue;
        }
        try {
            $report($registrar->register($site['iss'], $site['name'], $endpointOverride, $profileOption), $site['name']);
        } catch (Throwable $exception) {
            $failed = true;
            fwrite(STDERR, "✗ {$site['name']}: " . $exception->getMessage() . "\n");
        }
    }
    fwrite(STDOUT, "Next: enable each new client in its OpenEMR (Admin > System > API Clients).\n");
    exit($failed ? 1 : 0);
}

$siteName = is_string($options['site'] ?? null) ? $options['site'] : null;
if ($siteName !== null) {
    $site = Sites::get($siteName);
    if ($site === null) {
        fwrite(STDERR, "Unknown site '{$siteName}'. Configured: " . implode(', ', array_keys(Sites::all())) . "\n");
        exit(2);
    }
    $issuer = $site['iss'];
} elseif (is_string($options['iss'] ?? null)) {
    $issuer = Config::normalizeIssuer($options['iss']);
    $siteName = Sites::nameForIssuer($issuer);
} else {
    $usage(2);
}
$label = $siteName ?? $issuer;

if (isset($options['check'])) {
    $result = $registrar->verify($issuer, Config::clientFor($issuer));
    fwrite(STDOUT, implode("\n", $result['lines']) . "\n");
    exit($result['ok'] ? 0 : 1);
}

if (isset($options['repair'])) {
    try {
        fwrite(STDOUT, $registrar->repairSecret($issuer) . "\n");
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, '✗ ' . $exception->getMessage() . "\n");
        exit(1);
    }
}

if (is_string($options['client-id'] ?? null)) {
    $secret = is_string($options['client-secret'] ?? null) ? $options['client-secret'] : null;
    $report(Registrar::recordExisting($issuer, $siteName, $options['client-id'], $secret, $profileOption), $label);
    exit(0);
}

try {
    $report($registrar->register($issuer, $siteName, $endpointOverride, $profileOption), $label);
} catch (Throwable $exception) {
    fwrite(STDERR, '✗ ' . $exception->getMessage() . "\n");
    exit(1);
}
fwrite(STDOUT, "Launch URI:   " . Config::launchUri() . "\n"
    . "Redirect URI: " . Config::redirectUri() . "\n"
    . "Next: enable the client in OpenEMR (Admin > System > API Clients).\n");
