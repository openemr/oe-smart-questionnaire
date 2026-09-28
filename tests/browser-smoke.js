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
const dom = new JSDOM(fs.readFileSync(html, 'utf8').replace(/<script src="[^"]*"><\/script>/g, ''), {
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

window.eval(fs.readFileSync(path.join(publicDir, 'assets/runtime/openemr_questionnaire.js'), 'utf8'));
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
    ).replace(/<script src="[^"]*"><\/script>/g, '');
    const review = new JSDOM(reviewHtml, { runScripts: 'outside-only', url: 'http://127.0.0.1/index.php' });
    review.window.structuredClone = window.structuredClone;
    review.window.eval(fs.readFileSync(path.join(publicDir, 'assets/runtime/openemr_questionnaire.js'), 'utf8'));
    review.window.eval(fs.readFileSync(path.join(publicDir, 'assets/app.js'), 'utf8'));
    const r$ = (id) => review.window.document.getElementById(id);
    check('review opens read-only', r$('sq-fieldset').disabled === true && r$('sq-complete').disabled === true);
    check('review shows Amend', r$('sq-amend').hidden === false && r$('sq-amend').textContent === 'Amend');
    r$('sq-amend').click();
    check('Amend enables editing as amendment', r$('sq-fieldset').disabled === false && r$('sq-complete').textContent === 'Save amendment');

    console.log((failures === 0 ? 'OK' : 'FAILED') + ` (${count} checks, ${failures} failures)`);
    process.exit(failures === 0 ? 0 : 1);
})();
