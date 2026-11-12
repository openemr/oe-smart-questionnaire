<?php

/**
 * OAuth redirect URI: validates state, exchanges the code (PKCE), stores the token per launch.
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
use OpenEMR\SmartQuestionnaire\Session;
use OpenEMR\SmartQuestionnaire\SmartClient;
use OpenEMR\SmartQuestionnaire\View;

Session::start();

$launchId = LaunchStore::consumeState($_GET['state'] ?? null);
$launch = $launchId !== null ? LaunchStore::get($launchId) : null;
if ($launchId === null || $launch === null) {
    View::error(400, 'Authorization failed', 'Unknown or already-used authorization state. Launch the app again from the EHR, '
        . 'or start over at ' . Config::launchUri() . ' (a sign-in must start and end on the same address).');
}

if (isset($_GET['error'])) {
    $description = is_string($_GET['error_description'] ?? null) ? $_GET['error_description'] : (string)$_GET['error'];
    if (is_string($_GET['hint'] ?? null) && $_GET['hint'] !== '') {
        $description .= ' — ' . $_GET['hint'];
    }
    View::error(400, 'Authorization was not granted', $description);
}

$code = $_GET['code'] ?? null;
if (!is_string($code) || $code === '') {
    View::error(400, 'Authorization failed', 'The authorization server did not return a code.');
}

$isPublic = ($launch['client_type'] ?? null) === 'public';
$client = $isPublic ? Config::publicClientFor((string)$launch['issuer']) : Config::clientFor((string)$launch['issuer']);
if ($client === null) {
    View::error(400, 'Authorization failed', $isPublic
        ? 'The public patient client is no longer registered with this server.'
        : 'This app is no longer registered with the launching server.');
}

try {
    $token = (new SmartClient(new HttpClient(Config::verifyTls())))->exchangeCode(
        (array)$launch['smart_configuration'],
        $client,
        $code,
        (string)$launch['verifier']
    );
} catch (Throwable $exception) {
    $message = $exception->getMessage();
    if ($isPublic && (stripos($message, 'client authentication failed') !== false || stripos($message, 'invalid_client') !== false)) {
        $message .= ' — the server did not accept the public patient client ' . $client['client_id']
            . '. Check that it is enabled in OpenEMR (Admin > System > API Clients), or register it again.';
    } elseif (stripos($message, 'client authentication failed') !== false || stripos($message, 'invalid_client') !== false) {
        $message .= ' — the server rejected this app\'s client ID or secret for ' . $launch['issuer']
            . '. On the administration page, run Check for this site; it reports a secret mismatch and Repair secret fixes it.'
            . ' If Check says the server no longer knows the client, register again and enable the new client.';
    }
    View::error(502, 'Authorization failed', $message);
}

$expiresIn = is_numeric($token['expires_in'] ?? null) ? (int)$token['expires_in'] : 3600;
LaunchStore::update($launchId, [
    'token' => $token,
    'expires_at' => time() + $expiresIn - 30,
    'verifier' => null,
]);
session_regenerate_id(true);

header('Location: index.php?' . http_build_query(['lid' => $launchId]));
exit;
