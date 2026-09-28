# OpenEMR SMART Questionnaires — Installation and Use

This guide takes you from an empty server to a clinician completing a FHIR questionnaire from
a patient's chart. Allow about 20 minutes for the first site.

Project home: https://github.com/openemr/oe-smart-questionnaire. For how the app works internally,
see [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## 1. How the pieces fit

```
 OpenEMR (the EHR)                          SMART Questionnaires (this app)
 ─────────────────                          ───────────────────────────────
 Patient → Assessments → FHIR Assessments
   "Launch SMART App" ──── launch + iss ───▶ launch.php
                                             │ discovers OpenEMR's endpoints,
 OAuth2 authorize  ◀──── authorize (PKCE) ───┘ redirects the browser to sign in
   │ clinician approves
   └─────────────── code ─────────────────▶ callback.php  exchanges code → token
                                             index.php     renders the questionnaire
 FHIR API ◀── GET / POST / PUT (bearer) ──── api.php       saves the QuestionnaireResponse
```

- The app is a **confidential SMART client**. Its access token never reaches the browser; the
  browser only talks to the app, and the app talks FHIR to the OpenEMR that launched it.
- Because the browser never calls OpenEMR's API directly, OpenEMR needs no CORS setup, and the
  app can run on a different server from OpenEMR.
- The app has no database and needs no OpenEMR install. Its only state is a small credentials
  file (`var/clients.json`) and PHP sessions.

## 2. Requirements

| Item | Needed |
|------|--------|
| PHP | 8.1 or newer with the `curl` and `session` extensions (both standard in XAMPP) |
| Web server | Apache (XAMPP is fine), nginx + PHP-FPM, or any PHP host |
| HTTPS | Required outside local testing. The app runs inside OpenEMR's window, which only works with a `Secure` cookie. |
| OpenEMR | A version with native FHIR Questionnaire / QuestionnaireResponse support and the FHIR Assessments launcher (#12880 or later) |
| Network | The app server must reach each OpenEMR's `/apis/default/fhir` and `/oauth2/default` URLs |

## 3. Install the app

Get the code with git (recommended, since it makes upgrades a `git pull`):

```bash
git clone https://github.com/openemr/oe-smart-questionnaire.git
```

Or download a release archive from the repository's **Releases** page and extract it. Either way you
get a folder `oe-smart-questionnaire/` containing `public/`, `src/`, `bin/` and `var/`.

- The app needs no `composer install` or `npm install`; those are for development only.
- Only `public/` should be reachable from the web.
- Some archive tools add an extra folder level when extracting (`…\oe-smart-questionnaire\oe-smart-questionnaire\public`).
  Point the web server at the `public` folder that directly contains `launch.php`.

### Windows / XAMPP

1. Put the folder somewhere outside `htdocs`, for example `C:\apps\oe-smart-questionnaire`.
2. Add to `C:\xampp\apache\conf\extra\httpd-xampp.conf` (or `httpd.conf`):

   ```apache
   Alias /smart-questionnaire "C:/apps/oe-smart-questionnaire/public"
   <Directory "C:/apps/oe-smart-questionnaire/public">
       Options -Indexes
       AllowOverride All
       Require all granted
   </Directory>
   ```

3. Restart Apache from the XAMPP control panel.
4. The app URL is `https://localhost/smart-questionnaire`.

If you would rather drop it under `htdocs`, it works, but then the app URL ends in `/public`
(for example `https://localhost/oe-smart-questionnaire/public`), and you rely on the bundled
`.htaccess` files to hide `var/`, `src/` and `bin/`. Keep `AllowOverride All` so they apply.

### Linux / Apache

```apache
Alias /smart-questionnaire /opt/oe-smart-questionnaire/public
<Directory /opt/oe-smart-questionnaire/public>
    Options -Indexes
    AllowOverride All
    Require all granted
</Directory>
```

Make `var/` writable by the web server user, and nothing else:

```bash
sudo chown -R www-data:www-data /opt/oe-smart-questionnaire/var
sudo chmod 700 /opt/oe-smart-questionnaire/var
```

### nginx

```nginx
location /smart-questionnaire/ {
    alias /opt/oe-smart-questionnaire/public/;
    index index.php;
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $request_filename;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

Never point nginx at the project root; `.htaccess` files do nothing there.

## 4. Configure

```bash
cd oe-smart-questionnaire
cp config.sample.php config.php
```

Edit `config.php`:

| Setting | What to put |
|---------|-------------|
| `app_url` | The public URL of `public/`, exactly as browsers reach it, no trailing slash. Example: `https://localhost/smart-questionnaire`. The launch and redirect URIs registered with OpenEMR are built from this, so changing it later means re-registering. |
| `app_name` | Display name. Clients appear in OpenEMR as `<app_name> (<site>)`. |
| `sites` | Your OpenEMR instances, like the API Explorer's site list. Key = short name, value = the OpenEMR base URL (`https://localhost/openemr`) or a full FHIR base ending in `/fhir` (needed for a non-`default` multisite, e.g. `https://ehr.example.org/openemr/apis/clinicb/fhir`). To give a site its own scope profile use the array form: `'clinic-b' => ['url' => 'https://…', 'scope_profile' => 'v2']`. |
| `contact` | Email sent with registrations. |
| `verify_tls` | `true` in production. Set `false` only for local OpenEMR instances with self-signed certificates. |
| `clients_file` | Where credentials are saved. Leave the default (`var/clients.json`). |
| `default_scope_profile` | Which scope set new registrations use: `v1` (default) or `v2`. See **Scope profiles** below. |
| `scope_profiles` | Optional extra or overriding profiles. Leave out unless you need different scopes. |
| `ehr_scopes`, `standalone_scopes` | Only in `config.php` files from earlier versions. If present they override the `v1` profile, so an existing install keeps requesting what it registered. Safe to delete if they match the shipped v1 scopes. |

**The site URL must match OpenEMR's `iss`.** OpenEMR builds `iss` as
`<Site Address Override><OpenEMR web root>/apis/default/fhir` (see step 5). So the site entry is
the Site Address Override plus the web root:

| Site Address Override | OpenEMR web root | Site entry in `config.php` |
|-----------------------|------------------|----------------------------|
| `https://localhost` | `/openemr` | `https://localhost/openemr` |
| `https://localhost` | `/openemr-dev` | `https://localhost/openemr-dev` |
| `https://opensourcedemr.com` | (none) | `https://opensourcedemr.com` |

A different host name for the same server (`127.0.0.1` vs `localhost`) counts as a different
`iss`, and the launch is refused.

### Scope profiles

A scope profile is the set of scopes a client is registered with. Two ship with the app:

| Profile | QuestionnaireResponse scopes | Use it when |
|---------|------------------------------|-------------|
| `v1` (default) | `user/QuestionnaireResponse.read` + `.write` | Any current OpenEMR. |
| `v2` | `user/QuestionnaireResponse.rs` + `.cu` | The OpenEMR has the consent-screen fix that keeps SMART v2 reads and writes as separate scopes. |

Both also request `openid fhirUser launch user/Patient.rs user/Questionnaire.rs` (EHR launch), with
`launch/patient` for standalone launch.

Why v2 needs the fix: OpenEMR's registration only accepts v2 reads and writes as separate scopes, but an
unfixed consent screen merges them back into one `.crus` scope that its own approval check then drops. The
token then has no QuestionnaireResponse access and every save fails with 401. The app still lets you
register `v2`, since you may be preparing for the fix, but it notes the requirement. If a launch loses
the scope, the workspace says so at the top.

Every client remembers the exact scopes it was registered with, and launches request exactly those.
Changing `default_scope_profile` or a site's profile only affects the next registration.

## 5. Prepare each OpenEMR

Signed in as an administrator:

1. **Admin → Config → Connectors**
   - **Site Address Override**: the scheme and host (and port, if any) browsers use for this
     OpenEMR, with no path, for example `https://localhost` or `https://ehr.example.org:9300`.
     OpenEMR adds its web root and `/apis/default/fhir` to form the `iss` the app sees.
   - **Enable OpenEMR Standard FHIR REST API**: on.
   - **Enable OpenEMR SMART ON FHIR Context Test Launches**: optional. Only needed for the
     test-launch buttons on the patient dashboard; the FHIR Assessments launcher does not use it.
   - Save.
2. Make sure at least one questionnaire is active. Active questionnaires are the ones listed
   under **Patient → Assessments → FHIR Assessments**.
3. The signing-in clinician needs the **Patients → Medical/History** (`patients/med`) permission.
   OpenEMR checks it both for the assessments page and for FHIR QuestionnaireResponse writes.

## 6. Set the admin password

The registration console is locked until a password exists. On the app server:

```bash
php bin/set-admin-password.php
```

It asks for a password (12 or more characters) and stores only a hash in `var/admin.json`.
Run it again to change the password.

## 7. Register the app with OpenEMR

Registration is OAuth2 dynamic client registration: the app tells OpenEMR its launch URI,
redirect URI, and scopes, and gets back a client ID and secret. Do it from the browser or the
command line. Both write the same `var/clients.json`.

### From the browser (like the API Explorer's Register Clients)

1. Open `https://<app_url>/admin.php` and sign in.
2. The top card shows this app's **Launch URI** and **Redirect URI**. These are what OpenEMR
   stores for the client.
3. The **Sites** table lists every site from `config.php`:
   - **Register** registers that site with the scope profile chosen in the drop-down next to the
     button (it starts on the site's profile, else `default_scope_profile`). Each row shows the
     profile its client was registered with.
   - **Register all unregistered sites** does every site that has no client yet.
   - **Check** confirms OpenEMR is reachable, that it offers every configured scope, that it
     still knows the client, and that the saved client secret and redirect URI match OpenEMR's.
   - **Repair secret** re-copies the client secret from OpenEMR when Check reports a mismatch.
   - **Re-register** makes a fresh client, with the profile chosen in its drop-down. This is how a site
     moves between `v1` and `v2`. OpenEMR cannot delete clients through its API, so the old one stays
     in its list; the page names it so you can disable it.
   - **Forget** removes the saved credentials from the app.
   - **Standalone launch** starts the app against that site without a chart context (section 9).
4. **Register with another server** takes any FHIR base URL that is not in `config.php`.
5. **Record a client registered in OpenEMR** saves a client ID and secret you created by hand
   in OpenEMR instead. Pick the scope profile matching the scopes you gave that client in OpenEMR,
   or launches will request scopes it doesn't have (`invalid_scope`).

### From the command line

```bash
php bin/register.php --list                 # sites and their status, plus the launch/redirect URIs
php bin/register.php --all                  # register every unregistered site
php bin/register.php --site=localhost       # register (or re-register) one site
php bin/register.php --site=localhost --scopes=v2   # ...with a specific scope profile
php bin/register.php --site=localhost --check
php bin/register.php --site=localhost --repair     # re-sync a mismatched client secret
php bin/register.php --iss=https://ehr.example.org/apis/default/fhir
php bin/register.php --site=localhost --client-id=<id> --client-secret=<secret>
```

### Enable the client in OpenEMR (required)

OpenEMR creates every new client **disabled**.

1. In OpenEMR go to **Admin → System → API Clients**.
2. Open the client named `OpenEMR SMART Questionnaires (<site>)`.
3. Check the **Launch URI** is `<app_url>/launch.php` and the **Redirect URI** is
   `<app_url>/callback.php`.
4. Enable the client and save.

Until it is enabled, the app does not appear in OpenEMR's launch menus and launches fail with
`invalid_client`.

## 8. Launch from a patient's chart

1. Open a patient, then **Assessments → FHIR Assessments**.
2. Each questionnaire card has a **Launch SMART App** menu listing enabled SMART clients. Pick
   **OpenEMR SMART Questionnaires (<site>)**.
3. The first time, OpenEMR shows its authorization screen with the requested permissions.
   Approve it.
4. The app opens on that questionnaire for that patient.

What opens depends on what OpenEMR sends in `appContext`:

| OpenEMR action | When | The app opens |
|----------------|------|---------------|
| `start` | No response yet | A new, blank response |
| `continue` | A draft (`in-progress`) exists | That draft, editable |
| `review` | The latest response is finished | That response read-only, with **Amend** available |

## 9. Standalone launch (without a chart)

Open `https://<app_url>/launch.php` and pick a server, or use **Standalone launch** on the admin
page. OpenEMR signs you in and shows its patient picker. The app then lists the available
questionnaires and you choose one. **Choose another questionnaire** returns to that list.

## 10. Using the app

| Control | Does |
|---------|------|
| **Save draft** | Saves as `in-progress`. The first save creates the QuestionnaireResponse; later saves update the same one. |
| **Complete** | Checks required answers, then saves as `completed`. The response turns read-only. |
| **Amend** | Unlocks a completed response. **Save amendment** stores it as `amended`. |
| **Edit** | Unlocks a draft opened read-only (for example from a review launch). |
| **Start new** | Blank response for the same patient and questionnaire. |
| **Responses for this patient** | This patient's responses to this questionnaire, newest first. **Continue** reopens a draft, **View** opens a finished one. |

The badge next to the title shows the response status. **Unsaved changes** appears after any
edit, and the browser warns before you leave with unsaved changes.

After a **Complete** or amendment, the app sends a `smart-questionnaire.saved` message to the
OpenEMR window that opened it (addressed to OpenEMR's origin only, identifiers but no answers),
so an EHR page that listens for it can refresh.

Completed responses are visible in OpenEMR under **FHIR Assessments → Assessment History**.

## 11. More than one OpenEMR

Add each to `sites` in `config.php`, register it, and enable the client in that OpenEMR. The app
keeps one client per `iss` and refuses launches from any server it is not registered with.
One app install can serve all of them at once, and a clinician can have several launches open
in different tabs.

## 12. Changing things later

| Change | Then |
|--------|------|
| `app_url` (moved the app) | Re-register every site and enable the new clients; disable the old ones. |
| Scopes | Re-register every site. |
| OpenEMR gains the consent-screen fix | Optionally move that site to `v2`: on the admin page choose `v2` next to **Re-register** (or `php bin/register.php --site=<name> --scopes=v2`), enable the new client, disable the old one, relaunch. **Check** then shows `v2` for the site. To make `v2` the default for new registrations set `'default_scope_profile' => 'v2'`. |
| New site | Add it to `sites`, register, enable. |
| Updated OpenEMR questionnaire runtime | Normally arrives with an app release. To take one early, copy `interface/forms/questionnaire_assessments/native/openemr_questionnaire.js` and `.css` from OpenEMR into `public/assets/runtime/` (see [CONTRIBUTING.md](CONTRIBUTING.md#vendored-runtime)). |
| New app version | `git pull` (or extract the new release over the old folder), then read [CHANGELOG.md](CHANGELOG.md) for anything to do. `config.php` and `var/` are never touched by an upgrade. Re-register only if the changelog says scopes or URIs changed. |
| Client secret leaked | Disable the client in OpenEMR, then **Re-register** the site. |

## 13. Troubleshooting

| Symptom | Cause and fix |
|---------|---------------|
| "This app is not registered with https://…/fhir" | The `iss` OpenEMR sent does not match any registered site. The message shows the exact `iss`. Make the site URL in `config.php` match it (section 4), or register that URL on the admin page. |
| Launch menu doesn't list the app | The client is disabled, or was registered without `launch`. Enable it under **API Clients**; re-register if its scopes are wrong. |
| "Token request failed (HTTP 401): Client authentication failed" after approving the consent screen | OpenEMR rejected the app's client secret. Run **Check** for the site: it reads the registration back and reports whether the saved secret matches. **Repair secret** fixes a mismatch. If Check says OpenEMR can't decrypt the secret or no longer knows the client, register again and enable the new client. OpenEMR logs a mismatch as "Confidential client sent invalid client secret"; a decrypt failure is not logged. |
| `invalid_client` from OpenEMR | Client disabled, or `var/clients.json` holds an old client. **Check** on the admin page, then enable or re-register. |
| Workspace says "…This client uses the v2 scope profile, which needs the OpenEMR consent-screen fix" | The site was registered with `v2` but its OpenEMR doesn't have the fix, so consent dropped the QuestionnaireResponse scopes. Re-register the site with `v1`, or install the OpenEMR fix. |
| Save fails with HTTP 401 "Unauthorized", or the workspace says "The server did not grant: user/QuestionnaireResponse…" | The token has no QuestionnaireResponse write access. Either a permission was unchecked on OpenEMR's consent screen (relaunch and leave it checked), or the app was registered with v2 read+write scopes such as `.rs` + `.cu`, which OpenEMR's consent screen merges and then drops. Use the shipped v1 `.read`/`.write` scopes and re-register. The OpenEMR fix for the consent screen is in `openemr/templates/oauth2/scope-authorize.html.twig`. |
| Registration fails: "the server does not accept these scopes" | The message names each rejected scope and what the server offers for that resource. The usual cause is a combined read+write scope such as `user/QuestionnaireResponse.crus`; split it into `user/QuestionnaireResponse.rs user/QuestionnaireResponse.cu`. **Check** on the admin page (or `php bin/register.php --site=<name> --check`) runs the same test without registering. |
| `invalid_scope` at launch, or missing permissions | Scopes were edited after registering. Re-register. |
| "Unknown or already-used authorization state" | The session cookie was lost between launch and callback. Use HTTPS for `app_url` (the cookie must be `SameSite=None; Secure` inside OpenEMR's window), and don't use the browser's back button during sign-in. |
| App area stays blank inside OpenEMR | The browser blocked framing. The app only allows framing by the origins of registered `iss` URLs; if you open OpenEMR under a different host name than its Site Address Override, framing is refused. Use the same host name. |
| SMART discovery fails / SSL errors | The app server cannot reach OpenEMR or doesn't trust its certificate. Test with **Check**. For a local self-signed OpenEMR set `verify_tls` to `false`. |
| "The access token for this launch has expired" | Tokens last about an hour. Launch again from the chart. |
| Save fails with "validation failed" | OpenEMR rejected the response; the message carries OpenEMR's reason. A frequent one is a questionnaire updated since the response was started. |
| "Questionnaire/… could not be read" | The questionnaire is inactive or the token lacks `Questionnaire` read. Check the questionnaire is active; re-register if scopes changed. |
| OpenEMR log: `checkUserHasAccessToPatient rejected — user id missing or not a valid UUID` | Logged by OpenEMR's launch-patient binding check. With this app's `user/` scopes, its reads and saves work regardless. |

The app's own errors go to the web server's PHP error log.

## 14. Security notes

- Only `public/` is web-facing. `var/clients.json` holds client secrets and `var/admin.json`
  the admin password hash; both are written `0600`.
- Launches are accepted only from registered `iss` URLs, so a forged launch link cannot make the
  app send codes or tokens elsewhere.
- OAuth uses PKCE S256 plus a one-time `state`; tokens stay in the server-side session, per launch.
- On save the app sets the patient and questionnaire from the launch. The browser supplies only
  answers and the status. Existing responses can be updated only if this launch listed or created
  them, after re-reading them from OpenEMR and confirming the patient and questionnaire.
- Every POST (saves and admin actions) needs a CSRF token. The admin console signs out after
  30 minutes idle.
- The Content-Security-Policy allows no inline scripts, and only registered EHR origins may frame
  the app.

## 15. Verifying an install

From the project folder:

```bash
php tests/unit.php     # core logic
php tests/e2e.php      # full launch, save, admin and registration flow against a bundled mock OpenEMR
```

Both run with plain PHP, on Windows too, and neither touches your real OpenEMR. `tests/e2e.php` also
runs the questionnaire UI in jsdom when Node.js is available and `npm ci` has been run. Developers can
run everything CI runs with `composer install && npm ci && composer check`; see
[CONTRIBUTING.md](CONTRIBUTING.md).
