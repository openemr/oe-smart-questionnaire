<?php

/**
 * End-to-end: real HTTP through launch → authorize → callback → workspace → save,
 * against tests/mock/ehr.php.   php tests/e2e.php
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @link      https://github.com/openemr/oe-smart-questionnaire
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$work = sys_get_temp_dir() . '/sq-e2e-' . getmypid();
mkdir($work . '/mock', 0700, true);
$appPort = 18090;
$ehrPort = 18091;
$app = "http://127.0.0.1:{$appPort}";
$iss = "http://127.0.0.1:{$ehrPort}/fhir";

file_put_contents($work . '/config.php', '<?php return ' . var_export([
    'app_url' => $app,
    'verify_tls' => false,
    'clients_file' => $work . '/clients.json',
    'sites' => ['mock' => "http://127.0.0.1:{$ehrPort}/fhir"],
], true) . ';');
putenv("SQ_CONFIG={$work}/config.php");
putenv("MOCK_STATE_DIR={$work}/mock");

// Portable across Linux, macOS and Windows (cmd.exe): no VAR=value prefixes, no /dev/null, and the
// PHP binary running this script (XAMPP's php.exe need not be on PATH).
$php = PHP_BINARY;
$nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$serverEnv = static fn(array $extra): array => array_merge(getenv(), $extra);
/** Runs a PHP script with SQ_CONFIG pointing at $config; returns [exit code, output]. */
$runPhp = static function (string $config, string $script, string $args) use ($php, $work): array {
    putenv("SQ_CONFIG={$config}");
    exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . $args . ' 2>&1', $out, $code);
    putenv("SQ_CONFIG={$work}/config.php");
    return [$code, implode("\n", $out)];
};

$servers = [
    proc_open(
        [$php, '-S', "127.0.0.1:{$ehrPort}", "{$root}/tests/mock/ehr.php"],
        [1 => ['file', $nullDevice, 'w'], 2 => ['file', "{$work}/ehr.log", 'w']],
        $p,
        null,
        $serverEnv(['MOCK_STATE_DIR' => "{$work}/mock"])
    ),
    proc_open(
        [$php, '-S', "127.0.0.1:{$appPort}", '-t', "{$root}/public"],
        [1 => ['file', $nullDevice, 'w'], 2 => ['file', "{$work}/app.log", 'w']],
        $p,
        null,
        $serverEnv(['SQ_CONFIG' => "{$work}/config.php"])
    ),
];
register_shutdown_function(static function () use ($servers): void {
    foreach ($servers as $server) {
        proc_terminate($server);
    }
});
usleep(600000);

$failures = 0;
$count = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $count;
    $count++;
    echo ($ok ? 'ok    ' : 'FAIL  ') . $name . ($ok || $detail === '' ? '' : " — {$detail}") . "\n";
    $failures += $ok ? 0 : 1;
}

/** @return array{status: int, body: string, url: string} */
function http(string $method, string $url, string $jar, ?string $body = null, array $headers = [], bool $follow = true): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        // CURLOPT_POST (not CUSTOMREQUEST) so a 302 after a form post is followed with GET, like a browser.
        $method === 'POST' ? CURLOPT_POST : CURLOPT_CUSTOMREQUEST => $method === 'POST' ? true : $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $out = (string)curl_exec($ch);
    $result = ['status' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => $out, 'url' => (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)];
    curl_close($ch);
    return $result;
}
function pageData(string $html): ?array
{
    return preg_match('~<script type="application/json" id="sq-data">(.*?)</script>~s', $html, $m) === 1
        ? json_decode($m[1], true)
        : null;
}
function mockState(string $work): array
{
    return json_decode((string)file_get_contents("{$work}/mock/state.json"), true);
}
function Config_has(string $work, string $iss): bool
{
    $clients = json_decode((string)@file_get_contents("{$work}/clients.json"), true);
    return is_array($clients) && isset($clients[$iss]['client_id']);
}
function setContext(string $work, array $context): void
{
    file_put_contents("{$work}/mock/context.json", json_encode($context));
}
$jar = "{$work}/cookies.txt";

// --- registration --------------------------------------------------------------------------
$cli = static function (string $script, string $args) use ($work, $root, $runPhp): array {
    return $runPhp("{$work}/config.php", "{$root}/bin/{$script}", $args);
};
[$code, $outText] = $cli('register.php', '--site=mock');
$out = [$outText];
$clients = json_decode((string)@file_get_contents("{$work}/clients.json"), true);
check('register.php stores confidential client', $code === 0 && isset($clients[$iss]['client_secret']), implode("\n", $out));
$registered = array_values(mockState($work)['clients'])[0]['body'] ?? [];
check('registered as private with launch URI', ($registered['application_type'] ?? '') === 'private' && ($registered['initiate_login_uri'] ?? '') === "{$app}/launch.php");
check('client named after site', ($registered['client_name'] ?? '') === 'OpenEMR SMART Questionnaires (mock)');
[$code, $outText] = $cli('register.php', '--list');
check('CLI --list shows the site as registered', $code === 0 && str_contains($outText, 'client client-1'), $outText);
[$code, $outText] = $cli('register.php', '--site=mock --check');
check('CLI --check confirms the registration', $code === 0 && str_contains($outText, 'Registration confirmed'), $outText);
check('CLI --check confirms scopes are offered', str_contains($outText, 'All configured scopes are offered'), $outText);
check('registered QuestionnaireResponse as v1 read + write', str_contains($registered['scope'] ?? '', 'user/QuestionnaireResponse.read user/QuestionnaireResponse.write')
    && !str_contains($registered['scope'] ?? '', 'QuestionnaireResponse.crus'));
// v2 split scopes pass registration on OpenEMR but are merged and dropped at consent: refuse them.
$splitConfig = "{$work}/config-split.php";
file_put_contents($splitConfig, '<?php $c = require ' . var_export("{$work}/config.php", true) . '; '
    . '$c["ehr_scopes"] = "openid fhirUser launch user/Patient.rs user/Questionnaire.rs user/QuestionnaireResponse.rs user/QuestionnaireResponse.cu"; '
    . '$c["clients_file"] = ' . var_export("{$work}/clients-split.json", true) . '; return $c;');
[$splitCode, $splitText] = $runPhp($splitConfig, "{$root}/bin/register.php", '--site=mock');
check('v2 read/write split refused before registering', $splitCode !== 0 && str_contains($splitText, 'consent screen merges')
    && str_contains($splitText, 'user/QuestionnaireResponse.read') && !is_file("{$work}/clients-split.json"), $splitText);
// A config with the old combined scope is stopped before registering, naming the scope.
$badConfig = "{$work}/config-bad.php";
file_put_contents($badConfig, '<?php $c = require ' . var_export("{$work}/config.php", true) . '; '
    . '$c["ehr_scopes"] = "openid fhirUser launch user/Patient.rs user/Questionnaire.rs user/QuestionnaireResponse.crus"; '
    . '$c["clients_file"] = ' . var_export("{$work}/clients-bad.json", true) . '; return $c;');
[$badCode, $badText] = $runPhp($badConfig, "{$root}/bin/register.php", '--site=mock');
check('unsupported scope named before registering', $badCode !== 0 && str_contains($badText, 'user/QuestionnaireResponse.crus')
    && str_contains($badText, 'user/QuestionnaireResponse.cud') && !is_file("{$work}/clients-bad.json"), $badText);
[$code, $outText] = $cli('register.php', '--all');
check('CLI --all skips registered sites', $code === 0 && str_contains($outText, 'already registered'), $outText);

// --- saved secret out of sync with the server: diagnose and repair ---------------------------------
$clientsRaw = json_decode((string)file_get_contents("{$work}/clients.json"), true);
$goodSecret = $clientsRaw[$iss]['client_secret'];
$clientsRaw[$iss]['client_secret'] = 'stale-secret';
file_put_contents("{$work}/clients.json", json_encode($clientsRaw));
setContext($work, ['fhirContext' => [['reference' => 'Questionnaire/q1']]]);
$r = http('GET', "{$app}/launch.php?" . http_build_query(['iss' => $iss, 'launch' => 'Ls']), $jar);
check('stale secret: token request fails with guidance', str_contains($r['body'], 'Token request failed')
    && str_contains($r['body'], 'Repair secret'), strip_tags(substr($r['body'], 0, 1500)));
[$code, $outText] = $cli('register.php', '--site=mock --check');
check('Check reports the secret mismatch', $code !== 0 && str_contains($outText, 'does not match'), $outText);
[$code, $outText] = $cli('register.php', '--site=mock --repair');
$clientsRaw = json_decode((string)file_get_contents("{$work}/clients.json"), true);
check('--repair restores the server secret', $code === 0 && ($clientsRaw[$iss]['client_secret'] ?? '') === $goodSecret, $outText);
[$code, $outText] = $cli('register.php', '--site=mock --check');
check('Check passes after repair', $code === 0 && str_contains($outText, 'Client secret matches'), $outText);
$r = http('GET', "{$app}/launch.php?" . http_build_query(['iss' => $iss, 'launch' => 'Ls2']), $jar);
check('launch works after repair', pageData($r['body']) !== null);

// --- unknown issuer is refused ---------------------------------------------------------------
$r = http('GET', "{$app}/launch.php?" . http_build_query(['iss' => 'http://127.0.0.1:1/fhir', 'launch' => 'x']), $jar, null, [], false);
check('unregistered iss refused', $r['status'] === 400);

// --- EHR launch with a Questionnaire in context ----------------------------------------------
setContext($work, [
    'need_patient_banner' => true,
    'intent' => 'questionnaire.assessment.dialog',
    'fhirContext' => [['type' => 'Questionnaire', 'reference' => 'Questionnaire/q1', 'canonical' => "{$iss}/Questionnaire/q1"]],
    'appContext' => '{"workflow":"questionnaire-assessment","action":"start"}',
]);
$r = http('GET', "{$app}/launch.php?" . http_build_query(['iss' => $iss, 'launch' => 'L1']), $jar);
$data = pageData($r['body']);
check('launch lands on workspace', $r['status'] === 200 && $data !== null, "HTTP {$r['status']} at {$r['url']}");
check('questionnaire from context rendered', ($data['questionnaire']['id'] ?? null) === 'q1');
check('patient banner shown', str_contains($r['body'], 'Ada Lovelace'));
check('no scope warning when everything was granted', !str_contains($r['body'], 'The server did not grant'));
check('runtime tag carries the OpenEMR version from metadata', str_contains($r['body'], 'data-openemr-version="8.1.0-mock"'));
check('new response to start', is_array($data) && array_key_exists('initialResponseId', $data) && $data['initialResponseId'] === null);
check('launch, aud and PKCE sent to authorize', (bool)preg_grep('~^GET /oauth/authorize\?.*launch=L1.*code_challenge_method=S256~', mockState($work)['log'])
    || (bool)preg_grep('~^GET /oauth/authorize\?(?=.*launch=L1)(?=.*aud=)(?=.*code_challenge_method=S256)~', mockState($work)['log']));
$headers = [];
$ch = curl_init("{$app}/launch.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADERFUNCTION => static function ($c, $line) use (&$headers) {
    $headers[] = trim($line);
    return strlen($line);
}]);
curl_exec($ch);
curl_close($ch);
$csp = implode("\n", preg_grep('~^Content-Security-Policy:~i', $headers));
check('CSP frame-ancestors allows the registered EHR origin', str_contains($csp, "frame-ancestors 'self' http://127.0.0.1:{$ehrPort}"), $csp);
file_put_contents("{$work}/workspace.html", $r['body']);
$lid = $data['launchId'] ?? '';
$csrf = $data['csrf'] ?? '';
$api = "{$app}/api.php?" . http_build_query(['lid' => $lid, 'action' => 'save']);
$jsonHeaders = ['Content-Type: application/json', "X-CSRF-Token: {$csrf}"];

// --- saving ------------------------------------------------------------------------------------
$draft = ['resourceType' => 'QuestionnaireResponse', 'status' => 'in-progress', 'subject' => ['reference' => 'Patient/p2'],
    'item' => [['linkId' => 'housing', 'answer' => [['valueBoolean' => false]]]]];
$r = http('POST', $api, $jar, json_encode($draft), ['Content-Type: application/json', 'X-CSRF-Token: wrong']);
check('bad CSRF rejected', $r['status'] === 403);

$r = http('POST', $api, $jar, json_encode($draft), $jsonHeaders);
$saved = json_decode($r['body'], true)['resource'] ?? null;
check('draft created via POST', $r['status'] === 200 && is_string($saved['id'] ?? null), $r['body']);
$id = $saved['id'] ?? '';
$stored = mockState($work)['responses'][$id] ?? [];
check('subject stamped to launch patient (client said p2)', ($stored['subject']['reference'] ?? '') === 'Patient/p1');
check('questionnaire stamped from launch', ($stored['questionnaire'] ?? '') === 'Questionnaire/q1');

$complete = ['id' => $id, 'status' => 'completed'] + $draft;
$r = http('POST', $api, $jar, json_encode($complete), $jsonHeaders);
$saved = json_decode($r['body'], true)['resource'] ?? null;
check('completed via PUT to same id', $r['status'] === 200 && ($saved['id'] ?? null) === $id && ($saved['status'] ?? null) === 'completed', $r['body']);
check('no duplicate created', count(mockState($work)['responses']) === 1);
check('PUT was used', in_array("PUT /fhir/QuestionnaireResponse/{$id}", mockState($work)['log'], true));

// --- editing someone else's response is refused -------------------------------------------------
$state = mockState($work);
$state['responses']['foreign'] = ['resourceType' => 'QuestionnaireResponse', 'id' => 'foreign', 'status' => 'in-progress',
    'questionnaire' => 'Questionnaire/q1', 'subject' => ['reference' => 'Patient/p2']];
file_put_contents("{$work}/mock/state.json", json_encode($state));
$r = http('POST', $api, $jar, json_encode(['id' => 'foreign'] + $draft), $jsonHeaders);
check('response outside the launch refused', $r['status'] === 422, $r['body']);

// --- relaunch continuing the saved response -----------------------------------------------------
setContext($work, [
    'fhirContext' => [['reference' => 'Questionnaire/q1'], ['reference' => "QuestionnaireResponse/{$id}"]],
    'appContext' => '{"action":"continue"}',
]);
$r = http('GET', "{$app}/launch.php?" . http_build_query(['iss' => $iss, 'launch' => 'L2']), $jar);
$data2 = pageData($r['body']);
check('continue launch opens that response', ($data2['initialResponseId'] ?? null) === $id);
check('history lists only this patient', count($data2['responses'] ?? []) === 1);
check('first launch still usable (per-launch state)', pageData(http('GET', "{$app}/index.php?lid={$lid}", $jar)['body']) !== null);

// --- standalone launch without a questionnaire shows the picker --------------------------------
setContext($work, []);
$r = http('GET', "{$app}/launch.php?" . http_build_query(['iss' => $iss]), $jar);
check('standalone launch shows questionnaire picker', str_contains($r['body'], 'Choose a questionnaire') && str_contains($r['body'], 'SDOH Screen'));
$standaloneLid = preg_match('~index\.php\?lid=([\w-]+)~', $r['url'], $m) === 1 ? $m[1] : '';
$picked = pageData(http('GET', "{$app}/index.php?" . http_build_query(['lid' => $standaloneLid, 'q' => 'q1']), $jar)['body']);
check('picking a questionnaire opens it', ($picked['questionnaire']['id'] ?? null) === 'q1' && ($picked['canPick'] ?? false) === true);
$r2 = http('GET', "{$app}/index.php?" . http_build_query(['lid' => $standaloneLid, 'pick' => '1']), $jar);
check('"choose another" returns to picker', str_contains($r2['body'], 'Choose a questionnaire'));
$scopes = array_values(mockState($work)['log']);
check('standalone requests launch/patient', (bool)preg_grep('~/oauth/authorize\?.*launch%2Fpatient~', $scopes));

// --- replayed authorization response is refused -------------------------------------------------
$r = http('GET', "{$app}/callback.php?code=abc&state=replayed", $jar);
check('unknown state refused', $r['status'] === 400);

// --- expired / unknown launch ------------------------------------------------------------------
$r = http('GET', "{$app}/index.php?lid=nope", $jar);
check('unknown launch id refused', $r['status'] === 401);

// --- a launch that lost QuestionnaireResponse write says so, and save explains the 401 -------------
setContext($work, [
    'fhirContext' => [['reference' => 'Questionnaire/q1']],
    'appContext' => '{"action":"start"}',
    'denyScopes' => ['user/QuestionnaireResponse.write'],
]);
$r = http('GET', "{$app}/launch.php?" . http_build_query(['iss' => $iss, 'launch' => 'Lnw']), $jar);
$nw = pageData($r['body']);
check('workspace warns when write scope was not granted', str_contains($r['body'], 'The server did not grant: user/QuestionnaireResponse.write'));
$r = http(
    'POST',
    "{$app}/api.php?" . http_build_query(['lid' => $nw['launchId'] ?? '', 'action' => 'save']),
    $jar,
    json_encode($draft),
    ['Content-Type: application/json', 'X-CSRF-Token: ' . ($nw['csrf'] ?? '')]
);
check('save without write scope explains the 401', $r['status'] === 401 && str_contains($r['body'], 'does not allow writing QuestionnaireResponse'), $r['body']);

// --- OpenEMR "review" of a finished response opens read-only --------------------------------------
setContext($work, [
    'fhirContext' => [['reference' => 'Questionnaire/q1'], ['reference' => "QuestionnaireResponse/{$id}"]],
    'appContext' => '{"action":"review"}',
]);
$review = pageData(http('GET', "{$app}/launch.php?" . http_build_query(['iss' => $iss, 'launch' => 'L3']), $jar)['body']);
check('review action opens the response read-only', ($review['reviewOnly'] ?? false) === true && ($review['initialResponseId'] ?? null) === $id);

// --- v2 scope profile: registers with a note; unfixed OpenEMR drops it, fixed OpenEMR keeps it ----
[$code, $outText] = $cli('register.php', '--site=mock --scopes=v2');
$v2Client = json_decode((string)file_get_contents("{$work}/clients.json"), true)[$iss] ?? [];
check('--scopes=v2 registers with a consent-fix note', $code === 0 && str_contains($outText, '(v2 scopes)') && str_contains($outText, 'consent screen'), $outText);
check('client record stores the v2 scopes', ($v2Client['scope_profile'] ?? '') === 'v2'
    && str_contains($v2Client['ehr_scopes'] ?? '', 'user/QuestionnaireResponse.rs user/QuestionnaireResponse.cu'));
$v2Registered = end(mockState($work)['clients'])['body']['scope'] ?? '';
check('v2 registration sent .rs + .cu', str_contains($v2Registered, 'user/QuestionnaireResponse.rs user/QuestionnaireResponse.cu'), $v2Registered);
[$code, $outText] = $cli('register.php', '--list');
check('--list shows the v2 profile', str_contains($outText, '(v2 scopes)') && str_contains($outText, 'Scope profile v2:'), $outText);

setContext($work, ['fhirContext' => [['reference' => 'Questionnaire/q1']], 'appContext' => '{"action":"start"}']);
$r = http('GET', "{$app}/launch.php?" . http_build_query(['iss' => $iss, 'launch' => 'Lv2a']), $jar);
check('v2 launch requests .rs + .cu', (bool)preg_grep('~/oauth/authorize\?.*QuestionnaireResponse\.rs\+user%2FQuestionnaireResponse\.cu~', mockState($work)['log']));
check('unfixed OpenEMR: workspace explains the v2 profile needs the fix', str_contains($r['body'], 'The server did not grant')
    && str_contains($r['body'], 'v2 scope profile'), strip_tags(substr($r['body'], 0, 2500)));
$v2Page = pageData($r['body']);
$r = http(
    'POST',
    "{$app}/api.php?" . http_build_query(['lid' => $v2Page['launchId'] ?? '', 'action' => 'save']),
    $jar,
    json_encode($draft),
    ['Content-Type: application/json', 'X-CSRF-Token: ' . ($v2Page['csrf'] ?? '')]
);
check('unfixed OpenEMR: v2 save fails with 401', $r['status'] === 401);

setContext($work, ['fhirContext' => [['reference' => 'Questionnaire/q1']], 'appContext' => '{"action":"start"}', 'consentFixed' => true]);
$r = http('GET', "{$app}/launch.php?" . http_build_query(['iss' => $iss, 'launch' => 'Lv2b']), $jar);
$v2Page = pageData($r['body']);
check('fixed OpenEMR: v2 launch has no scope warning', $v2Page !== null && !str_contains($r['body'], 'The server did not grant'));
$r = http(
    'POST',
    "{$app}/api.php?" . http_build_query(['lid' => $v2Page['launchId'] ?? '', 'action' => 'save']),
    $jar,
    json_encode($draft),
    ['Content-Type: application/json', 'X-CSRF-Token: ' . ($v2Page['csrf'] ?? '')]
);
check('fixed OpenEMR: v2 save succeeds', $r['status'] === 200 && (json_decode($r['body'], true)['success'] ?? false) === true, $r['body']);

[$code, $outText] = $cli('register.php', '--site=mock --scopes=v9');
check('unknown scope profile refused', $code !== 0 && str_contains($outText, "Unknown scope profile 'v9'"), $outText);
[$code, $outText] = $cli('register.php', '--site=mock');
check('re-register without --scopes returns to the site default (v1)', $code === 0 && str_contains($outText, '(v1 scopes)'), $outText);

// --- admin console ---------------------------------------------------------------------------------
$adminJar = "{$work}/admin-cookies.txt";
check('admin locked until a password is set', http('GET', "{$app}/admin.php", $adminJar)['status'] === 503);
[$code] = $cli('set-admin-password.php', '--password=' . escapeshellarg('correct horse battery'));
check('set-admin-password.php', $code === 0);
[$code, $outText] = $cli('set-admin-password.php', '--password=short');
check('short admin password refused', $code !== 0);
$r = http('POST', "{$app}/admin.php", $adminJar, http_build_query(['action' => 'login', 'password' => 'wrong password!']));
check('wrong admin password refused', str_contains($r['body'], 'Incorrect password') && !str_contains($r['body'], 'client registration'));
$r = http('POST', "{$app}/admin.php", $adminJar, http_build_query(['action' => 'login', 'password' => 'correct horse battery']));
$currentClientId = json_decode((string)file_get_contents("{$work}/clients.json"), true)[$iss]['client_id'] ?? '?';
check('admin login shows sites', str_contains($r['body'], 'client registration') && str_contains($r['body'], $currentClientId) && str_contains($r['body'], "{$app}/launch.php"));
$adminCsrf = preg_match('~name="csrf" value="([0-9a-f]+)"~', $r['body'], $m) === 1 ? $m[1] : '';
$r = http('POST', "{$app}/admin.php", $adminJar, http_build_query(['action' => 'verify', 'site' => 'mock']));
check('admin action without CSRF refused', str_contains($r['body'], 'The form expired'));
$r = http('POST', "{$app}/admin.php", $adminJar, http_build_query(['action' => 'verify', 'site' => 'mock', 'csrf' => $adminCsrf]));
check('admin Check confirms registration', str_contains($r['body'], 'Registration confirmed'));
check('admin lists both scope profiles', str_contains($r['body'], 'Scope profile') && str_contains($r['body'], 'user/QuestionnaireResponse.rs user/QuestionnaireResponse.cu')
    && str_contains($r['body'], 'name="scope_profile"'));
$r = http('POST', "{$app}/admin.php", $adminJar, http_build_query(['action' => 'register', 'site' => 'mock', 'scope_profile' => 'v2', 'csrf' => $adminCsrf]));
check('admin registers with the chosen v2 profile and warns', str_contains($r['body'], 'with the v2 scope profile') && str_contains($r['body'], 'Scope profile note'));
check('admin shows the site\'s current profile', str_contains($r['body'], 'v2 scopes'));
$beforeId = json_decode((string)file_get_contents("{$work}/clients.json"), true)[$iss]['client_id'] ?? '?';
$r = http('POST', "{$app}/admin.php", $adminJar, http_build_query(['action' => 'register', 'site' => 'mock', 'scope_profile' => 'v1', 'csrf' => $adminCsrf]));
$clientsNow = json_decode((string)file_get_contents("{$work}/clients.json"), true);
check('admin Re-register replaces the client and says so', ($clientsNow[$iss]['client_id'] ?? '') !== $beforeId
    && str_contains($r['body'], "replaces client {$beforeId}") && ($clientsNow[$iss]['scope_profile'] ?? '') === 'v1', strip_tags(substr($r['body'], 0, 2000)));
$r = http('GET', "{$app}/launch.php?" . http_build_query(['iss' => $iss, 'launch' => 'L4']), $jar);
check('launch works with the new client', pageData($r['body']) !== null);
$r = http('POST', "{$app}/admin.php", $adminJar, http_build_query(['action' => 'record', 'site' => 'mock', 'client_id' => 'manual-1', 'client_secret' => 's', 'csrf' => $adminCsrf]));
$clientsNow = json_decode((string)file_get_contents("{$work}/clients.json"), true);
check('admin records a hand-made client', ($clientsNow[$iss]['client_id'] ?? '') === 'manual-1' && !empty($clientsNow[$iss]['recorded_manually']));
$r = http('POST', "{$app}/admin.php", $adminJar, http_build_query(['action' => 'forget', 'site' => 'mock', 'csrf' => $adminCsrf]));
check('admin Forget removes credentials', json_decode((string)file_get_contents("{$work}/clients.json"), true) === []);
$r = http('POST', "{$app}/admin.php", $adminJar, http_build_query(['action' => 'register_all', 'csrf' => $adminCsrf]));
check('admin Register all registers the site again', str_contains($r['body'], 'Registered:') && Config_has($work, $iss));
$r = http('POST', "{$app}/admin.php", $adminJar, http_build_query(['action' => 'logout', 'csrf' => $adminCsrf]));
check('admin sign out', str_contains(http('GET', "{$app}/admin.php", $adminJar)['body'], 'Admin password'));

// Browser side (optional): real runtime + app.js in jsdom against the captured page.
exec('node -e "require(\'jsdom\')" 2>' . $nullDevice, $ignored, $noJsdom);
if ($noJsdom === 0) {
    exec('node ' . escapeshellarg("{$root}/tests/browser-smoke.js") . ' ' . escapeshellarg("{$work}/workspace.html")
        . ' ' . escapeshellarg("{$root}/public") . ' 2>&1', $browserOut, $browserCode);
    echo implode("\n", $browserOut) . "\n";
    check('browser smoke test (jsdom)', $browserCode === 0);
} else {
    echo "skip  browser smoke test (npm i jsdom to enable)\n";
}

$appLog = (string)file_get_contents("{$work}/app.log");
check('no PHP warnings/errors in app log', !preg_match('~(Warning|Fatal|Deprecated|Notice)~', $appLog), $appLog);

echo ($failures === 0 ? 'OK' : 'FAILED') . " ({$count} checks, {$failures} failures)\n";
exit($failures === 0 ? 0 : 1);
