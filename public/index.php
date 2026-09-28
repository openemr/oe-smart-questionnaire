<?php

/**
 * The questionnaire workspace for an authorized launch (index.php?lid=…).
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
use OpenEMR\SmartQuestionnaire\FhirException;
use OpenEMR\SmartQuestionnaire\LaunchSession;
use OpenEMR\SmartQuestionnaire\Refs;
use OpenEMR\SmartQuestionnaire\ScopeCheck;
use OpenEMR\SmartQuestionnaire\Session;
use OpenEMR\SmartQuestionnaire\View;

Session::start();

if (!isset($_GET['lid'])) {
    header('Location: launch.php');
    exit;
}

$session = LaunchSession::resolve($_GET['lid']);
if (is_string($session)) {
    View::error(401, 'Launch required', $session);
}

$token = $session->token();
$launchContext = Refs::launchContext($token);
$appContext = Refs::appContext($token);
$fhir = $session->fhir;
$notices = [];

// The server can grant less than was asked for (consent unchecked, or scopes it drops). Say so
// up front rather than letting the first save fail with a bare 401.
$requestedScope = (string)($session->record['requested_scope'] ?? '');
if ($requestedScope !== '' && is_string($token['scope'] ?? null)) {
    $missing = ScopeCheck::notGranted($requestedScope, $token['scope']);
    if ($missing !== []) {
        $notices[] = 'The server did not grant: ' . implode(', ', $missing) . '. '
            . (preg_grep('~^user/QuestionnaireResponse\.~', $missing)
                ? 'Saving responses will fail until it does. Relaunch and leave those permissions checked. '
                    . (str_contains($requestedScope, 'QuestionnaireResponse.cu')
                        ? 'This client uses the v2 scope profile, which needs the OpenEMR consent-screen fix; on an OpenEMR '
                            . 'without it, re-register the site with the v1 profile.'
                        : 'If they are missing from the consent screen, re-register the app.')
                : 'Some features may not work.');
    }
}

try {
    $patient = $fhir->read('Patient', $session->patientId);
} catch (FhirException $exception) {
    $patient = null;
    $notices[] = 'Patient details could not be read: ' . $exception->getMessage();
}

// The launch context is authoritative. Only a launch without a questionnaire may pick one.
$questionnaireId = $launchContext['questionnaire'];
if ($questionnaireId === null && !isset($_GET['pick'])) {
    $picked = $_GET['q'] ?? null;
    $questionnaireId = is_string($picked) && preg_match('~^[A-Za-z0-9\-.]{1,64}$~', $picked) === 1
        ? $picked
        : $session->questionnaireId();
}

$patientName = 'Patient';
$patientMeta = [];
if (is_array($patient)) {
    foreach (is_array($patient['name'] ?? null) ? $patient['name'] : [] as $name) {
        $display = trim(implode(' ', is_array($name['given'] ?? null) ? $name['given'] : []) . ' ' . ($name['family'] ?? ''));
        if ($display !== '') {
            $patientName = $display;
            break;
        }
    }
    foreach (['birthDate' => 'DOB', 'gender' => 'Sex'] as $field => $label) {
        if (is_string($patient[$field] ?? null)) {
            $patientMeta[] = $label . ': ' . $patient[$field];
        }
    }
}
$showBanner = ($token['need_patient_banner'] ?? true) !== false;

$renderBanner = static function () use ($showBanner, $patientName, $patientMeta): void {
    if ($showBanner) {
        echo '<div class="sq-banner border rounded px-3 py-2 mb-3 d-flex flex-wrap align-items-center">'
            . '<strong class="mr-3">' . h($patientName) . '</strong>'
            . '<span class="small text-muted">' . h(implode(' · ', $patientMeta)) . '</span></div>';
    }
};
$renderNotices = static function (array $notices): void {
    foreach ($notices as $notice) {
        echo '<div class="alert alert-warning">' . h($notice) . '</div>';
    }
};

// --- No questionnaire in context: let the user pick one -------------------------------------
if ($questionnaireId === null) {
    try {
        $questionnaires = $fhir->search('Questionnaire', ['_count' => '100']);
    } catch (FhirException $exception) {
        View::error(502, 'Questionnaires unavailable', $exception->getMessage());
    }
    View::header('Choose a questionnaire');
    $renderBanner();
    $renderNotices($notices);
    echo '<h1 class="h5 mb-3">Choose a questionnaire</h1>';
    if ($questionnaires === []) {
        echo '<div class="alert alert-info">No questionnaires are available to this app.</div>';
    } else {
        echo '<div class="list-group">';
        foreach ($questionnaires as $questionnaire) {
            $id = is_string($questionnaire['id'] ?? null) ? $questionnaire['id'] : '';
            if ($id === '' || ($questionnaire['status'] ?? 'active') === 'retired') {
                continue;
            }
            $title = $questionnaire['title'] ?? $questionnaire['name'] ?? $id;
            echo '<a class="list-group-item list-group-item-action" href="index.php?'
                . h(http_build_query(['lid' => $session->launchId, 'q' => $id])) . '">' . h((string)$title)
                . (is_string($questionnaire['description'] ?? null)
                    ? '<div class="small text-muted">' . h($questionnaire['description']) . '</div>'
                    : '')
                . '</a>';
        }
        echo '</div>';
    }
    View::footer();
    exit;
}

// --- Load the questionnaire and this patient's responses to it ------------------------------
try {
    $questionnaire = $fhir->read('Questionnaire', $questionnaireId);
} catch (FhirException $exception) {
    View::error(
        $exception->getHttpStatus() === 404 ? 404 : 502,
        'Questionnaire unavailable',
        "Questionnaire/{$questionnaireId} could not be read: " . $exception->getMessage()
    );
}
if (($questionnaire['resourceType'] ?? null) !== 'Questionnaire') {
    View::error(502, 'Questionnaire unavailable', 'The server did not return a Questionnaire.');
}

try {
    $responses = $session->responsesFor($questionnaireId);
} catch (FhirException $exception) {
    $responses = [];
    $notices[] = 'Previous responses could not be listed: ' . $exception->getMessage();
}

$contextResponseId = $launchContext['response'];
if ($contextResponseId !== null && !in_array($contextResponseId, array_column($responses, 'id'), true)) {
    try {
        $contextResponse = $fhir->read('QuestionnaireResponse', $contextResponseId);
        if (
            Refs::idFrom($contextResponse['subject']['reference'] ?? null, 'Patient') === $session->patientId
            && Refs::idFrom($contextResponse['questionnaire'] ?? null, 'Questionnaire') === $questionnaireId
        ) {
            array_unshift($responses, $contextResponse);
        } else {
            $notices[] = 'The response in the launch context does not match this patient and questionnaire.';
            $contextResponseId = null;
        }
    } catch (FhirException $exception) {
        $notices[] = "QuestionnaireResponse/{$contextResponseId} could not be read: " . $exception->getMessage();
        $contextResponseId = null;
    }
}

// Opening choice: the launch's response, else continue the newest draft when asked to,
// else a fresh response.
$initialResponseId = null;
if ($contextResponseId !== null) {
    $initialResponseId = $contextResponseId;
} elseif (($appContext['action'] ?? null) === 'continue') {
    foreach ($responses as $response) {
        if (($response['status'] ?? null) === 'in-progress') {
            $initialResponseId = $response['id'];
            break;
        }
    }
}

$session->remember([
    'questionnaire_id' => $questionnaireId,
    'editable_ids' => array_values(array_filter(array_column($responses, 'id'), 'is_string')),
]);

$title = (string)($questionnaire['title'] ?? $questionnaire['name'] ?? 'Questionnaire');
$payload = [
    'launchId' => $session->launchId,
    'csrf' => $session->csrf(),
    'questionnaire' => $questionnaire,
    'responses' => $responses,
    'initialResponseId' => $initialResponseId,
    // OpenEMR sends action "review" for a finished response: open it read-only.
    'reviewOnly' => ($appContext['action'] ?? null) === 'review' && $initialResponseId !== null,
    'canPick' => $launchContext['questionnaire'] === null,
    'intent' => is_string($token['intent'] ?? null) ? $token['intent'] : null,
    'ehrOrigin' => (static function (string $issuer): ?string {
        $parts = parse_url($issuer);
        return is_array($parts) && isset($parts['scheme'], $parts['host'])
            ? $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
            : null;
    })((string)$session->record['issuer']),
];

View::header($title);
$renderBanner();
$renderNotices($notices);
?>
<div class="card mb-3">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
        <div class="mr-2">
            <h1 class="h5 d-inline mb-0"><?= h($title) ?></h1>
            <span class="badge badge-secondary ml-2" id="sq-status" hidden></span>
            <span class="badge badge-warning ml-1" id="sq-dirty" hidden>Unsaved changes</span>
        </div>
        <div class="btn-group btn-group-sm mt-2 mt-md-0" role="group" aria-label="Assessment actions">
            <button type="button" class="btn btn-outline-primary" id="sq-new">Start new</button>
            <button type="button" class="btn btn-outline-secondary" id="sq-amend" hidden>Amend</button>
            <button type="button" class="btn btn-secondary" id="sq-draft" disabled>Save draft</button>
            <button type="button" class="btn btn-primary" id="sq-complete" disabled>Complete</button>
        </div>
    </div>
    <div class="card-body">
        <div id="sq-alert" class="d-none" role="alert"></div>
        <p class="small text-muted mb-2" id="sq-working"></p>
        <fieldset id="sq-fieldset" class="border-0 p-0 m-0">
            <div id="sq-form"></div>
        </fieldset>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Responses for this patient</strong>
        <?php if ($payload['canPick']) : ?>
            <a class="small" href="index.php?<?= h(http_build_query(['lid' => $session->launchId, 'pick' => '1'])) ?>">Choose another questionnaire</a>
        <?php endif; ?>
    </div>
    <ul class="list-group list-group-flush" id="sq-history"></ul>
</div>

<?php $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT; ?>
<script type="application/json" id="sq-data"><?= json_encode($payload, $jsonFlags) ?></script>
<script src="assets/runtime/openemr_questionnaire.js"></script>
<script src="assets/app.js"></script>
<?php
View::footer();
