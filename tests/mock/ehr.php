<?php

/**
 * Tiny mock SMART/FHIR server for the end-to-end test (php -S router script).
 * State lives in $MOCK_STATE_DIR so the test can inspect and steer it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @link      https://github.com/openemr/oe-smart-questionnaire
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

$dir = getenv('MOCK_STATE_DIR') ?: sys_get_temp_dir() . '/sq-mock';
@mkdir($dir, 0700, true);
$stateFile = $dir . '/state.json';
$state = is_file($stateFile) ? json_decode((string)file_get_contents($stateFile), true) : [];
$state += ['codes' => [], 'tokens' => [], 'responses' => [], 'clients' => [], 'seq' => 0, 'log' => []];
$context = is_file($dir . '/context.json') ? json_decode((string)file_get_contents($dir . '/context.json'), true) : [];

$base = 'http://' . $_SERVER['HTTP_HOST'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$state['log'][] = $method . ' ' . $_SERVER['REQUEST_URI'];

$save = static function () use (&$state, $stateFile): void {
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
};
$json = static function (int $status, array $body, array $headers = []) use ($save): never {
    $save();
    http_response_code($status);
    header('Content-Type: application/fhir+json');
    foreach ($headers as $name => $value) {
        header("{$name}: {$value}");
    }
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
};
$outcome = static fn(string $text) => ['resourceType' => 'OperationOutcome', 'issue' => [['severity' => 'error', 'code' => 'invalid', 'diagnostics' => $text]]];
$b64u = static fn(string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

$advertised = ['openid', 'fhirUser', 'launch', 'launch/patient', 'offline_access',
    'user/Patient.rs', 'user/Patient.cud', 'user/Questionnaire.rs', 'user/Questionnaire.cud',
    'user/QuestionnaireResponse.rs', 'user/QuestionnaireResponse.cud',
    'user/QuestionnaireResponse.read', 'user/QuestionnaireResponse.write'];
$perms = static fn(string $scope): string => ['read' => 'rs', 'write' => 'cud'][preg_replace('~^.*\.~', '', $scope)] ?? preg_replace('~^.*\.~', '', $scope);
/**
 * OpenEMR consent emulation: one card per context/resource; v1 cards come back as separate
 * .read/.write, v2 cards as ONE merged scope; the server then keeps an approved scope only if a
 * single requested scope contains it.
 */
$consent = static function (array $requested, array $deny, bool $consentFixed = false) use ($perms): array {
    $cards = [];
    $plain = [];
    foreach ($requested as $scope) {
        if (preg_match('~^(\w+/\w+)\.(\w+)$~', $scope, $m) !== 1) {
            $plain[] = $scope;
            continue;
        }
        $cards[$m[1]]['v1'] ??= in_array($m[2], ['read', 'write'], true);
        $cards[$m[1]]['actions'] = array_merge($cards[$m[1]]['actions'] ?? [], str_split($perms($scope)));
    }
    $submitted = $plain;
    foreach ($cards as $base => $card) {
        $actions = array_unique($card['actions']);
        if ($card['v1']) {
            if (array_intersect($actions, ['r', 's'])) {
                $submitted[] = "{$base}.read";
            }
            if (array_intersect($actions, ['c', 'u', 'd'])) {
                $submitted[] = "{$base}.write";
            }
        } elseif ($consentFixed) {
            // Fixed consent screen: v2 reads and writes come back as separate scopes.
            foreach ([['r', 's'], ['c', 'u', 'd']] as $group) {
                $picked = implode('', array_values(array_filter(['c', 'r', 'u', 'd', 's'], static fn($a) => in_array($a, $actions, true) && in_array($a, $group, true))));
                if ($picked !== '') {
                    $submitted[] = $base . '.' . $picked;
                }
            }
        } else {
            $submitted[] = $base . '.' . implode('', array_values(array_filter(['c', 'r', 'u', 'd', 's'], static fn($a) => in_array($a, $actions, true))));
        }
    }
    $kept = [];
    foreach ($submitted as $scope) {
        if (in_array($scope, $deny, true)) {
            continue;
        }
        if (in_array($scope, $requested, true)) {
            $kept[] = $scope;
            continue;
        }
        foreach ($requested as $req) {
            if (
                preg_replace('~\.\w+$~', '', $req) === preg_replace('~\.\w+$~', '', $scope)
                && array_diff(str_split($perms($scope)), str_split($perms($req))) === []
            ) {
                $kept[] = $scope;
                break;
            }
        }
    }
    return $kept;
};
$tokenAllows = static function (array $granted, string $permission) use ($perms): bool {
    foreach ($granted as $scope) {
        if (str_starts_with($scope, 'user/QuestionnaireResponse.') && str_contains($perms($scope), $permission)) {
            return true;
        }
    }
    return false;
};
$scopeOk = static function (string $scope) use ($advertised): bool {
    if (in_array($scope, $advertised, true)) {
        return true;
    }
    if (preg_match('~^(\w+/\w+)\.([a-z]+)$~', $scope, $m) !== 1) {
        return false;
    }
    foreach ($advertised as $offer) {
        if (
            preg_match('~^' . preg_quote($m[1], '~') . '\.([a-z]+)$~', $offer, $o) === 1
            && array_diff(str_split($m[2]), str_split($o[1])) === []
        ) {
            return true;
        }
    }
    return false;
};
$patient = ['resourceType' => 'Patient', 'id' => 'p1', 'name' => [['given' => ['Ada'], 'family' => 'Lovelace']], 'birthDate' => '1815-12-10', 'gender' => 'female'];
$other = ['resourceType' => 'Patient', 'id' => 'p2'];
$questionnaire = [
    'resourceType' => 'Questionnaire', 'id' => 'q1', 'status' => 'active', 'title' => 'SDOH Screen',
    'item' => [
        ['linkId' => 'housing', 'text' => 'Housing stable?', 'type' => 'boolean', 'required' => true],
        ['linkId' => 'notes', 'text' => 'Notes', 'type' => 'string'],
    ],
];

switch (true) {
    case $path === '/fhir/.well-known/smart-configuration':
        $json(200, [
            'authorization_endpoint' => $base . '/oauth/authorize',
            'token_endpoint' => $base . '/oauth/token',
            'registration_endpoint' => $base . '/oauth/registration',
            'code_challenge_methods_supported' => ['S256'],
            // Nested one level, as OpenEMR publishes it; reads and writes offered separately.
            'scopes_supported' => [$advertised],
        ]);
        // no break: $json() exits
    case $path === '/oauth/registration' && $method === 'POST':
        $body = json_decode((string)file_get_contents('php://input'), true);
        foreach (explode(' ', (string)($body['scope'] ?? '')) as $requested) {
            if (!$scopeOk($requested)) {
                $json(400, ['error' => 'invalid_scope', 'error_description' => 'The requested scope is invalid, unknown, or malformed',
                    'hint' => "Check the `{$requested}` scope", 'message' => 'The requested scope is invalid, unknown, or malformed']);
            }
        }
        $id = 'client-' . (++$state['seq']);
        $state['clients'][$id] = ['secret' => 'secret-' . $id, 'redirect_uris' => $body['redirect_uris'] ?? [], 'body' => $body, 'reg_token' => 'rt-' . $id];
        $json(201, ['client_id' => $id, 'client_secret' => 'secret-' . $id, 'registration_access_token' => 'rt-' . $id,
            'registration_client_uri' => $base . '/oauth/client/' . $id]);
        // no break: $json() exits
    case preg_match('~^/oauth/client/([\w-]+)$~', $path, $cm) === 1:
        $client = $state['clients'][$cm[1]] ?? null;
        if ($client === null || ($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer ' . $client['reg_token']) {
            $json(403, ['error' => 'invalid_request']);
        }
        $json(200, ['client_id' => $cm[1], 'client_secret' => $client['secret'], 'client_name' => $client['body']['client_name'] ?? '',
            'application_type' => 'user', 'redirect_uris' => $client['redirect_uris']]);
        // no break: $json() exits
    case $path === '/oauth/authorize':
        $client = $state['clients'][$_GET['client_id'] ?? ''] ?? null;
        if ($client === null || !in_array($_GET['redirect_uri'] ?? '', $client['redirect_uris'], true)) {
            $json(400, ['error' => 'invalid_client']);
        }
        if (($_GET['aud'] ?? '') !== $base . '/fhir' || ($_GET['code_challenge_method'] ?? '') !== 'S256') {
            $json(400, ['error' => 'invalid_request', 'error_description' => 'bad aud or PKCE']);
        }
        $code = bin2hex(random_bytes(8));
        $state['codes'][$code] = [
            'client_id' => $_GET['client_id'], 'challenge' => $_GET['code_challenge'],
            'redirect_uri' => $_GET['redirect_uri'], 'launch' => $_GET['launch'] ?? null,
            'scope' => implode(' ', $consent(explode(' ', (string)($_GET['scope'] ?? '')), $context['denyScopes'] ?? [], !empty($context['consentFixed']))),
        ];
        $save();
        header('Location: ' . $_GET['redirect_uri'] . '?' . http_build_query(['code' => $code, 'state' => $_GET['state'] ?? '']));
        exit;

    case $path === '/oauth/token' && $method === 'POST':
        $grant = $state['codes'][$_POST['code'] ?? ''] ?? null;
        unset($state['codes'][$_POST['code'] ?? '']);
        $client = $grant ? ($state['clients'][$grant['client_id']] ?? null) : null;
        if (
            $grant === null || $client === null
            || ($_POST['client_secret'] ?? '') !== $client['secret']
            || ($_POST['redirect_uri'] ?? '') !== $grant['redirect_uri']
            || $b64u(hash('sha256', (string)($_POST['code_verifier'] ?? ''), true)) !== $grant['challenge']
        ) {
            $json(
                $client !== null && ($_POST['client_secret'] ?? '') !== $client['secret'] ? 401 : 400,
                $client !== null && ($_POST['client_secret'] ?? '') !== $client['secret']
                    ? ['error' => 'invalid_client', 'error_description' => 'Client authentication failed', 'message' => 'Client authentication failed']
                : ['error' => 'invalid_grant']
            );
        }
        $access = bin2hex(random_bytes(12));
        $state['tokens'][$access] = explode(' ', $grant['scope']);
        unset($context['denyScopes'], $context['consentFixed']);
        $json(200, ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => $grant['scope'], 'patient' => 'p1'] + $context);
}

// ---- FHIR (bearer required) ----
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!str_starts_with($auth, 'Bearer ') || !isset($state['tokens'][substr($auth, 7)])) {
    $json(401, $outcome('invalid token'));
}

$granted = (array)$state['tokens'][substr($auth, 7)];
// Like OpenEMR's route check: writes need c/u in a granted QuestionnaireResponse scope (401 otherwise).
if (
    str_starts_with($path, '/fhir/QuestionnaireResponse') && in_array($method, ['POST', 'PUT'], true)
    && !$tokenAllows($granted, $method === 'POST' ? 'c' : 'u')
) {
    $json(401, ['error' => 'An error occurred', 'message' => 'Unauthorized', 'code' => 0]);
}
if ($path === '/fhir/Patient/p1') {
    $json(200, $patient);
}
if ($path === '/fhir/Questionnaire/q1') {
    $json(200, $questionnaire);
}
if ($path === '/fhir/Questionnaire' && $method === 'GET') {
    $json(200, ['resourceType' => 'Bundle', 'type' => 'searchset', 'entry' => [['resource' => $questionnaire]]]);
}
if ($path === '/fhir/QuestionnaireResponse' && $method === 'GET') {
    $entries = [];
    foreach ($state['responses'] as $response) {
        if (($response['subject']['reference'] ?? '') === 'Patient/' . ($_GET['patient'] ?? '')) {
            $entries[] = ['resource' => $response];
        }
    }
    $json(200, ['resourceType' => 'Bundle', 'type' => 'searchset', 'entry' => $entries]);
}
$validate = static function (array $body) use ($json, $outcome): void {
    if (($body['resourceType'] ?? '') !== 'QuestionnaireResponse' || ($body['questionnaire'] ?? '') === '' || !isset($body['subject']['reference'])) {
        $json(400, $outcome('missing questionnaire or subject'));
    }
};
if ($path === '/fhir/QuestionnaireResponse' && $method === 'POST') {
    $body = json_decode((string)file_get_contents('php://input'), true);
    $validate($body);
    $id = 'qr' . (++$state['seq']);
    $body['id'] = $id;
    $state['responses'][$id] = $body;
    // Like OpenEMR, answer a create with an id stub rather than the resource.
    $json(201, ['uuid' => $id], ['Location' => $base . '/fhir/QuestionnaireResponse/' . $id . '/_history/1']);
}
if (preg_match('~^/fhir/QuestionnaireResponse/([\w.-]+)$~', $path, $m)) {
    if (!isset($state['responses'][$m[1]])) {
        $json(404, $outcome('not found'));
    }
    if ($method === 'GET') {
        $json(200, $state['responses'][$m[1]]);
    }
    if ($method === 'PUT') {
        $body = json_decode((string)file_get_contents('php://input'), true);
        $validate($body);
        if (($body['id'] ?? null) !== $m[1]) {
            $json(400, $outcome('id mismatch'));
        }
        $state['responses'][$m[1]] = $body;
        $json(200, $body);
    }
}
$json(404, $outcome('no route ' . $method . ' ' . $path));
