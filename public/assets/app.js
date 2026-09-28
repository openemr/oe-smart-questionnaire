/**
 * Browser side of the questionnaire workspace.
 *
 * Renders the launch Questionnaire with the OpenEMR native runtime and hands responses to
 * api.php, which writes them to the launching FHIR server. No token ever reaches the browser.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @link      https://github.com/openemr/oe-smart-questionnaire
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

(function () {
    'use strict';

    const dataElement = document.getElementById('sq-data');
    if (!dataElement || typeof window.OpenEMRQuestionnaire === 'undefined') {
        return;
    }
    const data = JSON.parse(dataElement.textContent);

    const el = (id) => document.getElementById(id);
    const form = el('sq-form');
    const fieldset = el('sq-fieldset');
    const alertBox = el('sq-alert');
    const working = el('sq-working');
    const statusBadge = el('sq-status');
    const dirtyBadge = el('sq-dirty');
    const history = el('sq-history');
    const buttons = {
        new: el('sq-new'),
        amend: el('sq-amend'),
        draft: el('sq-draft'),
        complete: el('sq-complete'),
    };

    const STATUS_CLASSES = {
        'in-progress': 'badge-warning',
        completed: 'badge-success',
        amended: 'badge-info',
        stopped: 'badge-danger',
        'entered-in-error': 'badge-danger',
    };

    let responses = Array.isArray(data.responses) ? data.responses : [];
    let runtime = null;
    let current = null; // the stored response being edited, or null for a new one
    let editable = false;
    let dirty = false;
    let busy = false;

    const showAlert = (message, type) => {
        alertBox.className = 'alert alert-' + type;
        alertBox.textContent = message;
    };
    const clearAlert = () => {
        alertBox.className = 'd-none';
        alertBox.textContent = '';
    };
    const formatDate = (value) => {
        if (!value) {
            return 'date not recorded';
        }
        const parsed = new Date(value);
        return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString();
    };
    const setDirty = (value) => {
        dirty = value;
        dirtyBadge.hidden = !value;
    };

    const refreshControls = () => {
        const status = current?.status ?? null;
        statusBadge.hidden = status === null;
        statusBadge.textContent = status ?? '';
        statusBadge.className = 'badge ml-2 ' + (STATUS_CLASSES[status] ?? 'badge-secondary');

        const finished = status === 'completed' || status === 'amended';
        buttons.amend.hidden = editable || current === null;
        buttons.amend.textContent = finished ? 'Amend' : 'Edit';
        buttons.draft.hidden = finished;
        buttons.draft.disabled = busy || !editable || runtime === null;
        buttons.complete.disabled = busy || !editable || runtime === null;
        buttons.complete.textContent = finished ? 'Save amendment' : 'Complete';
        buttons.new.disabled = busy;
        buttons.amend.disabled = busy;
        fieldset.disabled = !editable;

        working.textContent = current?.id
            ? 'QuestionnaireResponse/' + current.id + ' · last authored ' + formatDate(current.authored)
            : 'New response — it is created on the first save.';
    };

    const renderHistory = () => {
        history.replaceChildren();
        if (responses.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'list-group-item small text-muted';
            empty.textContent = 'No responses yet.';
            history.append(empty);
            return;
        }
        for (const response of responses) {
            const item = document.createElement('li');
            item.className = 'list-group-item d-flex justify-content-between align-items-center'
                + (current?.id === response.id ? ' active-response' : '');
            const label = document.createElement('div');
            const badge = document.createElement('span');
            badge.className = 'badge mr-2 ' + (STATUS_CLASSES[response.status] ?? 'badge-secondary');
            badge.textContent = response.status ?? 'unknown';
            const when = document.createElement('span');
            when.className = 'small';
            when.textContent = formatDate(response.authored);
            label.append(badge, when);

            const open = document.createElement('button');
            open.type = 'button';
            open.className = 'btn btn-sm btn-link';
            open.textContent = response.status === 'in-progress' ? 'Continue' : 'View';
            open.disabled = current?.id === response.id;
            open.addEventListener('click', () => {
                if (confirmDiscard()) {
                    load(response, response.status === 'in-progress');
                }
            });
            item.append(label, open);
            history.append(item);
        }
    };

    const confirmDiscard = () => !dirty || window.confirm('Discard unsaved changes?');

    const load = (response, allowEdit) => {
        clearAlert();
        form.replaceChildren();
        current = response ? structuredClone(response) : null;
        editable = allowEdit;
        runtime = window.OpenEMRQuestionnaire.mount({
            questionnaire: data.questionnaire,
            questionnaireResponse: current,
            container: form,
            options: { questionLayout: 'vertical' },
        });
        runtime.onChange(() => {
            if (editable) {
                setDirty(true);
            }
        });
        setDirty(false);
        refreshControls();
        renderHistory();
    };

    const save = async (status) => {
        if (!runtime || !editable || busy) {
            return;
        }
        clearAlert();
        if (status !== 'in-progress') {
            const validation = runtime.validate();
            if (!validation.valid) {
                const message = validation.issues.map((issue) => issue.message).filter(Boolean).join(' ');
                showAlert(message || 'Please correct the highlighted fields.', 'danger');
                return;
            }
        }

        const body = runtime.getQuestionnaireResponse();
        body.status = status;
        if (current?.id) {
            body.id = current.id;
        } else {
            delete body.id;
        }

        busy = true;
        refreshControls();
        try {
            const response = await fetch('api.php?' + new URLSearchParams({ lid: data.launchId, action: 'save' }), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': data.csrf },
                body: JSON.stringify(body),
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok || result.success !== true) {
                throw new Error(result.message || 'Save failed (HTTP ' + response.status + ').');
            }
            const saved = result.resource;
            responses = [saved, ...responses.filter((item) => item.id !== saved.id)];
            current = saved;
            // Keep editing drafts; a completed or amended response goes back to read-only.
            editable = saved.status === 'in-progress';
            setDirty(false);
            showAlert(
                saved.status === 'in-progress'
                    ? 'Draft saved.'
                    : 'Saved as ' + saved.status + '. The response is now in the patient\'s record.',
                'success'
            );
            renderHistory();
            notifyEhr(saved);
        } catch (error) {
            showAlert(error instanceof Error ? error.message : 'Save failed.', 'danger');
        } finally {
            busy = false;
            refreshControls();
        }
    };

    /**
     * Best-effort notice to the launching EHR window (opener or framing parent) so it can refresh
     * its assessment list. Addressed to the EHR's origin only, and carries identifiers, never answers.
     */
    const notifyEhr = (saved) => {
        const target = window.opener || (window.parent !== window ? window.parent : null);
        if (!target || !data.ehrOrigin || saved.status === 'in-progress') {
            return;
        }
        try {
            target.postMessage({
                type: 'smart-questionnaire.saved',
                resource: 'QuestionnaireResponse/' + saved.id,
                status: saved.status,
            }, data.ehrOrigin);
        } catch (e) {
            // A cross-origin parent that refuses messages is fine; the save already happened.
        }
    };

    buttons.new.addEventListener('click', () => {
        if (confirmDiscard()) {
            load(null, true);
        }
    });
    buttons.amend.addEventListener('click', () => {
        editable = true;
        refreshControls();
        if (current?.status === 'completed' || current?.status === 'amended') {
            showAlert('Amending a finished response. Saving records it with status "amended".', 'info');
        }
    });
    buttons.draft.addEventListener('click', () => save('in-progress'));
    buttons.complete.addEventListener('click', () => {
        const finished = current?.status === 'completed' || current?.status === 'amended';
        save(finished ? 'amended' : 'completed');
    });
    window.addEventListener('beforeunload', (event) => {
        if (dirty) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    const initial = responses.find((response) => response.id === data.initialResponseId) ?? null;
    load(initial, !data.reviewOnly && (initial === null || initial.status === 'in-progress'));
})();
