# OpenEMR SMART Questionnaires

[![CI](https://github.com/openemr/oe-smart-questionnaire/actions/workflows/ci.yml/badge.svg)](https://github.com/openemr/oe-smart-questionnaire/actions/workflows/ci.yml)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](LICENSE)
![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4.svg)
![SMART App Launch 2.x](https://img.shields.io/badge/SMART-App%20Launch%202.x-2a6ebb.svg)
![FHIR R4](https://img.shields.io/badge/FHIR-R4-e8612c.svg)

A standalone **SMART on FHIR** app for completing FHIR Questionnaires against OpenEMR. A clinician
launches it from a patient's chart and it opens the questionnaire in context. Every read and write
goes through OpenEMR's FHIR API as a `QuestionnaireResponse` for that patient.

It needs no OpenEMR install, database, or login session of its own, so it can run on the same
server as OpenEMR or anywhere else. It also works with any SMART App Launch 2.x / FHIR R4 server
that supports Questionnaire and QuestionnaireResponse.

Part of the questionnaire-driven clinical workflows effort in OpenEMR
([openemr/openemr#13266](https://github.com/openemr/openemr/issues/13266)). It renders with OpenEMR's
native FHIR Questionnaire runtime from [openemr/openemr#12880](https://github.com/openemr/openemr/pull/12880).

## Features

- **Launch from the chart.** In OpenEMR go to *Patient → Assessments → FHIR Assessments → Launch SMART
  App*. The app follows OpenEMR's launch context:
  - `start` opens a new response.
  - `continue` resumes the draft.
  - `review` opens a finished response read-only.
- **Standalone launch.** Pick a patient in OpenEMR's picker, then a questionnaire.
- **Draft, complete, amend.** The first save creates the QuestionnaireResponse and later saves update
  it, so one assessment never becomes duplicates. Required items are validated before completion,
  and finished responses reopen read-only with *Amend*.
- **Patient history.** The patient's earlier responses to the questionnaire, ready to continue or view.
- **Client registration console.** A password-protected admin page and a CLI that register the app
  with each OpenEMR site through OAuth2 dynamic registration, modeled on the API Explorer. The
  console can check a registration against the server and repair a mismatched client secret.
- **Scope profiles.**
  - `v1` (default) works with current OpenEMR.
  - `v2` is ready for OpenEMR versions whose consent screen keeps SMART v2 read and write scopes
    separate.
  - Each client remembers the scopes it was registered with.
- **Tells you what's wrong.** Registration names any scope the server would reject, and the workspace
  warns when a launch was granted less than it asked for. Errors point at the fix.
- **EHR notification.** After a completed save the app posts a `smart-questionnaire.saved` message to
  the launching OpenEMR window, addressed to its origin, with identifiers only and never answers.

## Security at a glance

- It is a confidential client. The access token stays server-side, per launch, and the browser never
  sees it.
- OAuth uses PKCE S256 with a one-time `state`. The app only launches against servers it is
  registered with.
- The app, not the browser, decides the patient and questionnaire of every write. Updates are limited
  to responses this launch loaded or created, and each one is re-read and verified before the PUT.
- A strict Content-Security-Policy is applied, with no inline scripts. Only the registered EHR
  origins may frame the app. Every POST requires a CSRF token.

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the full model and
[SECURITY.md](SECURITY.md) to report a vulnerability.

## Quick start

Requirements: PHP 8.1+ with `curl`, a web server, and HTTPS. There are no runtime dependencies, so
Composer and npm are only needed for development.

```bash
git clone https://github.com/openemr/oe-smart-questionnaire.git
cd oe-smart-questionnaire
cp config.sample.php config.php        # set app_url and your OpenEMR sites
php bin/set-admin-password.php
```

1. Serve **only** `public/` over HTTPS, for example with an Apache `Alias /smart-questionnaire …/public`.
2. Open `<app_url>/admin.php` and register each site, or run `php bin/register.php --all`.
3. In OpenEMR, enable the new client under **Admin → System → API Clients**.
4. Launch from **Patient → Assessments → FHIR Assessments → Launch SMART App**.

**[INSTALLATION.md](INSTALLATION.md)** is the complete guide. It covers XAMPP, Apache and nginx, every
setting, OpenEMR preparation, scope profiles, and troubleshooting.

## Documentation

| Document | For |
|----------|-----|
| [INSTALLATION.md](INSTALLATION.md) | Installing, configuring, registering, using, troubleshooting |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | How it works: launch flow, FHIR calls, state, security model, scope profiles |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Development setup, standards, tests, pull requests |
| [CHANGELOG.md](CHANGELOG.md) | Release history |
| [SECURITY.md](SECURITY.md) | Reporting vulnerabilities |

## Project layout

```
public/                 web root — the only directory the web server may expose
  launch.php            SMART launch URI (EHR and standalone)
  callback.php          OAuth redirect URI (state, PKCE, code exchange)
  index.php             questionnaire workspace
  api.php               save endpoint used by the browser
  admin.php             client registration console
  assets/               app.js, admin.js, app.css; runtime/ (OpenEMR, vendored); vendor/ (Bootstrap)
src/                    application classes, namespace OpenEMR\SmartQuestionnaire
bin/                    register.php, set-admin-password.php (command line)
tests/                  unit, end-to-end against a mock OpenEMR, browser (jsdom), lint
var/                    clients.json and admin.json — credentials, never web-accessible
config.sample.php       copy to config.php
```

## OpenEMR scope notes

OpenEMR only grants `user/` scopes to confidential clients, which is why the app registers as one.

Current OpenEMR also has a consent-screen limitation. Registration only accepts SMART v2 reads and
writes as separate scopes (`.rs`, `.cud`). The consent screen then merges them back into one scope
that its approval check drops, so the token loses that resource. The default **v1** profile avoids
this by requesting `user/QuestionnaireResponse.read` + `.write`. The **v2** profile
(`.rs` + `.cu`) is ready for when OpenEMR's consent screen is fixed. Details are in
[INSTALLATION.md → Scope profiles](INSTALLATION.md#scope-profiles).

## Development

```bash
composer install && npm ci
composer check          # lint, PSR-12, PHPStan level 8, unit + end-to-end (+ browser) tests
```

CI runs the same checks on PHP 8.1–8.5 and on Windows. See [CONTRIBUTING.md](CONTRIBUTING.md).

## Support

- Questions and discussion: [OpenEMR community forum](https://community.open-emr.org/)
- Bugs and feature requests: [issues](https://github.com/openemr/oe-smart-questionnaire/issues)
- Problems in OpenEMR itself (FHIR API, OAuth2, consent screen): [openemr/openemr issues](https://github.com/openemr/openemr/issues)

## License

GNU General Public License v3.0, the same as OpenEMR. See [LICENSE](LICENSE).
Third-party components and their licenses are listed in [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).

Copyright (c) 2026 Jerry Padgett.
