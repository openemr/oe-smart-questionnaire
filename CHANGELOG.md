# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

First public release (planned as 1.0.0).

### Added

- **SMART launches**
  - EHR launch from OpenEMR's FHIR Assessments, honoring `fhirContext` (Questionnaire, QuestionnaireResponse) and `appContext` actions `start`, `continue` and `review`.
  - Standalone launch with OpenEMR's patient picker and a questionnaire picker.
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
