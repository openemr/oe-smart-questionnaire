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

use OpenEMR\SmartQuestionnaire\Refs;
use OpenEMR\SmartQuestionnaire\Registrar;
use OpenEMR\SmartQuestionnaire\ScopeCheck;
use OpenEMR\SmartQuestionnaire\ScopeProfiles;
use OpenEMR\SmartQuestionnaire\Sites;
use OpenEMR\SmartQuestionnaire\ResponseStamper;
use OpenEMR\SmartQuestionnaire\SmartClient;

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
@unlink($tmpConfig);

echo ($failures === 0 ? "OK" : "FAILED") . " ({$count} checks, {$failures} failures)\n";
exit($failures === 0 ? 0 : 1);
