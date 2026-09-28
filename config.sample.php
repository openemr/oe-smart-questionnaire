<?php

/**
 * OpenEMR SMART Questionnaires configuration. Copy to config.php and edit.
 * See INSTALLATION.md for what each setting does.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @link      https://github.com/openemr/oe-smart-questionnaire
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

return [
    // Public URL of this app's public/ directory (the web server's document root for the app).
    // Launch URI = <app_url>/launch.php, Redirect URI = <app_url>/callback.php.
    'app_url' => 'https://localhost/smart-questionnaire',

    // Shown in the app and used for client names ("<app_name> (<site>)") in OpenEMR's API Clients list.
    'app_name' => 'OpenEMR SMART Questionnaires',

    // OpenEMR sites this app can be registered with, like the API Explorer's $apiSites.
    // Value: the OpenEMR base URL (FHIR base becomes <url>/apis/default/fhir), or a full FHIR base
    // URL ending in /fhir (e.g. for a non-default multisite). Use the array form to give a site
    // its own scope profile (see default_scope_profile below).
    'sites' => [
        'localhost' => 'https://localhost/openemr',
        // 'dev-xampp-domain' => 'https://opensourcedemr.com',
        // 'clinic-b' => ['url' => 'https://ehr.example.org/openemr/apis/clinicb/fhir', 'scope_profile' => 'v2'],
    ],

    // Contact address sent with client registrations.
    'contact' => 'admin@example.org',

    // Keep true in production. Set false only for local servers with self-signed certificates.
    'verify_tls' => true,

    // Saved client credentials (and the admin password hash beside it). Keep outside public/.
    'clients_file' => __DIR__ . '/var/clients.json',

    // Scope profile used when registering (can also be chosen per site, per registration on the
    // admin page, or with `php bin/register.php --scopes=v1|v2`):
    //   v1 (default): user/QuestionnaireResponse.read + .write. Works with current OpenEMR.
    //   v2: user/QuestionnaireResponse.rs + .cu. Needs an OpenEMR whose consent screen keeps v2
    //       reads and writes separate; an unfixed consent screen merges them into one scope that
    //       its approval check drops, so saves fail with 401 (the app warns when that happens).
    // Every client remembers the scopes it was registered with and launches request exactly those,
    // so switching the default only affects new registrations.
    'default_scope_profile' => 'v1',

    // Optional: extra or overriding profiles, e.g.
    // 'scope_profiles' => [
    //     'v2-encounter' => [
    //         'label' => 'v2 plus encounter read',
    //         'ehr' => 'openid fhirUser launch user/Patient.rs user/Encounter.rs user/Questionnaire.rs '
    //             . 'user/QuestionnaireResponse.rs user/QuestionnaireResponse.cu',
    //         'needs_consent_fix' => true,   // standalone scopes default to ehr with launch/patient
    //     ],
    // ],
    //
    // Older config.php files set 'ehr_scopes' / 'standalone_scopes'; those still work and
    // override the v1 profile.
];
