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
use OpenEMR\SmartQuestionnaire\HomeVitals;
use OpenEMR\SmartQuestionnaire\LaunchSession;
use OpenEMR\SmartQuestionnaire\PatientRecord;
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

// A signed-in patient starts from their home page, which lists the questionnaires. ?vitals=1 is
// their Home vital signs form.
$isPatient = $session->isPatient();
$vitalsMode = $isPatient && isset($_GET['vitals']);
if ($isPatient && !$vitalsMode && (!isset($_GET['q']) || isset($_GET['pick']))) {
    header('Location: home.php?' . http_build_query(['lid' => $session->launchId]));
    exit;
}

// The server can grant less than was asked for (consent unchecked, or scopes it drops). Say so
// up front rather than letting the first save fail with a bare 401.
$requestedScope = (string)($session->record['requested_scope'] ?? '');
if ($isPatient) {
    $grantNotice = $session->patientGrantNotice();
    if ($grantNotice !== null) {
        $notices[] = $grantNotice;
    }
} elseif ($requestedScope !== '' && is_string($token['scope'] ?? null)) {
    $missing = ScopeCheck::notGranted($requestedScope, $token['scope']);
    if ($missing !== []) {
        $notices[] = 'The server did not grant: ' . implode(', ', $missing) . '. '
            . (preg_grep('~^(user|patient)/QuestionnaireResponse\.~', $missing) !== []
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

$renderBanner = static function () use ($showBanner, $patientName, $patientMeta, $isPatient, $session): void {
    if ($isPatient) {
        echo View::patientBar($session, $patientName, false);
    } elseif ($showBanner) {
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
if ($questionnaireId === null && !$vitalsMode) {
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
                    ? '<div class="small text-muted">' . h(View::excerpt($questionnaire['description'])) . '</div>'
                    : '')
                . '</a>';
        }
        echo '</div>';
    }
    View::footer();
    exit;
}

// --- Load the questionnaire and this patient's responses to it ------------------------------
$questionnaire = null;
if (!$vitalsMode) {
    try {
        $questionnaire = $fhir->read('Questionnaire', (string)$questionnaireId);
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
    $vitalsMode = $isPatient && HomeVitals::identifies($questionnaire);
}

// How a patient's readings travel: as vital-sign Observations when the server grants that, else
// as answers to the practice's Home vital signs Questionnaire, held for staff review.
$transport = 'questionnaire';
if ($vitalsMode) {
    $homeVitals = null;
    try {
        $homeVitals = $questionnaire ?? HomeVitals::find($fhir);
    } catch (FhirException $exception) {
        $notices[] = 'The Home vital signs questionnaire could not be looked up: ' . $exception->getMessage();
    }
    if ($session->canCreate('Observation')) {
        $transport = 'observation';
    } elseif ($homeVitals === null || !$session->canCreate('QuestionnaireResponse')) {
        View::error(409, 'Home vital signs unavailable', $homeVitals === null
            ? 'Your practice has not set up home vital signs yet. Ask them to add the "Home vital signs" questionnaire.'
            : ($session->isPublicClient()
                ? 'This practice is not accepting information from public apps right now. Sign out and use the confidential patient sign-in.'
                : 'This practice is not accepting information from apps right now.'));
    }
    $questionnaire = $homeVitals ?? HomeVitals::bundled();
    $questionnaireId = is_string($homeVitals['id'] ?? null) ? $homeVitals['id'] : null;
}

try {
    $responses = $questionnaireId !== null ? $session->responsesFor($questionnaireId) : [];
} catch (FhirException $exception) {
    $responses = [];
    $notices[] = 'Previous responses could not be listed: ' . $exception->getMessage();
}
$readings = [];
if ($vitalsMode && $session->canRead('Observation')) {
    try {
        $readings = PatientRecord::vitalSigns($session, 10);
    } catch (FhirException $exception) {
        $notices[] = 'Recent vital signs could not be read: ' . $exception->getMessage();
    }
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

// A patient app may change only its own writes still held for review; the server refuses the rest.
$editableResponses = $isPatient ? array_filter($responses, [LaunchSession::class, 'isHeld']) : $responses;
$session->remember([
    'questionnaire_id' => $questionnaireId,
    'vitals_mode' => $vitalsMode,
    'editable_ids' => array_values(array_filter(array_column($editableResponses, 'id'), 'is_string')),
]);

$title = (string)($questionnaire['title'] ?? $questionnaire['name'] ?? 'Questionnaire');
$homeUrl = 'home.php?' . http_build_query(['lid' => $session->launchId]);
$payload = [
    'patientMode' => $isPatient,
    // Whether this token may send what the form saves; without it the form opens read-only.
    'canSend' => $transport === 'observation' || $session->canCreate('QuestionnaireResponse'),
    // Change and Withdraw on an earlier response update a QuestionnaireResponse, whatever new readings travel as.
    'canChangeResponses' => $session->canUpdate('QuestionnaireResponse'),
    'transport' => $transport,
    'homeUrl' => $isPatient ? $homeUrl : null,
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
<?php if ($isPatient) : ?>
    <?php if ($vitalsMode && $transport === 'observation') : ?>
        <div class="alert alert-info small">Your readings are sent as vital signs. Your care team reviews them before they go in your record.</div>
    <?php elseif ($vitalsMode) : ?>
        <div class="alert alert-info small">Your readings are sent to your care team, who review them before they go in your record.</div>
    <?php endif; ?>
<?php endif; ?>
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
            <button type="button" class="btn btn-outline-danger" id="sq-withdraw" hidden>Withdraw</button>
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

<?php if ($vitalsMode) : ?>
    <div class="card mb-3">
        <div class="card-header"><strong>Your recent vital signs</strong></div>
        <?php if ($readings === []) : ?>
            <div class="card-body small text-muted"><?= $session->canRead('Observation') ? 'None in your record yet.' : 'Not shared with this app.' ?></div>
        <?php else : ?>
            <table class="table table-sm mb-0">
                <tbody>
                <?php foreach ($readings as $row) : ?>
                    <tr>
                        <td class="small text-muted"><?= h(View::when($row['when'])) ?></td>
                        <td><?= h($row['name']) ?></td><td class="text-right"><?= h($row['value']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php // Earlier held responses stay reachable (to change or withdraw) even when new readings go as Observations. ?>
<?php if (!($vitalsMode && $transport === 'observation' && $responses === [])) : ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong><?= $isPatient ? 'What you sent' : 'Responses for this patient' ?></strong>
        <?php if ($payload['canPick'] && !$isPatient) : ?>
            <a class="small" href="index.php?<?= h(http_build_query(['lid' => $session->launchId, 'pick' => '1'])) ?>">Choose another questionnaire</a>
        <?php endif; ?>
    </div>
    <ul class="list-group list-group-flush" id="sq-history"></ul>
</div>
<?php endif; ?>

<?php $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT; ?>
<script type="application/json" id="sq-data"><?= json_encode($payload, $jsonFlags) ?></script>
<script src="assets/runtime/openemr_questionnaire.js" data-openemr-version="<?= h($session->openemrVersion()) ?>"></script>
<script src="assets/app.js"></script>
<?php
View::footer();
