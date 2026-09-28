<?php

/**
 * JSON endpoint for the browser: api.php?lid=…&action=save
 *
 * The browser never holds the access token. It posts the rendered response here and the
 * app writes it to the launching server over FHIR (POST to create, PUT to update).
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
use OpenEMR\SmartQuestionnaire\LaunchSession;
use OpenEMR\SmartQuestionnaire\ResponseStamper;
use OpenEMR\SmartQuestionnaire\Session;
use OpenEMR\SmartQuestionnaire\View;

Session::start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_GET['action'] ?? null) !== 'save') {
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

$questionnaireId = $session->questionnaireId();
if ($questionnaireId === null) {
    View::json(409, ['success' => false, 'message' => 'Open a questionnaire before saving.']);
}

try {
    $submitted = json_decode((string)file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    View::json(400, ['success' => false, 'message' => 'Request body is not valid JSON.']);
}
if (!is_array($submitted)) {
    View::json(400, ['success' => false, 'message' => 'Request body must be a JSON object.']);
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
    $status = $exception->getHttpStatus();
    if ($status === 401 || $status === 403) {
        View::json($status, ['success' => false, 'message' => $exception->getMessage()
            . ' — the access token does not allow writing QuestionnaireResponse. Check that the launch granted '
            . 'QuestionnaireResponse write access (see the notice at the top of the page), then relaunch.']);
    }
    View::json($status >= 400 && $status < 500 ? $status : 502, ['success' => false, 'message' => $exception->getMessage()]);
}

$savedId = is_string($saved['id'] ?? null) ? $saved['id'] : null;
if ($savedId === null) {
    View::json(502, ['success' => false, 'message' => 'The server accepted the response but did not return its id.']);
}

// Re-read so the browser continues from exactly what the server stored.
try {
    $current = $session->fhir->read('QuestionnaireResponse', $savedId);
} catch (FhirException) {
    $current = $saved;
}
$session->remember([
    'editable_ids' => array_values(array_unique([...$session->editableIds(), $savedId])),
]);

View::json(200, ['success' => true, 'resource' => $current]);
