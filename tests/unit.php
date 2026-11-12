<?php

/**
 * Dependency-free unit checks: php tests/unit.php
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
use OpenEMR\SmartQuestionnaire\HomeVitals;
use OpenEMR\SmartQuestionnaire\LaunchSession;
use OpenEMR\SmartQuestionnaire\Refs;
use OpenEMR\SmartQuestionnaire\Registrar;
use OpenEMR\SmartQuestionnaire\ScopeCheck;
use OpenEMR\SmartQuestionnaire\ScopeProfiles;
use OpenEMR\SmartQuestionnaire\Sites;
use OpenEMR\SmartQuestionnaire\ResponseStamper;
use OpenEMR\SmartQuestionnaire\SmartClient;
use OpenEMR\SmartQuestionnaire\View;

$failures = 0;
$count = 0;
function check(string $name, bool $ok): void
{
    global $failures, $count;
    $count++;
    if (!$ok) {
        $failures++;
        echo "FAIL  {$name}\n";
    }
}
function throws(callable $fn): ?string
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e->getMessage();
    }
    return null;
}

// PKCE: RFC 7636 appendix B test vector.
check('pkce S256 vector', SmartClient::challengeFor('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk') === 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
check('verifier length within 43..128', strlen(SmartClient::newVerifier()) >= 43 && strlen(SmartClient::newVerifier()) <= 128);

// References.
check('relative ref', Refs::idFrom('Questionnaire/abc-1', 'Questionnaire') === 'abc-1');
check('absolute ref', Refs::idFrom('https://h/fhir/Questionnaire/abc', 'Questionnaire') === 'abc');
check('history ref', Refs::idFrom('https://h/fhir/QuestionnaireResponse/r1/_history/3', 'QuestionnaireResponse') === 'r1');
check('versioned canonical', Refs::idFrom('https://h/fhir/Questionnaire/abc|2.0', 'Questionnaire') === 'abc');
check('wrong type', Refs::idFrom('Patient/abc', 'Questionnaire') === null);
check('no type prefix match', Refs::idFrom('MyQuestionnaire/abc', 'Questionnaire') === null);
check('non-string', Refs::idFrom(null, 'Questionnaire') === null);

$ctx = Refs::launchContext(['fhirContext' => [
    ['type' => 'Questionnaire', 'reference' => 'Questionnaire/q1', 'canonical' => 'https://x/fhir/Questionnaire/q1'],
    ['type' => 'QuestionnaireResponse', 'reference' => 'QuestionnaireResponse/r1'],
]]);
check('launch context q', $ctx['questionnaire'] === 'q1');
check('launch context qr', $ctx['response'] === 'r1');
check('canonical-only context', Refs::launchContext(['fhirContext' => [['canonical' => 'https://x/fhir/Questionnaire/q9']]])['questionnaire'] === 'q9');
check('string fhirContext', Refs::launchContext(['fhirContext' => '["Questionnaire/q2"]'])['questionnaire'] === 'q2');
check('appContext json string', Refs::appContext(['appContext' => '{"action":"continue"}'])['action'] === 'continue');
check('appContext garbage', Refs::appContext(['appContext' => 'nope']) === []);

// Stamping a new response.
$new = ResponseStamper::stamp([
    'resourceType' => 'QuestionnaireResponse',
    'status' => 'completed',
    'questionnaire' => 'Questionnaire/evil',
    'subject' => ['reference' => 'Patient/evil'],
    'encounter' => ['reference' => 'Encounter/evil'],
    'item' => [['linkId' => 'a', 'answer' => [['valueBoolean' => true]]]],
], 'p1', 'q1', []);
check('new: subject stamped', $new['subject']['reference'] === 'Patient/p1');
check('new: questionnaire stamped', $new['questionnaire'] === 'Questionnaire/q1');
check('new: client encounter dropped', !isset($new['encounter']));
check('new: status kept', $new['status'] === 'completed');
check('new: items kept', $new['item'][0]['linkId'] === 'a');
check('new: no id', !isset($new['id']));
check('bad status coerced', ResponseStamper::stamp(['resourceType' => 'QuestionnaireResponse', 'status' => 'entered-in-error'], 'p1', 'q1', [])['status'] === 'in-progress');
check('wrong resourceType', throws(fn() => ResponseStamper::stamp(['resourceType' => 'Patient'], 'p1', 'q1', [])) !== null);

// Editing.
$stored = [
    'resourceType' => 'QuestionnaireResponse', 'id' => 'r1', 'status' => 'in-progress',
    'questionnaire' => 'https://x/fhir/Questionnaire/q1|3', 'subject' => ['reference' => 'Patient/p1'],
    'encounter' => ['reference' => 'Encounter/e1'],
];
$edit = ResponseStamper::stamp(
    ['resourceType' => 'QuestionnaireResponse', 'id' => 'r1', 'status' => 'completed', 'encounter' => ['reference' => 'Encounter/evil']],
    'p1',
    'q1',
    ['r1'],
    $stored
);
check('edit: id kept', $edit['id'] === 'r1');
check('edit: stored versioned canonical kept', $edit['questionnaire'] === 'https://x/fhir/Questionnaire/q1|3');
check('edit: encounter from stored copy', $edit['encounter']['reference'] === 'Encounter/e1');
check('edit: id not in launch rejected', throws(fn() => ResponseStamper::stamp(['resourceType' => 'QuestionnaireResponse', 'id' => 'r9'], 'p1', 'q1', ['r1'], $stored)) !== null);
check('edit: without stored copy rejected', throws(fn() => ResponseStamper::stamp(['resourceType' => 'QuestionnaireResponse', 'id' => 'r1'], 'p1', 'q1', ['r1'])) !== null);
check('edit: other patient rejected', throws(fn() => ResponseStamper::stamp(['resourceType' => 'QuestionnaireResponse', 'id' => 'r1'], 'p2', 'q1', ['r1'], $stored)) !== null);
check('edit: other questionnaire rejected', throws(fn() => ResponseStamper::stamp(['resourceType' => 'QuestionnaireResponse', 'id' => 'r1'], 'p1', 'q2', ['r1'], $stored)) !== null);

// Scope preflight (OpenEMR semantics: one advertised scope must contain the request).
$advertised = ['openid', 'fhirUser', 'launch', 'launch/patient', 'user/QuestionnaireResponse.rs', 'user/QuestionnaireResponse.cud',
    'user/QuestionnaireResponse.read', 'user/Patient.rs', 'user/Observation.rs?category=http://x|sdoh'];
check('advertised: flattens nested list', ScopeCheck::advertised(['scopes_supported' => [$advertised]]) === $advertised);
check('advertised: missing → null', ScopeCheck::advertised([]) === null);
check('crus rejected (split rs/cud)', array_keys(ScopeCheck::unsupported('user/QuestionnaireResponse.crus', $advertised)) === ['user/QuestionnaireResponse.crus']);
check('rs + cu accepted', ScopeCheck::unsupported('openid fhirUser launch user/QuestionnaireResponse.rs user/QuestionnaireResponse.cu', $advertised) === []);
check('v1 read maps to rs', ScopeCheck::unsupported('user/QuestionnaireResponse.read user/Patient.read', $advertised) === []);
check('unknown plain scope rejected', array_keys(ScopeCheck::unsupported('offline_access', $advertised)) === ['offline_access']);
check('restricted offer does not grant unrestricted', array_keys(ScopeCheck::unsupported('user/Observation.rs', $advertised)) === ['user/Observation.rs']);
check('rejection lists what is offered', ScopeCheck::unsupported('user/QuestionnaireResponse.crus', $advertised)['user/QuestionnaireResponse.crus']
    === ['user/QuestionnaireResponse.rs', 'user/QuestionnaireResponse.cud', 'user/QuestionnaireResponse.read']);
check('default config scopes pass', ScopeCheck::unsupported(
    'openid fhirUser launch launch/patient user/Patient.rs user/QuestionnaireResponse.read user/QuestionnaireResponse.write',
    $advertised
) === []);

// OpenEMR consent merge guard.
check('split v2 read/write flagged', array_keys(ScopeCheck::consentMergeProblems('user/QuestionnaireResponse.rs user/QuestionnaireResponse.cu')) === ['user/QuestionnaireResponse']);
check('v1 read/write not flagged', ScopeCheck::consentMergeProblems('user/QuestionnaireResponse.read user/QuestionnaireResponse.write') === []);
check('single v2 scope not flagged', ScopeCheck::consentMergeProblems('user/Patient.rs user/Questionnaire.rs') === []);
check('nested v2 subset not flagged', ScopeCheck::consentMergeProblems('user/Patient.rs user/Patient.r') === []);
check('notGranted finds dropped write', ScopeCheck::notGranted('openid user/QuestionnaireResponse.read user/QuestionnaireResponse.write', 'openid user/QuestionnaireResponse.read') === ['user/QuestionnaireResponse.write']);
check('notGranted: v1 write covered by granted cud', ScopeCheck::notGranted('user/QuestionnaireResponse.write', 'user/QuestionnaireResponse.cud') === []);
check('notGranted ignores openid/launch omissions', ScopeCheck::notGranted('openid fhirUser launch user/Patient.rs', 'user/Patient.rs') === []);

// Scope profiles (need a config; use a throwaway one).
$tmpConfig = sys_get_temp_dir() . '/sq-unit-config-' . getmypid() . '.php';
file_put_contents($tmpConfig, '<?php return ["app_url" => "https://app.example", "clients_file" => '
    . var_export(sys_get_temp_dir() . '/sq-unit-clients.json', true)
    . ', "sites" => ["a" => "https://ehr.example/openemr", "b" => ["url" => "https://ehr2.example/fhir", "scope_profile" => "v2"]]];');
putenv("SQ_CONFIG={$tmpConfig}");
check('app host: same host matches', Config::isAppHost('app.example') && Config::isAppHost('APP.example:443'));
check('app host: another name or port does not', !Config::isAppHost('localhost') && !Config::isAppHost('app.example:8443'));
check('app host: no Host header is left alone', Config::isAppHost(null) && Config::isAppHost(''));
// Public patient client: payload, storage beside the confidential client, and what a patient is told.
check('scope writes: c/u and v1 write only', ScopeCheck::writes(['patient/QuestionnaireResponse.cu', 'patient/Patient.rs', 'user/X.write', 'openid', 'patient/Y.c'])
    === ['patient/QuestionnaireResponse.cu', 'user/X.write', 'patient/Y.c']);
$publicPayload = (new Registrar(new \OpenEMR\SmartQuestionnaire\HttpClient(false)))->publicPayload('n', 'c@example.org', 'openid launch/patient patient/Patient.rs');
check('public payload: application_type public, no auth method, no launch URI', $publicPayload['application_type'] === 'public'
    && !isset($publicPayload['token_endpoint_auth_method']) && !isset($publicPayload['initiate_login_uri'])
    && $publicPayload['redirect_uris'] === ['https://app.example/callback.php']);
@unlink(sys_get_temp_dir() . '/sq-unit-clients.json');
check('public client needs the confidential record first', throws(fn() => Config::savePublicClient('https://ehr.example/fhir', ['client_id' => 'pub'])) !== null);
Config::saveClient('https://ehr.example/fhir', ['client_id' => 'conf', 'client_secret' => 's']);
Config::savePublicClient('https://ehr.example/fhir/', ['client_id' => 'pub', 'patient_scopes' => 'openid patient/Patient.rs']);
check('public client saved beside the confidential one', (Config::publicClientFor('https://ehr.example/fhir')['client_id'] ?? null) === 'pub'
    && (Config::clientFor('https://ehr.example/fhir')['client_id'] ?? null) === 'conf');
Config::savePublicClient('https://ehr.example/fhir', null);
check('public client forgotten, confidential kept', Config::publicClientFor('https://ehr.example/fhir') === null && Config::clientFor('https://ehr.example/fhir') !== null);
// Patient portal client: launch in place of launch/patient, the portal launch URI, stored beside the others.
check('portal scopes swap launch/patient for launch', Registrar::portalScopes('openid fhirUser launch/patient patient/Patient.rs launch')
    === 'openid fhirUser launch patient/Patient.rs');
$portalPayload = (new Registrar(new \OpenEMR\SmartQuestionnaire\HttpClient(false)))->portalPayload('n', 'c@example.org', 'openid launch patient/Patient.rs');
check('portal payload: private, secret auth, portal launch URI', $portalPayload['application_type'] === 'private'
    && $portalPayload['token_endpoint_auth_method'] === 'client_secret_post'
    && $portalPayload['initiate_login_uri'] === 'https://app.example/launch.php?mode=portal'
    && $portalPayload['launch_uris'] === ['https://app.example/launch.php?mode=portal']
    && $portalPayload['redirect_uris'] === ['https://app.example/callback.php']);
Config::savePortalClient('https://ehr.example/fhir', ['client_id' => 'portal', 'client_secret' => 'ps']);
check('portal client saved beside the confidential one', (Config::portalClientFor('https://ehr.example/fhir/')['client_id'] ?? null) === 'portal'
    && (Config::clientFor('https://ehr.example/fhir')['client_id'] ?? null) === 'conf');
Config::savePortalClient('https://ehr.example/fhir', null);
check('portal client forgotten, confidential kept', Config::portalClientFor('https://ehr.example/fhir') === null && Config::clientFor('https://ehr.example/fhir') !== null);
@unlink(sys_get_temp_dir() . '/sq-unit-clients.json');
$_SESSION = [];
$grantFor = static function (string $clientType, string $granted): ?string {
    $lid = \OpenEMR\SmartQuestionnaire\LaunchStore::create([
        'issuer' => 'https://ehr.example/fhir', 'mode' => 'patient', 'client_type' => $clientType,
        'requested_scope' => 'openid fhirUser launch/patient patient/Patient.rs patient/QuestionnaireResponse.rs patient/QuestionnaireResponse.cu',
        'token' => ['access_token' => 't', 'patient' => 'p1', 'scope' => $granted], 'expires_at' => time() + 600,
    ]);
    $session = LaunchSession::resolve($lid);
    return $session instanceof LaunchSession ? $session->patientGrantNotice() : 'unresolved';
};
$allGranted = 'openid patient/Patient.rs patient/QuestionnaireResponse.rs patient/QuestionnaireResponse.cu';
$readOnly = 'openid patient/Patient.rs patient/QuestionnaireResponse.rs';
check('grant notice: none when everything was granted', $grantFor('public', $allGranted) === null);
check('grant notice: public without writes points to the confidential sign-in', str_contains((string)$grantFor('public', $readOnly), 'confidential patient sign-in'));
check('grant notice: confidential without writes says sign in again', str_contains((string)$grantFor('confidential', $readOnly), 'Sign in again'));
check('grant notice: a dropped read is named', str_contains((string)$grantFor('confidential', 'patient/QuestionnaireResponse.rs patient/QuestionnaireResponse.cu'), 'patient/Patient.rs'));
$noon = (int)strtotime('2026-11-12 12:00:00');
check('when: today, yesterday and older', View::when('2026-11-12T09:05:00', $noon) === 'Today 9:05 am'
    && View::when('2026-11-11T21:30:00', $noon) === 'Yesterday 9:30 pm' && View::when('2026-03-03', $noon) === 'Mar 3, 2026');
check('when: unreadable value returned as is', View::when('soon', $noon) === 'soon' && View::when('', $noon) === '');
check('default profile is v1', ScopeProfiles::defaultName() === 'v1');
check('v1 uses QuestionnaireResponse .read/.write', str_contains(ScopeProfiles::get('v1')['ehr'], 'user/QuestionnaireResponse.read user/QuestionnaireResponse.write'));
check('v2 uses QuestionnaireResponse .rs/.cu', str_contains(ScopeProfiles::get('v2')['ehr'], 'user/QuestionnaireResponse.rs user/QuestionnaireResponse.cu'));
check('v2 flagged as needing the consent fix', ScopeProfiles::get('v2')['needs_consent_fix'] && !ScopeProfiles::get('v1')['needs_consent_fix']);
check('standalone swaps launch for launch/patient', str_contains(ScopeProfiles::get('v2')['standalone'], 'launch/patient')
    && !preg_match('~(^| )launch( |$)~', ScopeProfiles::get('v2')['standalone']));
check('union has both launch scopes once', substr_count(Registrar::scopes('v1'), 'launch/patient') === 1 && preg_match('~(^| )launch( |$)~', Registrar::scopes('v1')) === 1);
check('site array form sets its profile', ScopeProfiles::forSite(Sites::get('b')) === 'v2' && ScopeProfiles::forSite(Sites::get('a')) === 'v1');
check('site array form keeps its URL', (Sites::get('b')['iss'] ?? '') === 'https://ehr2.example/fhir');
$stored = ScopeProfiles::forClient(['scope_profile' => 'v1', 'ehr_scopes' => 'openid launch user/X.rs', 'standalone_scopes' => 'openid launch/patient user/X.rs'], Sites::get('b'));
check('stored client scopes win over site profile', $stored['ehr'] === 'openid launch user/X.rs' && $stored['profile'] === 'v1');
check('legacy client record falls back to site profile', ScopeProfiles::forClient(['client_id' => 'x'], Sites::get('b'))['profile'] === 'v2');
check('unknown profile rejected', throws(fn() => ScopeProfiles::get('v9')) !== null);
check('explicit profile beats site profile', Registrar::resolveProfile('v1', 'b') === 'v1' && Registrar::resolveProfile(null, 'b') === 'v2');

// Patient sign-in scopes: only what the server publishes, and nothing without the essentials.
$offeredToday = ['openid', 'fhirUser', 'launch', 'launch/patient', 'patient/Patient.rs', 'patient/Questionnaire.rs',
    'patient/QuestionnaireResponse.rs', 'patient/QuestionnaireResponse.cu', 'patient/Observation.rs', 'patient/Condition.rs'];
$patientScope = ScopeProfiles::patientScopesFor($offeredToday);
check('patient scopes keep published QR write', is_string($patientScope) && str_contains($patientScope, 'patient/QuestionnaireResponse.cu'));
check('patient scopes drop unpublished Observation write', is_string($patientScope) && !str_contains($patientScope, 'patient/Observation.cu'));
check('patient scopes drop unpublished AllergyIntolerance read', is_string($patientScope) && !str_contains($patientScope, 'patient/AllergyIntolerance.rs'));
check('patient scopes keep openid and fhirUser', is_string($patientScope) && str_starts_with($patientScope, 'openid fhirUser launch/patient'));
check('patient scopes pick up Observation write once published', str_contains((string)ScopeProfiles::patientScopesFor([...$offeredToday, 'patient/Observation.cu']), 'patient/Observation.cu'));
check('no patient sign-in without launch/patient', ScopeProfiles::patientScopesFor(['openid', 'patient/Patient.rs']) === null);
check('no patient sign-in when scopes are unpublished', ScopeProfiles::patientScopesFor(null) === null);
check('legacy client has no patient scopes', ScopeProfiles::forClient(['client_id' => 'x'], null)['patient'] === '');
check('union takes three scope strings', ScopeProfiles::union('a b', 'b c', 'c d') === 'a b c d');
check('patient note without writes says how to turn them on', str_contains(Registrar::patientNote('openid launch/patient patient/Patient.rs'), 'Patient App FHIR Writes'));
check('patient note with QR write', str_contains(Registrar::patientNote('launch/patient patient/Patient.rs patient/QuestionnaireResponse.cu'), 'held for staff review'));

// Held writes: OpenEMR's held-for-review tag.
check('held tag recognized', LaunchSession::isHeld(['meta' => ['tag' => [['system' => LaunchSession::HELD_TAG_SYSTEM, 'code' => 'held-for-review']]]]));
check('other tags are not held', !LaunchSession::isHeld(['meta' => ['tag' => [['system' => 'http://x', 'code' => 'held-for-review']]]]));
check('no meta is not held', !LaunchSession::isHeld(['resourceType' => 'QuestionnaireResponse']));

// Home vital signs: the bundled definition and the answers -> Observations mapping.
$homeVitals = HomeVitals::bundled();
check('bundled Home vital signs has its url', ($homeVitals['url'] ?? null) === HomeVitals::URL && ($homeVitals['title'] ?? null) === HomeVitals::TITLE);
check('Home vitals is known by its identifier when the server replaced the url', HomeVitals::identifies([
    'title' => HomeVitals::TITLE, 'url' => 'https://ehr.example/fhir/Questionnaire/abc',
    'identifier' => [['system' => HomeVitals::IDENTIFIER_SYSTEM, 'value' => HomeVitals::URL]],
]));
check('Home vitals needs its title as well', !HomeVitals::identifies(['title' => 'Other', 'url' => HomeVitals::URL]));
check('a questionnaire titled Home vital signs without the canonical is not it', !HomeVitals::identifies(['title' => HomeVitals::TITLE, 'url' => 'https://ehr.example/fhir/Questionnaire/abc']));
check('bundled Home vital signs carries the canonical identifier', HomeVitals::identifies(array_diff_key($homeVitals, ['url' => 1])));
check('excerpt keeps a short description', View::excerpt('Short.') === 'Short.');
check('excerpt takes the first paragraph', View::excerpt("First line.
Second line.") === 'First line.');
check('excerpt cuts a long paragraph at a word', ($e = View::excerpt(str_repeat('word ', 60))) !== '' && mb_strlen($e) <= 161 && str_ends_with($e, '…') && !str_contains($e, 'wor…'));
$explorerSeeds = dirname(__DIR__, 2) . '/openemr/devtools/oe-module-api-explorer/patient_write_seeds.php';
if (is_file($explorerSeeds)) {
    check('bundled Home vital signs matches the API Explorer seed', (require $explorerSeeds)['homeVitals'] == $homeVitals);
}
$readings = [
    'resourceType' => 'QuestionnaireResponse',
    'item' => [
        ['linkId' => 'taken', 'answer' => [['valueDateTime' => '2026-11-10T08:30:00-05:00']]],
        ['linkId' => 'bp', 'item' => [
            ['linkId' => 'bp-systolic', 'answer' => [['valueInteger' => 128]]],
            ['linkId' => 'bp-diastolic', 'answer' => [['valueInteger' => 82]]],
        ]],
        ['linkId' => 'heart-rate', 'answer' => [['valueInteger' => 70]]],
        ['linkId' => 'weight', 'answer' => [['valueDecimal' => 180.5]]],
        ['linkId' => 'notes', 'answer' => [['valueString' => 'after my walk']]],
    ],
];
$observations = HomeVitals::toObservations($homeVitals, $readings, 'p1');
$byCode = [];
foreach ($observations as $observation) {
    $byCode[$observation['code']['coding'][0]['code']] = $observation;
}
check('vitals: one Observation per reading, BP as one panel', count($observations) === 3 && isset($byCode['85354-9'], $byCode['8867-4'], $byCode['29463-7']));
check('vitals: BP components systolic/diastolic', array_map(static fn($c) => $c['code']['coding'][0]['code'] . '=' . $c['valueQuantity']['value'], $byCode['85354-9']['component'] ?? []) === ['8480-6=128', '8462-4=82']);
check('vitals: UCUM unit from the item', ($byCode['29463-7']['valueQuantity']['code'] ?? null) === '[lb_av]' && ($byCode['29463-7']['valueQuantity']['system'] ?? null) === 'http://unitsofmeasure.org');
check('vitals: effective from "taken"', ($byCode['8867-4']['effectiveDateTime'] ?? null) === '2026-11-10T08:30:00-05:00');
check('vitals: vital-signs category, preliminary, the patient', ($byCode['8867-4']['category'][0]['coding'][0]['code'] ?? null) === 'vital-signs'
    && $byCode['8867-4']['status'] === 'preliminary' && $byCode['8867-4']['subject']['reference'] === 'Patient/p1');
check('vitals: the note rides on one Observation', count(array_filter($observations, static fn($o) => isset($o['note']))) === 1);
check('vitals: nothing entered is refused', throws(fn() => HomeVitals::toObservations($homeVitals, ['item' => [['linkId' => 'taken', 'answer' => [['valueDateTime' => '2026-11-10']]]]], 'p1')) !== null);
check('vitals: a lone systolic still makes the panel', count(HomeVitals::toObservations($homeVitals, ['item' => [['linkId' => 'bp', 'item' => [['linkId' => 'bp-systolic', 'answer' => [['valueInteger' => 120]]]]]]], 'p1')) === 1);
@unlink($tmpConfig);

echo ($failures === 0 ? "OK" : "FAILED") . " ({$count} checks, {$failures} failures)\n";
exit($failures === 0 ? 0 : 1);
