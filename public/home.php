<?php

/**
 * Patient home: where a patient lands after signing in (launch.php?mode=patient).
 *
 * Shows what the patient can do from this app and what this practice's server accepts: record
 * home vital signs, answer questionnaires, see what they sent and whether the care team has
 * reviewed it, and their recent vital signs, allergies and conditions. Works the same through
 * the confidential and the public patient client; what differs is whether the server lets the
 * client send (OpenEMR's Patient App FHIR Writes mode), and the page says which applies.
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
use OpenEMR\SmartQuestionnaire\Session;
use OpenEMR\SmartQuestionnaire\View;

Session::start();

$session = LaunchSession::resolve($_GET['lid'] ?? null);
if (is_string($session)) {
    View::error(401, 'Sign in again', str_replace('from the EHR', 'and sign in', $session));
}
if (!$session->isPatient()) {
    header('Location: index.php?' . http_build_query(['lid' => $session->launchId]));
    exit;
}

$fhir = $session->fhir;
$lid = $session->launchId;
$issuer = (string)$session->record['issuer'];
$notices = [];
$grantNotice = $session->patientGrantNotice();

$patientName = 'You';
$patientMeta = '';
try {
    $patient = $fhir->read('Patient', $session->patientId);
    foreach (is_array($patient['name'] ?? null) ? $patient['name'] : [] as $name) {
        $display = trim(implode(' ', is_array($name['given'] ?? null) ? $name['given'] : []) . ' ' . ($name['family'] ?? ''));
        if ($display !== '') {
            $patientName = $display;
            break;
        }
    }
    $patientMeta = is_string($patient['birthDate'] ?? null) ? 'Born ' . View::when($patient['birthDate']) : '';
} catch (FhirException $exception) {
    $notices[] = 'Your details could not be read: ' . $exception->getMessage();
}

$questionnaires = [];
try {
    $questionnaires = $fhir->search('Questionnaire', ['_count' => '100']);
} catch (FhirException $exception) {
    $notices[] = 'Questionnaires could not be listed: ' . $exception->getMessage();
}
$homeVitals = null;
$titles = [];
foreach ($questionnaires as $questionnaire) {
    if (is_string($questionnaire['id'] ?? null)) {
        $titles[$questionnaire['id']] = (string)($questionnaire['title'] ?? $questionnaire['name'] ?? $questionnaire['id']);
    }
    if (HomeVitals::identifies($questionnaire)) {
        $homeVitals = $questionnaire;
    }
}

$responses = [];
try {
    $responses = $session->allResponses();
} catch (FhirException $exception) {
    $notices[] = 'What you sent could not be listed: ' . $exception->getMessage();
}
// The newest response to each questionnaire, for its status in the list.
$latest = [];
foreach ($responses as $response) {
    $questionnaireId = Refs::idFrom($response['questionnaire'] ?? null, 'Questionnaire');
    if ($questionnaireId !== null && !isset($latest[$questionnaireId])) {
        $latest[$questionnaireId] = $response;
    }
}
$waiting = count(array_filter($responses, [LaunchSession::class, 'isHeld']));

$vitals = [];
if ($session->canRead('Observation')) {
    try {
        $vitals = PatientRecord::vitalSigns($session, 12);
    } catch (FhirException $exception) {
        $notices[] = 'Vital signs could not be read: ' . $exception->getMessage();
    }
}
$vitalsByDay = [];
foreach ($vitals as $row) {
    $vitalsByDay[View::when(substr($row['when'], 0, 10))][] = $row;
}
$lists = [];
foreach (['AllergyIntolerance' => 'Allergies', 'Condition' => 'Conditions'] as $type => $label) {
    if ($session->canRead($type)) {
        try {
            $lists[$label] = PatientRecord::names($session, $type);
        } catch (FhirException $exception) {
            $notices[] = $label . ' could not be read: ' . $exception->getMessage();
        }
    }
}

$canAnswer = $session->canCreate('QuestionnaireResponse');
$canSendObservations = $session->canCreate('Observation');
$canRecordVitals = $canSendObservations || ($homeVitals !== null && $canAnswer);
$canSend = $canAnswer || $canSendObservations;
$confidentialSignIn = $session->isPublicClient() && Config::clientFor($issuer) !== null
    ? 'launch.php?' . http_build_query(['iss' => $issuer, 'mode' => 'patient'])
    : null;
$notAccepting = $session->isPublicClient()
    ? 'This practice is not accepting information from public apps right now.'
    : 'This practice is not accepting information from apps right now.';

View::header('My health');
echo View::patientBar($session, $patientName, true, $patientMeta);
?>
<?php if ($grantNotice !== null) : ?>
    <div class="alert alert-info d-flex flex-wrap align-items-center justify-content-between" id="sq-grant-notice">
        <span class="mr-3"><?= h($grantNotice) ?></span>
        <?php if ($confidentialSignIn !== null && !$canSend) : ?>
            <a class="btn btn-sm btn-primary mt-2 mt-md-0" href="<?= h($confidentialSignIn) ?>">Confidential sign-in</a>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php foreach ($notices as $notice) : ?>
    <div class="alert alert-warning"><?= h($notice) ?></div>
<?php endforeach; ?>

<div class="row">
    <div class="col-lg-5 mb-3">
        <div class="card">
            <div class="card-header"><strong>Home vital signs</strong></div>
            <div class="card-body">
                <?php if ($canRecordVitals) : ?>
                    <p>Blood pressure, pulse, weight, temperature, oxygen level and breathing rate. Your care team
                        reviews them before they go in your record.</p>
                    <div>
                        <a class="btn btn-primary" href="index.php?<?= h(http_build_query(['lid' => $lid, 'vitals' => '1'])) ?>">Record readings</a>
                        <?php if ($vitals !== []) : ?>
                            <span class="small text-muted ml-2">Last in your record: <?= h(View::when($vitals[0]['when'])) ?></span>
                        <?php endif; ?>
                    </div>
                <?php elseif (!$canSend) : ?>
                    <p class="mb-0 text-muted"><?= h($notAccepting) ?></p>
                <?php else : ?>
                    <p class="mb-0 text-muted">Your practice has not set up home vital signs yet. Ask them to add the
                        "Home vital signs" questionnaire.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7 mb-3">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Questionnaires</strong>
                <?php if (!$canAnswer) : ?>
                    <span class="badge badge-light border">read only</span>
                <?php endif; ?>
            </div>
            <div class="sq-scroll"><div class="list-group list-group-flush">
                <?php $shown = 0; ?>
                <?php foreach ($questionnaires as $questionnaire) : ?>
                    <?php
                    $id = is_string($questionnaire['id'] ?? null) ? $questionnaire['id'] : '';
                    if ($id === '' || ($questionnaire['status'] ?? 'active') !== 'active' || HomeVitals::identifies($questionnaire)) {
                        continue;
                    }
                    $shown++;
                    $last = $latest[$id] ?? null;
                    ?>
                    <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-start"
                        href="index.php?<?= h(http_build_query(['lid' => $lid, 'q' => $id])) ?>">
                        <span class="mr-2">
                            <?= h($titles[$id] ?? $id) ?>
                            <?php if (is_string($questionnaire['description'] ?? null)) : ?>
                                <span class="d-block small text-muted"><?= h(View::excerpt($questionnaire['description'])) ?></span>
                            <?php endif; ?>
                        </span>
                        <?php if ($last !== null) : ?>
                            <span class="badge text-nowrap <?= LaunchSession::isHeld($last) ? 'badge-info' : 'badge-success' ?>"
                                title="<?= h(View::when((string)($last['authored'] ?? ''))) ?>">
                                <?= LaunchSession::isHeld($last) ? 'Waiting for review' : 'Answered' ?>
                            </span>
                        <?php elseif ($canAnswer) : ?>
                            <span class="badge badge-light border text-nowrap">Not answered</span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
                <?php if ($shown === 0) : ?>
                    <div class="list-group-item small text-muted">No questionnaires are available.</div>
                <?php endif; ?>
            </div></div>
            <?php if (!$canAnswer && $shown > 0) : ?>
                <div class="card-footer small text-muted">You can read these, but answers can't be sent from this app right now.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
        <strong>Sent to your care team</strong>
        <?php if ($responses !== []) : ?>
            <span class="small text-muted"><?= $waiting ?> waiting for review · <?= count($responses) - $waiting ?> in your record</span>
        <?php endif; ?>
    </div>
    <ul class="list-group list-group-flush">
        <?php if ($responses === []) : ?>
            <li class="list-group-item small text-muted">Nothing yet. What you send shows here, with whether your care team has reviewed it.</li>
        <?php endif; ?>
        <?php foreach (array_slice($responses, 0, 20) as $response) : ?>
            <?php
            $questionnaireId = Refs::idFrom($response['questionnaire'] ?? null, 'Questionnaire');
            $held = LaunchSession::isHeld($response);
            ?>
            <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center">
                <span>
                    <span class="badge mr-2 <?= $held ? 'badge-info' : 'badge-success' ?>"><?= $held ? 'Waiting for review' : 'In your record' ?></span>
                    <?= h($titles[$questionnaireId ?? ''] ?? 'Questionnaire') ?>
                    <span class="small text-muted ml-2"><?= h(View::when((string)($response['authored'] ?? ''))) ?></span>
                </span>
                <?php if ($questionnaireId !== null) : ?>
                    <a class="small" href="<?= h('index.php?' . http_build_query(['lid' => $lid, 'q' => $questionnaireId])) ?>">
                        <?= $held && $canAnswer ? 'View or change' : 'View' ?>
                    </a>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<div class="row">
    <div class="col-lg-6 mb-3">
        <div class="card">
            <div class="card-header"><strong>Recent vital signs</strong></div>
            <?php if (!$session->canRead('Observation')) : ?>
                <div class="card-body small text-muted">Not shared with this app.</div>
            <?php elseif ($vitals === []) : ?>
                <div class="card-body small text-muted">None recorded.</div>
            <?php else : ?>
                <table class="table table-sm mb-0">
                    <tbody>
                    <?php foreach ($vitalsByDay as $day => $rows) : ?>
                        <tr class="table-light"><th colspan="2" class="small"><?= h((string)$day) ?></th></tr>
                        <?php foreach ($rows as $row) : ?>
                            <tr><td><?= h($row['name']) ?></td><td class="text-right text-nowrap"><?= h($row['value']) ?></td></tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-6 mb-3">
        <?php foreach ($lists as $label => $names) : ?>
            <div class="card mb-3">
                <div class="card-header"><strong><?= h($label) ?></strong></div>
                <div class="card-body small">
                    <?php if ($names === []) : ?>
                        <span class="text-muted">None recorded.</span>
                    <?php else : ?>
                        <?php foreach ($names as $item) : ?>
                            <span class="badge badge-light border mr-1 mb-1 sq-chip"><?= h($item) ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if ($lists !== [] && !$session->canCreate('AllergyIntolerance') && !$session->canCreate('Condition')) : ?>
            <p class="small text-muted">To report a new allergy or condition, tell your care team; this practice's server does
                not take them from apps yet.</p>
        <?php endif; ?>
    </div>
</div>
<?php
View::footer();
