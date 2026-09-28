<?php

/**
 * SMART launch endpoint (register this as the client's launch URI).
 *
 *  - EHR launch:        launch.php?iss=<fhir base>&launch=<opaque>
 *  - Standalone launch: launch.php?iss=<fhir base>   (the server shows its patient picker)
 *  - No parameters:     lists the registered servers for a standalone launch.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @link      https://github.com/openemr/oe-smart-questionnaire
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use OpenEMR\SmartQuestionnaire\Config;
use OpenEMR\SmartQuestionnaire\HttpClient;
use OpenEMR\SmartQuestionnaire\LaunchStore;
use OpenEMR\SmartQuestionnaire\ScopeProfiles;
use OpenEMR\SmartQuestionnaire\Session;
use OpenEMR\SmartQuestionnaire\Sites;
use OpenEMR\SmartQuestionnaire\SmartClient;
use OpenEMR\SmartQuestionnaire\View;

Session::start();

$issuer = filter_input(INPUT_GET, 'iss', FILTER_UNSAFE_RAW);
$launch = filter_input(INPUT_GET, 'launch', FILTER_UNSAFE_RAW);

if (!is_string($issuer) || $issuer === '') {
    View::header('Launch');
    echo '<h1 class="h4 mb-3">' . h(Config::string('app_name')) . '</h1>';
    $clients = Config::clients();
    if ($clients === []) {
        echo '<div class="alert alert-warning">No FHIR servers are registered yet. Register one on the '
            . '<a href="admin.php">administration page</a> or with <code>php bin/register.php</code>.</div>';
    } else {
        echo '<p>Choose a server for a standalone launch. From inside the EHR, launch the app from the '
            . 'patient\'s assessments instead.</p><div class="list-group">';
        foreach (array_keys($clients) as $registeredIssuer) {
            echo '<a class="list-group-item list-group-item-action" href="launch.php?' . h(http_build_query(['iss' => $registeredIssuer])) . '">'
                . h($registeredIssuer) . '</a>';
        }
        echo '</div>';
    }
    echo '<p class="small mt-4"><a href="admin.php">Client registration (administrators)</a></p>';
    View::footer();
    exit;
}

$issuer = Config::normalizeIssuer($issuer);
if (filter_var($issuer, FILTER_VALIDATE_URL) === false || !preg_match('~^https?://~i', $issuer)) {
    View::error(400, 'Launch failed', 'The iss parameter is not a valid FHIR base URL.');
}
$client = Config::clientFor($issuer);
if ($client === null) {
    // Refusing unknown issuers keeps the app from sending codes or tokens to a server an
    // attacker names in the launch URL.
    View::error(400, 'Launch failed', "This app is not registered with {$issuer}.");
}

$isEhrLaunch = is_string($launch) && $launch !== '';
try {
    $configuration = (new SmartClient(new HttpClient(Config::verifyTls())))->discover($issuer);
} catch (Throwable $exception) {
    View::error(502, 'Launch failed', 'SMART discovery failed: ' . $exception->getMessage());
}

$state = SmartClient::base64Url(random_bytes(32));
$verifier = SmartClient::newVerifier();
$launchId = LaunchStore::create([
    'issuer' => $issuer,
    'mode' => $isEhrLaunch ? 'ehr' : 'standalone',
    'smart_configuration' => $configuration,
    'verifier' => $verifier,
]);
LaunchStore::bindState($state, $launchId);

// Request exactly what this client was registered with (see ScopeProfiles).
$siteName = Sites::nameForIssuer($issuer);
$clientScopes = ScopeProfiles::forClient($client, $siteName !== null ? Sites::get($siteName) : null);
$scope = $isEhrLaunch ? $clientScopes['ehr'] : $clientScopes['standalone'];
LaunchStore::update($launchId, ['requested_scope' => $scope]);
header('Location: ' . SmartClient::authorizeUrl(
    $configuration,
    (string)$client['client_id'],
    $issuer,
    $scope,
    $state,
    $verifier,
    $isEhrLaunch ? $launch : null
));
exit;
