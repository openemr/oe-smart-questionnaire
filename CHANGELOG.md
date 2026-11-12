# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

First public release (planned as 1.0.0).

### Added

- **Patient sign-in** (`launch.php?mode=patient`, OpenEMR's *Patient Login*), for OpenEMR's patient
  app writes (openemr/openemr#15138, #15144, #15162):
  - A home page with what the patient can do and what the server accepts: record home vital signs,
    answer questionnaires, what they sent (*waiting for review* / *in your record*), recent vital
    signs, allergies and conditions.
  - What a patient sends is held by OpenEMR for staff review; the app shows the held-for-review tag,
    lets the patient change or **withdraw** (entered-in-error) a held response, and keeps responses in
    the record view-only.
  - **Home vital signs** (`docs/home-vital-signs.questionnaire.json`, LOINC/UCUM per item): sent as a
    held QuestionnaireResponse today; sent as vital-sign Observations (BP as one panel) when the token
    grants `patient/Observation.cu`, with the same form. The app recognizes it by title plus its
    canonical as `url` or `identifier` (`urn:ietf:rfc:3986`): OpenEMR ignores a client `url` on
    create and publishes its own.
  - Registration adds the patient scopes the server publishes (v2 only, e.g.
    `patient/QuestionnaireResponse.cu`), so a site with patient writes off still registers. Check
    reports when the server's offer has changed and the app should be registered again.
  - Long questionnaire descriptions are shortened to their first sentences on the home page.
  - **Public patient client** beside the confidential one (admin *Add public client*, or
    `php bin/register.php --site=<name> --public`): `application_type: public`, no secret, PKCE S256,
    patient scopes only, no launch URI, so OpenEMR enables it by itself. The sign-in page offers
    *Patient sign-in* as **confidential** and **public**; the callback exchanges a public sign-in's code
    without a secret. Patient App FHIR Writes decides what each may send: under *Confidential apps*
    OpenEMR leaves a public client's write scopes out, and the patient sees a read-only home and forms
    with a button to the confidential sign-in instead of a failed save. **Check** confirms the public
    client has no secret on the server; re-registering the confidential client keeps it;
    `--forget-public` / *Forget public* drop it.
  - Patient pages share a top bar: name, server, *confidential app* / *public app*, **My health**
    and **Sign out** (POST with the launch's CSRF token; forgets the launch and its token).
  - Home page: each questionnaire shows *Not answered*, *Waiting for review* or *Answered*; a count of
    what is waiting; vital signs grouped by day; allergies and conditions as chips; dates as
    *Today 9:05 am* / *Yesterday* / *Mar 3, 2026*.
  - Forms open read-only, without send buttons, when the token can't send what they save.
  - Searches for the patient's responses never send `_sort`: OpenEMR adds held writes only to a
    search by `_id`, `patient`, `subject` and `_count`.
- **SMART launches**
  - `launch.php` opened under a host name other than `app_url`'s (e.g. `https://localhost/…` with a
    domain `app_url`) first redirects to `app_url`: OpenEMR returns to `app_url`'s `callback.php`, where
    the first host's session cookie is not sent, and staff and patient sign-in failed with "Unknown or
    already-used authorization state".
  - EHR launch from OpenEMR's FHIR Assessments, honoring `fhirContext` (Questionnaire, QuestionnaireResponse) and `appContext` actions `start`, `continue` and `review`.
  - Standalone launch with OpenEMR's patient picker and a questionnaire picker.
  - The sign-in page lists each server as a card with patient (confidential, public) and staff
    sign-in, and explains the difference between the two patient sign-ins.
- **Questionnaire workspace**
  - Uses OpenEMR's native FHIR Questionnaire runtime (openemr/openemr#12880), vendored unmodified.
  - Runtime re-synced from openemr/openemr `master` at `2ee0a6a` (#14744, merged): broader SDC/R4 item coverage, per-instance repeats, QuestionnaireResponse provenance tags and translatable runtime strings.
  - The runtime script tag carries `data-openemr-version`, read once per launch from the server's CapabilityStatement `software.version`, so saved responses are tagged with the OpenEMR version.
  - Save draft, complete (with required-item validation) and amend.
  - Patient response history.
  - Unsaved-changes guard.
- **Server-side writes**
  - `POST` on first save, `PUT` afterward.
  - The app sets patient and questionnaire from the launch.
  - Updates are limited to responses the launch loaded or created, and are verified against a fresh read.
- **Client registration**
  - Password-protected admin console (`admin.php`) and CLI (`bin/register.php`): register per site or all sites, record a hand-made client, Check a registration, repair a mismatched secret.
  - Site list in `config.php`, like the API Explorer.
- **Scope profiles**
  - `v1` (default, works with current OpenEMR) and `v2` (for OpenEMR with the consent-screen fix).
  - Custom profiles.
  - Each client stores the scopes it was registered with.
- **Scope diagnostics**
  - Registration and Check name scopes the server would reject or that OpenEMR's consent screen would drop.
  - The workspace warns when a launch was granted less than requested.
  - OAuth error `hint`s are surfaced.
- **EHR notification**: after a completed or amended save, a `smart-questionnaire.saved` `postMessage` is sent to the launching OpenEMR window, targeted at its origin.
- **Security**
  - Confidential client with PKCE S256 and one-time `state`.
  - Registered-issuer allowlist.
  - Per-launch session state; CSRF tokens.
  - Strict CSP with `frame-ancestors` limited to registered EHRs.
  - `SameSite=None; Secure` session cookie for use inside OpenEMR.
- **Documentation**: `INSTALLATION.md` (XAMPP, Apache, nginx, OpenEMR setup, troubleshooting), `docs/ARCHITECTURE.md`, contributing and security policies.
- **Tooling**
  - Unit, end-to-end (mock OpenEMR, including its consent-screen behavior) and jsdom browser tests.
  - PSR-12 and PHPStan level 8.
  - GitHub Actions on PHP 8.1–8.5 and Windows.
