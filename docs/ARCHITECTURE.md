# Architecture

How OpenEMR SMART Questionnaires works, for developers and reviewers. For installation and day-to-day
use see [INSTALLATION.md](../INSTALLATION.md).

## Overview

The app is a small server-rendered PHP application and a **confidential SMART on FHIR client**.
It has no database; its state is:

- **`var/clients.json`**: one registered client per FHIR server (`iss`). Holds the client ID and
  secret, the registration management URI and token, the scope profile, and the exact scopes it was
  registered with.
- **`var/admin.json`**: the admin password hash.
- **PHP session**: per-launch state (token, context, CSRF token, what may be written). Up to 12
  launches per browser session, each kept for at most 12 hours.

```
Browser ──────────────── this app (PHP) ──────────────── OpenEMR
  │  launch.php?iss&launch   │                                │
  │ ───────────────────────▶ │  GET {iss}/.well-known/smart-configuration
  │                          │ ─────────────────────────────▶ │
  │  302 → authorize (PKCE)  │                                │
  │ ◀─────────────────────── │                                │
  │  sign-in + consent  ───────────────────────────────────▶  │
  │  302 → callback.php?code&state                            │
  │ ───────────────────────▶ │  POST token (secret + PKCE)    │
  │                          │ ─────────────────────────────▶ │
  │  302 → index.php?lid     │  GET Patient, Questionnaire,   │
  │ ◀─────────────────────── │  QuestionnaireResponse search  │
  │  workspace (runtime)     │ ─────────────────────────────▶ │
  │  POST api.php?lid (JSON) │  POST / PUT QuestionnaireResponse
  │ ───────────────────────▶ │ ─────────────────────────────▶ │
```

The browser never receives the access token and never calls OpenEMR's API, so OpenEMR needs no CORS
configuration for this app.

## Request flow

### 1. Launch: `public/launch.php`

- **EHR launch** (`?iss=…&launch=…`) or **standalone** (`?iss=…`). With no parameters, the page lists
  registered servers.
- `iss` must match a registered client (`Config::clientFor`). Unknown issuers are refused, so a forged
  launch link can't make the app send a code or token to another server.
- SMART discovery (`SmartClient::discover`), then a new launch record (`LaunchStore::create`) with a
  PKCE verifier. A random `state` is bound to the launch id.
- The requested scopes are the ones stored with the client (`ScopeProfiles::forClient`): EHR scopes
  include `launch`, standalone scopes include `launch/patient`.
- Redirect to the authorize endpoint with `aud = iss`, `code_challenge_method = S256`, and `launch`
  when present.

### 2. Callback: `public/callback.php`

- `state` is consumed once (`LaunchStore::consumeState`). Replays and unknown states are refused.
- The code is exchanged with the client secret (`client_secret_post`, or `client_secret_basic` if so
  configured) and the PKCE verifier.
- The token response (token, `patient`, `fhirContext`, `appContext`, `intent`, granted `scope`) is
  stored on the launch. The session id is regenerated, then the browser goes to `index.php?lid=…`.

### 3. Workspace: `public/index.php`

`LaunchSession::resolve` checks the launch exists, has a token that hasn't expired, and has a patient.
Then the page:

1. Compares granted and requested scopes (`ScopeCheck::notGranted`) and warns if anything is missing.
2. Reads `Patient/{id}` for the banner, shown when `need_patient_banner` isn't `false`.
3. Takes the Questionnaire from `fhirContext` (`Refs::launchContext` accepts `reference`, `canonical`,
   versioned and absolute forms). With no questionnaire in context, it shows a picker
   (`GET Questionnaire?_count=100`).
4. Lists the patient's responses to that questionnaire (`GET QuestionnaireResponse?patient=…`, falling
   back to `subject=Patient/…`), filtered by questionnaire and patient, newest first.
5. Picks what to open from `appContext.action`:
   - a response in `fhirContext` opens that response (read-only for `review`);
   - `continue` opens the newest `in-progress` response;
   - otherwise it opens a new response.
6. Records on the launch which questionnaire and which response ids this launch may write.
7. Renders the page. Data for the browser goes in a `<script type="application/json">` block, and
   `assets/app.js` mounts the vendored OpenEMR runtime (`OpenEMRQuestionnaire.mount`).

### 4. Save: `public/api.php`

- The request is a POST from the workspace with a JSON QuestionnaireResponse and the launch's CSRF
  token in `X-CSRF-Token`.
- For an update, the stored response is **re-read from the server**. `ResponseStamper::stamp` then
  builds the resource to write:
  - `subject` is always the launch patient;
  - `questionnaire` is the launch questionnaire (an existing, possibly versioned reference is kept on
    update);
  - `status` is limited to `in-progress`, `completed` or `amended`;
  - `encounter`, `author`, `source`, `identifier`, `basedOn` and `partOf` on an update come from the
    stored copy, never the browser;
  - updates are refused unless the id belongs to this launch, and the stored response belongs to the
    same patient and questionnaire.
- `FhirClient::save` sends `POST` (create) or `PUT` (update). OpenEMR answers a create with an id stub,
  which is handled.
- The saved resource is read back and returned, so the browser continues from exactly what the server
  stored. Its id joins the launch's writable set, so later saves are `PUT`s.

### 5. Telling the EHR

After a `completed` or `amended` save, `app.js` posts
`{type: 'smart-questionnaire.saved', resource: 'QuestionnaireResponse/{id}', status}` to
`window.opener` or the framing parent, **targeted at the EHR's origin** (derived from `iss`). An
OpenEMR page can listen for it to refresh its assessment list. Answers are never included.

## Components

| Class (`src/`) | Responsibility |
|----------------|----------------|
| `Config` | `config.php` (overridable with `SQ_CONFIG`), `clients.json`, URIs, frame-ancestor origins |
| `Sites` | Named OpenEMR sites; `iss` derivation (`<base>/apis/default/fhir`) |
| `ScopeProfiles` | `v1` / `v2` / custom scope sets; scopes stored per client |
| `ScopeCheck` | Scope checks: containment against `scopes_supported`, OpenEMR consent-merge detection, granted vs. requested |
| `Registrar` | Dynamic registration (RFC 7591), registration read-back (RFC 7592), Check, secret repair |
| `SmartClient` | Discovery, PKCE, authorize URL, code exchange |
| `LaunchStore` | Per-launch session records and one-time `state` binding |
| `LaunchSession` | Resolves `?lid=` to an authorized launch with a `FhirClient` |
| `FhirClient` / `FhirException` | Bearer-token FHIR reads, searches, create/update; OperationOutcome diagnostics |
| `HttpClient` | cURL wrapper (TLS verification configurable) |
| `Refs` | FHIR reference, `fhirContext` and `appContext` parsing |
| `ResponseStamper` | Server-side construction of the QuestionnaireResponse to write |
| `Session` | Session cookie settings and security headers |
| `AdminAuth` | Admin password, login, idle timeout, CSRF |
| `View` | Page chrome, error pages, JSON responses |

## Security model

| Threat | Mitigation |
|--------|------------|
| Token theft from the browser | Confidential client, with the token held only in the server session |
| Authorization code interception | PKCE S256 and a one-time `state` |
| Forged launch naming an attacker's server | Only registered `iss` values are accepted |
| Browser writing to another patient or questionnaire | `ResponseStamper` sets both from the launch; updates are checked against the re-read stored copy |
| Browser tampering with server-owned fields | Carried from the stored copy on update, and dropped on create |
| Cross-site request forgery | CSRF token on every POST (per launch for saves, per admin session for the console) |
| Clickjacking | CSP `frame-ancestors` limited to the app and registered EHR origins |
| Script injection | CSP `script-src 'self'` with no inline scripts; output escaped with `h()`; data passed as JSON with `JSON_HEX_*` |
| Session fixation | Session id regenerated after token exchange and after admin login |
| Credential exposure | `var/` and project root denied by `.htaccess`; web root is `public/`; files written `0600` |
| Admin console abuse | Password hash, login delay on failure, 30-minute idle timeout, CSRF |

Inside OpenEMR's window the app is a cross-site frame, so its session cookie is
`SameSite=None; Secure` (plain-http development falls back to `Lax`).

## Scope profiles and OpenEMR's consent screen

OpenEMR validates scopes in three places, and they don't fully agree:

1. **Registration** accepts a requested scope only if **one** advertised scope contains it. OpenEMR
   advertises v2 reads and writes separately (`user/X.rs`, `user/X.cud`), so `user/X.crus` is rejected.
2. **The consent screen** shows one card per resource and, for v2, rebuilds the checked actions into
   **one** scope (`user/X.crus`). v1 cards are rebuilt as separate `.read` / `.write` scopes.
3. **Approval** keeps a consented scope only if one **requested** scope contains it. A merged
   `user/X.crus` fits neither `user/X.rs` nor `user/X.cu`, so it is silently dropped.

As a result, v2 read + write on the same resource can't survive current OpenEMR, while v1 can. Hence:

- **`v1` profile (default):** `user/QuestionnaireResponse.read` + `.write`.
- **`v2` profile:** `user/QuestionnaireResponse.rs` + `.cu`, for OpenEMR versions whose consent screen
  keeps them separate. It can be registered today, with a note, and the workspace warns if a launch
  loses the scope.
- `ScopeCheck::consentMergeProblems` refuses custom scope sets that would hit the merge unless they are
  marked as needing the fix. `ScopeCheck::unsupported` mirrors OpenEMR's containment rule; it was
  checked against OpenEMR's own validator on 230 scopes with no disagreements.
- Each client stores the scopes it was registered with, and launches request exactly those. Changing
  profiles or config never breaks an existing registration.

## Client registration

`Registrar::register` performs these steps:

1. Discover the server.
2. Refuse scopes the server doesn't advertise, or that its consent screen would drop.
3. `POST` the registration as a confidential client (`application_type: private`,
   `client_secret_post`) with `redirect_uris = [<app_url>/callback.php]` and
   `initiate_login_uri = <app_url>/launch.php`, which OpenEMR stores as the launch URI.
4. Save the credentials and scopes.

OpenEMR creates clients disabled and offers no delete API, so re-registering leaves the old client for
an administrator to disable.

`Registrar::verify` (**Check**) re-runs discovery and the scope checks. It then reads the registration
back with its registration token and compares the server's client secret and redirect URIs with the
saved ones. `Registrar::repairSecret` copies the server's current secret when they differ.

## Extending

- **More scopes:** add a profile under `scope_profiles` in `config.php`. Mark it
  `needs_consent_fix => true` if it splits v2 reads and writes on one resource.
- **Other resources in the workspace:** read them through `LaunchSession->fhir` in `index.php`, and add
  their scopes to the profile.
- **Renderer changes** belong in OpenEMR's runtime. Re-sync `public/assets/runtime/` afterwards (see
  [CONTRIBUTING.md](../CONTRIBUTING.md#vendored-runtime)).

## Tests

| Suite | Covers |
|-------|--------|
| `tests/unit.php` | PKCE (RFC 7636 vector), reference/context parsing, response stamping, scope checks, profiles |
| `tests/e2e.php` | Real HTTP through launch → authorize → callback → workspace → save against `tests/mock/ehr.php`, admin console, CLI, failure paths |
| `tests/browser-smoke.js` | The real runtime and `app.js` in jsdom: validation, draft → PUT → complete, read-only, amend, review |

`tests/mock/ehr.php` imitates OpenEMR's behavior, including its quirks: split scope advertisement,
invalid-scope hints, the consent merge (and a switch to emulate a fixed consent screen), id-stub create
responses, registration read-back, and 401 on missing write scope.
