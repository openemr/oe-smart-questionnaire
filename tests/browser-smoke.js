/**
 * Browser-side smoke test with jsdom (npm install).
 *
 *   node tests/browser-smoke.js <workspace.html> <public dir>
 *
 * Runs the real runtime and app.js against a workspace page captured by tests/e2e.php.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @link      https://github.com/openemr/oe-smart-questionnaire
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const [html, publicDir] = process.argv.slice(2);
const page = fs.readFileSync(html, 'utf8');
const openemrVersion = (page.match(/<script src="[^"]*openemr_questionnaire\.js"[^>]*data-openemr-version="([^"]*)"/) || [])[1] || '';
const dom = new JSDOM(page.replace(/<script src="[^"]*"[^>]*><\/script>/g, ''), {
    runScripts: 'outside-only',
    url: 'http://127.0.0.1/index.php',
});
const { window } = dom;
window.structuredClone = (value) => JSON.parse(JSON.stringify(value));
window.confirm = () => true;

const requests = [];
let nextId = 'qr-new';
window.fetch = async (url, init) => {
    const body = JSON.parse(init.body);
    requests.push({ url, headers: init.headers, body });
    const resource = { ...body, id: body.id || nextId, authored: '2026-09-26T12:00:00Z' };
    return { ok: true, status: 200, json: async () => ({ success: true, resource }) };
};

// The runtime reads its data-* attributes from document.currentScript while it evaluates.
const runtimeTag = window.document.createElement('script');
runtimeTag.dataset.openemrVersion = openemrVersion;
Object.defineProperty(window.document, 'currentScript', { configurable: true, get: () => runtimeTag });
window.eval(fs.readFileSync(path.join(publicDir, 'assets/runtime/openemr_questionnaire.js'), 'utf8'));
Object.defineProperty(window.document, 'currentScript', { configurable: true, get: () => null });
window.eval(fs.readFileSync(path.join(publicDir, 'assets/app.js'), 'utf8'));

const $ = (id) => window.document.getElementById(id);
const tick = () => new Promise((resolve) => setTimeout(resolve, 0));
let failures = 0;
let count = 0;
const check = (name, ok, detail = '') => {
    count++;
    if (!ok) {
        failures++;
    }
    console.log((ok ? 'ok    ' : 'FAIL  ') + name + (ok || !detail ? '' : ' — ' + detail));
};

(async () => {
    check('form rendered', $('sq-form').querySelectorAll('input, select, textarea').length > 0);
    check('new response is editable', $('sq-fieldset').disabled === false && $('sq-complete').disabled === false);

    $('sq-complete').click();
    await tick();
    check('required item blocks completion', requests.length === 0 && $('sq-alert').className.includes('alert-danger'));

    const runtime = window.OpenEMRQuestionnaire.runtime;
    runtime.setAnswers('housing', [{ valueKey: 'valueBoolean', value: true }]);
    check('change marks form dirty', $('sq-dirty').hidden === false);

    $('sq-draft').click();
    await tick(); await tick();
    const draft = requests[0]?.body;
    check('draft posts in-progress without id', draft?.status === 'in-progress' && draft.id === undefined, JSON.stringify(draft));
    check('csrf header sent', requests[0]?.headers['X-CSRF-Token'] !== undefined && requests[0].headers['X-CSRF-Token'] !== '');
    check('answer included', JSON.stringify(draft?.item || []).includes('valueBoolean'));
    check('version tag stamped', openemrVersion !== '' && (draft?.meta?.tag || []).some((tag) => tag.code === 'openemrVersion: ' + openemrVersion), JSON.stringify(draft?.meta));
    check('dirty cleared after save', $('sq-dirty').hidden === true);

    $('sq-complete').click();
    await tick(); await tick();
    const completed = requests[1]?.body;
    check('complete updates same response', completed?.status === 'completed' && completed.id === 'qr-new', JSON.stringify(completed));
    check('completed response is read-only', $('sq-fieldset').disabled === true && $('sq-amend').hidden === false);

    $('sq-amend').click();
    check('amend unlocks form', $('sq-fieldset').disabled === false);
    $('sq-complete').click();
    await tick(); await tick();
    check('amendment saved with status amended', requests[2]?.body.status === 'amended' && requests[2].body.id === 'qr-new');
    check('history lists the response once', $('sq-history').querySelectorAll('li').length === 1);

    nextId = 'qr-second';
    $('sq-new').click();
    check('start new clears to editable blank', $('sq-fieldset').disabled === false && /New response/.test($('sq-working').textContent));

    // OpenEMR "review" launch of a finished response: opens read-only, Amend unlocks it.
    const source = fs.readFileSync(html, 'utf8');
    const reviewHtml = source.replace(
        /(<script type="application\/json" id="sq-data">)([\s\S]*?)(<\/script>)/,
        (match, open, json, close) => {
            const data = JSON.parse(json);
            data.responses = [{
                resourceType: 'QuestionnaireResponse', id: 'done-1', status: 'completed',
                authored: '2026-09-01T10:00:00Z', questionnaire: 'Questionnaire/q1',
                item: [{ linkId: 'housing', answer: [{ valueBoolean: true }] }],
            }];
            data.initialResponseId = 'done-1';
            data.reviewOnly = true;
            return open + JSON.stringify(data).replace(/</g, '\\u003c') + close;
        }
    ).replace(/<script src="[^"]*"[^>]*><\/script>/g, '');
    const review = new JSDOM(reviewHtml, { runScripts: 'outside-only', url: 'http://127.0.0.1/index.php' });
    review.window.structuredClone = window.structuredClone;
    review.window.eval(fs.readFileSync(path.join(publicDir, 'assets/runtime/openemr_questionnaire.js'), 'utf8'));
    review.window.eval(fs.readFileSync(path.join(publicDir, 'assets/app.js'), 'utf8'));
    const r$ = (id) => review.window.document.getElementById(id);
    check('review opens read-only', r$('sq-fieldset').disabled === true && r$('sq-complete').disabled === true);
    check('review shows Amend', r$('sq-amend').hidden === false && r$('sq-amend').textContent === 'Amend');
    r$('sq-amend').click();
    check('Amend enables editing as amendment', r$('sq-fieldset').disabled === false && r$('sq-complete').textContent === 'Save amendment');

    // Patient sign-in: what the patient sends is held for review; it can be changed or withdrawn.
    const patientPage = (patch) => {
        const pageHtml = source.replace(
            /(<script type="application\/json" id="sq-data">)([\s\S]*?)(<\/script>)/,
            (match, open, json, close) => {
                const data = Object.assign(JSON.parse(json), {
                    patientMode: true, responses: [], initialResponseId: null, reviewOnly: false, homeUrl: 'home.php?lid=x',
                }, patch);
                return open + JSON.stringify(data).replace(/</g, '\\u003c') + close;
            }
        ).replace(/<script src="[^"]*"[^>]*><\/script>/g, '');
        const pageDom = new JSDOM(pageHtml, { runScripts: 'outside-only', url: 'http://127.0.0.1/index.php' });
        const sent = [];
        pageDom.window.structuredClone = window.structuredClone;
        pageDom.window.confirm = () => true;
        pageDom.window.fetch = async (url, init) => {
            const body = JSON.parse(init.body);
            sent.push({ url, body });
            if (url.includes('action=withdraw')) {
                return { ok: true, status: 200, json: async () => ({ success: true, withdrawn: body.id }) };
            }
            if (url.includes('action=vitals')) {
                return { ok: true, status: 200, json: async () => ({ success: true, observations: [{ id: 'o1', held: true }, { id: 'o2', held: true }] }) };
            }
            const resource = {
                ...body, id: body.id || 'held-1', authored: '2026-11-10T12:00:00Z',
                meta: { tag: [{ system: 'http://open-emr.org/fhir/CodeSystem/patient-write', code: 'held-for-review' }] },
            };
            return { ok: true, status: 200, json: async () => ({ success: true, resource, held: true }) };
        };
        pageDom.window.eval(fs.readFileSync(path.join(publicDir, 'assets/runtime/openemr_questionnaire.js'), 'utf8'));
        pageDom.window.eval(fs.readFileSync(path.join(publicDir, 'assets/app.js'), 'utf8'));
        return { window: pageDom.window, sent, $: (id) => pageDom.window.document.getElementById(id) };
    };

    const p = patientPage({});
    check('patient: no Save draft', p.$('sq-draft').hidden === true);
    check('patient: Send to my care team', p.$('sq-complete').textContent === 'Send to my care team' && p.$('sq-fieldset').disabled === false);
    p.window.OpenEMRQuestionnaire.runtime.setAnswers('housing', [{ valueKey: 'valueBoolean', value: true }]);
    p.$('sq-complete').click();
    await tick(); await tick();
    check('patient: sends a completed response', p.sent[0]?.url.includes('action=save') && p.sent[0].body.status === 'completed', JSON.stringify(p.sent[0]));
    check('patient: shows Waiting for review', p.$('sq-status').textContent === 'Waiting for review');
    check('patient: held is view-only with Change and Withdraw', p.$('sq-fieldset').disabled === true
        && p.$('sq-amend').hidden === false && p.$('sq-amend').textContent === 'Change' && p.$('sq-withdraw').hidden === false);
    check('patient: history says waiting for review', /waiting for review/.test(p.$('sq-history').textContent));
    p.$('sq-amend').click();
    check('patient: Change unlocks with Send changes', p.$('sq-fieldset').disabled === false && p.$('sq-complete').textContent === 'Send changes');
    p.$('sq-complete').click();
    await tick(); await tick();
    check('patient: a change PUTs the same held write', p.sent[1]?.body.id === 'held-1' && p.sent[1].body.status === 'completed', JSON.stringify(p.sent[1]?.body));
    p.$('sq-withdraw').click();
    await tick(); await tick();
    check('patient: withdraw sends the id', p.sent[2]?.url.includes('action=withdraw') && p.sent[2].body.id === 'held-1');
    check('patient: after withdrawing, a blank form and no history', p.$('sq-fieldset').disabled === false
        && p.$('sq-withdraw').hidden === true && /No responses yet/.test(p.$('sq-history').textContent));

    const chart = patientPage({
        responses: [{
            resourceType: 'QuestionnaireResponse', id: 'chart-1', status: 'completed',
            authored: '2026-09-01T10:00:00Z', questionnaire: 'Questionnaire/q1',
            item: [{ linkId: 'housing', answer: [{ valueBoolean: true }] }],
        }],
    });
    chart.$('sq-history').querySelector('button').click();
    check('patient: a response in the record is view-only, no Change or Withdraw', chart.$('sq-fieldset').disabled === true
        && chart.$('sq-amend').hidden === true && chart.$('sq-withdraw').hidden === true && chart.$('sq-status').textContent === 'In your record');

    const obs = patientPage({ transport: 'observation' });
    obs.window.OpenEMRQuestionnaire.runtime.setAnswers('housing', [{ valueKey: 'valueBoolean', value: true }]);
    obs.$('sq-complete').click();
    await tick(); await tick();
    check('patient vitals: Observation transport posts to vitals', obs.sent[0]?.url.includes('action=vitals'));
    check('patient vitals: says how many readings went', /Sent 2 readings/.test(obs.$('sq-alert').textContent) && obs.$('sq-fieldset').disabled === false);

    console.log((failures === 0 ? 'OK' : 'FAILED') + ` (${count} checks, ${failures} failures)`);
    process.exit(failures === 0 ? 0 : 1);
})();
