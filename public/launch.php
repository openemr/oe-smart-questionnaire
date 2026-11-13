<?php

/**
 * SMART launch endpoint (register this as the client's launch URI).
 *
 *  - EHR launch:        launch.php?iss=<fhir base>&launch=<opaque>
 *  - Standalone launch: launch.php?iss=<fhir base>   (staff sign in; the server shows its patient picker)
 *  - Patient sign-in:   launch.php?iss=<fhir base>&mode=patient   (the patient signs in with
 *                       OpenEMR's "Patient Login" and works on their own record)
 *  - Portal launch:     launch.php?mode=portal&iss=<fhir base>&launch=<opaque>   (OpenEMR's
 *                       patient portal opens the patient portal client; the patient signs in
 *                       and works on their own record, as with patient sign-in)
 *  - No parameters:     lists the registered servers for a standalone launch or patient sign-in.
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

// The sign-in returns to app_url's callback.php, so it has to start on app_url's host too: the
// state is kept in that host's session cookie. Move a launch opened under another name there
// first, with its query (iss, launch, mode) intact.
$requestHost = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? null;
if (!Config::isAppHost(is_string($requestHost) ? $requestHost : null)) {
    $query = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ' . Config::launchUri() . (is_string($query) && $query !== '' ? '?' . $query : ''), true, 302);
    exit;
}

Session::start();

$issuer = filter_input(INPUT_GET, 'iss', FILTER_UNSAFE_RAW);
$launch = filter_input(INPUT_GET, 'launch', FILTER_UNSAFE_RAW);

if (!is_string($issuer) || $issuer === '') {
    View::header('Sign in');
    echo '<div class="sq-hero mb-4"><h1 class="h4 mb-1">' . h(Config::string('app_name')) . '</h1>'
        . '<p class="text-muted mb-0">Patients answer questionnaires and send home vital signs to their care team. '
        . 'Staff pick a patient and fill in assessments. Inside the EHR, launch the app from the patient\'s chart instead.</p></div>';
    $clients = Config::clients();
    if ($clients === []) {
        echo '<div class="alert alert-warning">No FHIR servers are registered yet. Register one on the '
            . '<a href="admin.php">administration page</a> or with <code>php bin/register.php</code>.</div>';
    } else {
        echo '<div class="row">';
        foreach ($clients as $registeredIssuer => $registeredClient) {
            $registeredIssuer = (string)$registeredIssuer;
            $hasPatient = ScopeProfiles::forClient($registeredClient, null)['patient'] !== '';
            $hasPublic = Config::publicClientFor($registeredIssuer) !== null;
            $url = static fn(array $query): string => 'launch.php?' . http_build_query(['iss' => $registeredIssuer] + $query);
            $site = is_string($registeredClient['site'] ?? null) ? $registeredClient['site'] : '';
            echo '<div class="col-lg-6 mb-3"><div class="card h-100 sq-server">'
                . '<div class="card-header d-flex justify-content-between align-items-center">'
                . '<strong>' . h((string)(parse_url($registeredIssuer, PHP_URL_HOST) ?: $registeredIssuer)) . '</strong>'
                . ($site !== '' ? '<span class="badge badge-light border">' . h($site) . '</span>' : '') . '</div>'
                . '<div class="card-body">'
                . '<h2 class="h6 mb-1">Patients</h2><p class="small text-muted mb-2">Sign in with your patient portal account.</p>'
                . '<div class="mb-3">'
                . ($hasPatient
                    ? '<a class="btn btn-primary btn-sm mr-2 mb-1" href="' . h($url(['mode' => 'patient'])) . '" '
                        . 'title="This app signs in with its client secret">Patient sign-in <span class="sq-kind">confidential</span></a>'
                    : '<span class="small text-muted mr-2" title="Register this server again on the administration page">'
                        . 'Confidential patient sign-in not registered.</span>')
                . ($hasPublic
                    ? '<a class="btn btn-outline-primary btn-sm mb-1" href="' . h($url(['mode' => 'patient', 'client' => 'public'])) . '" '
                        . 'title="Signs in the way a phone or browser app does: no secret, PKCE only">Patient sign-in <span class="sq-kind">public</span></a>'
                    : '<span class="small text-muted" title="Optional: Add public client on the administration page">No public patient client.</span>')
                . '</div>'
                . '<h2 class="h6 mb-1">Staff</h2><p class="small text-muted mb-2">Sign in to the EHR, then choose a patient.</p>'
                . '<a class="btn btn-outline-secondary btn-sm" href="' . h($url([])) . '">Staff sign-in</a>'
                . '</div><div class="card-footer small text-muted text-break">' . h($registeredIssuer) . '</div></div></div>';
        }
        echo '</div>';
        echo '<details class="small text-muted mb-3"><summary>Confidential or public patient sign-in?</summary>'
            . '<p class="mt-2 mb-1"><b>Confidential</b>: this app\'s server proves who it is with a client secret, like a '
            . 'practice-hosted web app. <b>Public</b>: no secret, PKCE only, the way a phone or browser app signs in.</p>'
            . '<p class="mb-0">Both can read your record. OpenEMR\'s <i>Patient App FHIR Writes</i> setting decides which can '
            . 'send information: "Confidential apps" or "Confidential and public apps". Everything sent waits for staff review.</p>'
            . '</details>';
    }
    echo '<p class="small"><a href="admin.php">Client registration (administrators)</a></p>';
    View::footer();
    exit;
}

$issuer = Config::normalizeIssuer($issuer);
if (filter_var($issuer, FILTER_VALIDATE_URL) === false || preg_match('~^https?://~i', $issuer) !== 1) {
    View::error(400, 'Launch failed', 'The iss parameter is not a valid FHIR base URL.');
}
$client = Config::clientFor($issuer);
if ($client === null) {
    // Refusing unknown issuers keeps the app from sending codes or tokens to a server an
    // attacker names in the launch URL.
    View::error(400, 'Launch failed', "This app is not registered with {$issuer}.");
}

$hasLaunch = is_string($launch) && $launch !== '';
// A portal launch comes from OpenEMR's patient portal, to the portal client's launch URI.
$isPortal = ($_GET['mode'] ?? null) === 'portal';
if ($isPortal) {
    $portalClient = Config::portalClientFor($issuer);
    if (!$hasLaunch) {
        View::error(400, 'Open this from your patient portal', 'This address is for the Health Apps list in the patient portal. '
            . 'Open the app from there, or use Patient sign-in on ' . Config::launchUri() . '.');
    }
    if ($portalClient === null) {
        View::error(400, 'Portal launch unavailable', "No patient portal client is registered with {$issuer}. "
            . 'Register one on the administration page (Add portal client).');
    }
}
$isEhrLaunch = $hasLaunch && !$isPortal;
$isPatientSignIn = !$hasLaunch && ($_GET['mode'] ?? null) === 'patient';
// Patient sign-in can go through the public patient client (no secret, PKCE only) instead.
$isPublic = $isPatientSignIn && ($_GET['client'] ?? null) === 'public';
if ($isPublic) {
    $publicClient = Config::publicClientFor($issuer);
    if ($publicClient === null) {
        View::error(400, 'Public sign-in unavailable', "No public patient client is registered with {$issuer}. "
            . 'Register one on the administration page (Add public client), or use the confidential patient sign-in.');
    }
}
try {
    $configuration = (new SmartClient(new HttpClient(Config::verifyTls())))->discover($issuer);
} catch (Throwable $exception) {
    View::error(502, 'Launch failed', 'SMART discovery failed: ' . $exception->getMessage());
}

$state = SmartClient::base64Url(random_bytes(32));
$verifier = SmartClient::newVerifier();
$launchId = LaunchStore::create([
    'issuer' => $issuer,
    // A portal launch works like patient sign-in once the patient has signed in.
    'mode' => $isEhrLaunch ? 'ehr' : ($isPatientSignIn || $isPortal ? 'patient' : 'standalone'),
    'client_type' => $isPortal ? 'portal' : ($isPublic ? 'public' : 'confidential'),
    'smart_configuration' => $configuration,
    'verifier' => $verifier,
]);
LaunchStore::bindState($state, $launchId);

// Request exactly what this client was registered with (see ScopeProfiles).
$siteName = Sites::nameForIssuer($issuer);
$clientScopes = ScopeProfiles::forClient($client, $siteName !== null ? Sites::get($siteName) : null);
if ($isPatientSignIn && !$isPublic && $clientScopes['patient'] === '') {
    View::error(400, 'Patient sign-in unavailable', "This app was registered with {$issuer} before patient sign-in existed, "
        . 'or the server offered no patient scopes. Register it again on the administration page and enable the new client.');
}
$scope = $isEhrLaunch ? $clientScopes['ehr'] : ($isPatientSignIn ? $clientScopes['patient'] : $clientScopes['standalone']);
$clientId = (string)$client['client_id'];
if ($isPublic) {
    $scope = (string)($publicClient['patient_scopes'] ?? '');
    $clientId = (string)$publicClient['client_id'];
}
if ($isPortal) {
    $scope = (string)($portalClient['patient_scopes'] ?? '');
    $clientId = (string)$portalClient['client_id'];
}
LaunchStore::update($launchId, ['requested_scope' => $scope]);
header('Location: ' . SmartClient::authorizeUrl(
    $configuration,
    $clientId,
    $issuer,
    $scope,
    $state,
    $verifier,
    $hasLaunch ? $launch : null
));
exit;
