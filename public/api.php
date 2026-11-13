<?php

/**
 * JSON endpoint for the browser: api.php?lid=…&action=save|withdraw|vitals
 *
 * The browser never holds the access token. It posts the rendered response here and the
 * app writes it to the launching server over FHIR (POST to create, PUT to update).
 *
 *  - save      a QuestionnaireResponse. For a signed-in patient OpenEMR holds it for staff
 *              review and answers with a held-for-review tag; it can be changed until then.
 *  - withdraw  patient sign-in only: withdraw a held response (status entered-in-error). OpenEMR
 *              then answers 410 for it.
 *  - vitals    patient sign-in only, when the token grants patient/Observation create: the Home
 *              vital signs answers sent as vital-sign Observations instead of a response.
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

use OpenEMR\SmartQuestionnaire\FhirException;
use OpenEMR\SmartQuestionnaire\HomeVitals;
use OpenEMR\SmartQuestionnaire\LaunchSession;
use OpenEMR\SmartQuestionnaire\ResponseStamper;
use OpenEMR\SmartQuestionnaire\Session;
use OpenEMR\SmartQuestionnaire\View;

Session::start();

$action = $_GET['action'] ?? null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($action, ['save', 'withdraw', 'vitals'], true)) {
    View::json(405, ['success' => false, 'message' => 'Unsupported request.']);
}

$session = LaunchSession::resolve($_GET['lid'] ?? null);
if (is_string($session)) {
    View::json(401, ['success' => false, 'message' => $session]);
}

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!is_string($csrf) || $session->csrf() === '' || !hash_equals($session->csrf(), $csrf)) {
    View::json(403, ['success' => false, 'message' => 'Invalid or missing CSRF token.']);
}

try {
    $submitted = json_decode((string)file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    View::json(400, ['success' => false, 'message' => 'Request body is not valid JSON.']);
}
if (!is_array($submitted)) {
    View::json(400, ['success' => false, 'message' => 'Request body must be a JSON object.']);
}

/** Says what a refused write means for this kind of sign-in. */
$refused = static function (FhirException $exception) use ($session): never {
    $status = $exception->getHttpStatus();
    if ($status === 401 || $status === 403) {
        View::json($status, ['success' => false, 'message' => $exception->getMessage() . ($session->isPatient()
            ? ' — this practice is not accepting this from apps right now, or this sign-in was not given permission to send it.'
            : ' — the access token does not allow writing QuestionnaireResponse. Check that the launch granted '
                . 'QuestionnaireResponse write access (see the notice at the top of the page), then relaunch.')]);
    }
    if ($status === 410) {
        View::json(410, ['success' => false, 'message' => 'Your care team already decided on this, or it was withdrawn; it can no longer be changed.']);
    }
    View::json($status >= 400 && $status < 500 ? $status : 502, ['success' => false, 'message' => $exception->getMessage()]);
};

if ($action === 'vitals') {
    if (!$session->isPatient() || ($session->record['vitals_mode'] ?? false) !== true || !$session->canCreate('Observation')) {
        View::json(409, ['success' => false, 'message' => 'Readings can only be sent as vital signs when the server allows it.']);
    }
    // Readings a failed earlier attempt already saved (by linkId); a retry sends only the rest.
    $alreadySent = array_values(array_filter(is_array($submitted['alreadySent'] ?? null) ? $submitted['alreadySent'] : [], 'is_string'));
    $sent = [];
    $sentKeys = $alreadySent;
    try {
        $questionnaireId = $session->questionnaireId();
        $definition = $questionnaireId !== null ? $session->fhir->read('Questionnaire', $questionnaireId) : HomeVitals::bundled();
        $observations = HomeVitals::toObservations($definition, $submitted, $session->patientId);
    } catch (InvalidArgumentException $exception) {
        View::json(422, ['success' => false, 'message' => $exception->getMessage()]);
    } catch (FhirException $exception) {
        $refused($exception);
    }
    foreach ($observations as $key => $observation) {
        if (in_array($key, $alreadySent, true)) {
            continue;
        }
        try {
            $saved = $session->fhir->save($observation);
        } catch (FhirException $exception) {
            if ($sentKeys === []) {
                $refused($exception);
            }
            // Some readings are stored: say which, so the browser does not send them twice.
            $status = $exception->getHttpStatus();
            View::json($status >= 400 && $status < 500 ? $status : 502, [
                'success' => false,
                'message' => 'Some readings were sent and some were not: ' . $exception->getMessage()
                    . ' Send again to try only the readings that did not go.',
                'observations' => $sent,
                'sentKeys' => $sentKeys,
            ]);
        }
        $sent[] = ['id' => $saved['id'] ?? null, 'held' => LaunchSession::isHeld($saved)];
        $sentKeys[] = $key;
    }
    View::json(200, ['success' => true, 'observations' => $sent, 'sentKeys' => $sentKeys]);
}

if ($action === 'withdraw') {
    $id = is_string($submitted['id'] ?? null) ? $submitted['id'] : '';
    if (!$session->isPatient() || $id === '' || !in_array($id, $session->editableIds(), true)) {
        View::json(403, ['success' => false, 'message' => 'Only your own response that is still waiting for review can be withdrawn.']);
    }
    try {
        $stored = $session->fhir->read('QuestionnaireResponse', $id);
        if (!LaunchSession::isHeld($stored)) {
            View::json(409, ['success' => false, 'message' => 'Your care team has already reviewed this; ask them to correct it.']);
        }
        unset($stored['meta'], $stored['text']);
        $session->fhir->save(['status' => 'entered-in-error'] + $stored);
    } catch (FhirException $exception) {
        $refused($exception);
    }
    $session->remember(['editable_ids' => array_values(array_diff($session->editableIds(), [$id]))]);
    View::json(200, ['success' => true, 'withdrawn' => $id]);
}

$questionnaireId = $session->questionnaireId();
if ($questionnaireId === null) {
    View::json(409, ['success' => false, 'message' => 'Open a questionnaire before saving.']);
}

try {
    $id = is_string($submitted['id'] ?? null) && $submitted['id'] !== '' ? $submitted['id'] : null;
    // Never trust the browser's copy of server-owned fields: re-read the stored response.
    $stored = $id !== null && in_array($id, $session->editableIds(), true)
        ? $session->fhir->read('QuestionnaireResponse', $id)
        : null;
    $resource = ResponseStamper::stamp(
        $submitted,
        $session->patientId,
        $questionnaireId,
        $session->editableIds(),
        $stored
    );
    $saved = $session->fhir->save($resource);
} catch (InvalidArgumentException $exception) {
    View::json(422, ['success' => false, 'message' => $exception->getMessage()]);
} catch (FhirException $exception) {
    $refused($exception);
}

$savedId = is_string($saved['id'] ?? null) ? $saved['id'] : null;
if ($savedId === null) {
    View::json(502, ['success' => false, 'message' => 'The server accepted the response but did not return its id.']);
}

// Re-read so the browser continues from exactly what the server stored (for a patient, the held
// write with its held-for-review tag).
// When that read fails, what the server did with it is unconfirmed: the copy we sent carries no
// held-for-review tag either way, so it is not evidence the response went into the record.
$confirmed = true;
try {
    $current = $session->fhir->read('QuestionnaireResponse', $savedId);
} catch (FhirException) {
    $current = $saved;
    $confirmed = false;
}
$held = $confirmed ? LaunchSession::isHeld($current) : null;
// A patient can keep changing only a write still held for review. Unconfirmed, it stays
// changeable here; the server refuses the change if staff have already decided.
if (!$session->isPatient() || $held !== false) {
    $session->remember([
        'editable_ids' => array_values(array_unique([...$session->editableIds(), $savedId])),
    ]);
}

View::json(200, ['success' => true, 'resource' => $current, 'held' => $held, 'confirmed' => $confirmed]);
