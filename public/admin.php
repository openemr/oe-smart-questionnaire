<?php

/**
 * Client registration console, modeled on the API Explorer's Register Clients page.
 *
 * Lists the OpenEMR sites from config.php and registers this app with each one as a SMART
 * EHR-launch client (launch URI + redirect URI + scopes), records hand-made registrations,
 * and checks existing ones. Password-protected; set it with `php bin/set-admin-password.php`.
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

use OpenEMR\SmartQuestionnaire\AdminAuth;
use OpenEMR\SmartQuestionnaire\Config;
use OpenEMR\SmartQuestionnaire\HttpClient;
use OpenEMR\SmartQuestionnaire\Registrar;
use OpenEMR\SmartQuestionnaire\ScopeProfiles;
use OpenEMR\SmartQuestionnaire\Session;
use OpenEMR\SmartQuestionnaire\Sites;
use OpenEMR\SmartQuestionnaire\View;

Session::start();
header('Cache-Control: no-store');

$flash = static function (string $type, string|array $message): void {
    $_SESSION['sq_flash'][] = ['type' => $type, 'lines' => (array)$message];
};
$back = static function (): never {
    header('Location: admin.php');
    exit;
};

if (!AdminAuth::isConfigured()) {
    View::error(
        503,
        'Administration is locked',
        'No admin password is set. On the server, run: php bin/set-admin-password.php'
    );
}

// --- login / logout ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? null) === 'login') {
    if (!AdminAuth::login((string)($_POST['password'] ?? ''))) {
        $flash('danger', 'Incorrect password.');
    }
    $back();
}
if (!AdminAuth::isLoggedIn()) {
    View::header('Administration');
    foreach ($_SESSION['sq_flash'] ?? [] as $message) {
        echo '<div class="alert alert-' . h($message['type']) . '">' . h(implode(' ', $message['lines'])) . '</div>';
    }
    unset($_SESSION['sq_flash']);
    ?>
    <div class="row justify-content-center">
        <form method="post" class="col-md-5 card card-body mt-4">
            <h1 class="h5 mb-3"><?= h(Config::string('app_name')) ?> — administration</h1>
            <input type="hidden" name="action" value="login">
            <label for="password">Admin password</label>
            <input type="password" class="form-control mb-3" id="password" name="password" autocomplete="current-password" required autofocus>
            <button class="btn btn-primary" type="submit">Sign in</button>
        </form>
    </div>
    <?php
    View::footer();
    exit;
}

// --- actions (POST → redirect) ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AdminAuth::checkCsrf($_POST['csrf'] ?? null)) {
        $flash('danger', 'The form expired. Try again.');
        $back();
    }
    $action = (string)($_POST['action'] ?? '');
    $registrar = new Registrar(new HttpClient(Config::verifyTls()));
    $siteName = is_string($_POST['site'] ?? null) && $_POST['site'] !== '' ? $_POST['site'] : null;
    $site = $siteName !== null ? Sites::get($siteName) : null;
    $issuer = $site['iss'] ?? (is_string($_POST['iss'] ?? null) ? Config::normalizeIssuer($_POST['iss']) : '');
    $label = $siteName ?? $issuer;
    $profileName = is_string($_POST['scope_profile'] ?? null) && $_POST['scope_profile'] !== '' ? $_POST['scope_profile'] : null;

    $validIssuer = static fn(string $url): bool => filter_var($url, FILTER_VALIDATE_URL) !== false
        && preg_match('~^https?://~i', $url) === 1;

    try {
        switch ($action) {
            case 'logout':
                AdminAuth::logout();
                header('Location: admin.php');
                exit;

            case 'register':
                if (!$validIssuer($issuer)) {
                    throw new RuntimeException('Choose a site or enter a valid FHIR base URL.');
                }
                $record = $registrar->register($issuer, $siteName, null, $profileName);
                $lines = ["Registered {$label} as client {$record['client_id']} with the {$record['scope_profile']} scope profile."];
                if (isset($record['replaced_client_id'])) {
                    $lines[] = "This replaces client {$record['replaced_client_id']}. OpenEMR keeps the old one; "
                        . 'disable it under Admin > System > API Clients.';
                }
                $lines[] = 'Next: enable the new client in OpenEMR (Admin > System > API Clients).';
                $flash('success', $lines);
                if (isset($record['note'])) {
                    $flash('warning', ['Scope profile note:', $record['note']]);
                }
                break;

            case 'register_all':
                $done = [];
                $failed = [];
                foreach (Sites::all() as $each) {
                    if (Config::clientFor($each['iss']) !== null) {
                        continue;
                    }
                    try {
                        $record = $registrar->register($each['iss'], $each['name']);
                        $done[] = "{$each['name']}: client {$record['client_id']} ({$record['scope_profile']} scopes)";
                    } catch (Throwable $exception) {
                        $failed[] = "{$each['name']}: " . $exception->getMessage();
                    }
                }
                if ($done === [] && $failed === []) {
                    $flash('info', 'Every configured site is already registered.');
                }
                if ($done !== []) {
                    $flash('success', ['Registered:', ...$done, 'Enable each new client in its OpenEMR (Admin > System > API Clients).']);
                }
                if ($failed !== []) {
                    $flash('danger', ['Failed:', ...$failed]);
                }
                break;

            case 'record':
                if (!$validIssuer($issuer)) {
                    throw new RuntimeException('Choose a site or enter a valid FHIR base URL.');
                }
                $clientId = trim((string)($_POST['client_id'] ?? ''));
                if ($clientId === '') {
                    throw new RuntimeException('Enter the client ID.');
                }
                $record = Registrar::recordExisting(
                    $issuer,
                    $siteName ?? Sites::nameForIssuer($issuer),
                    $clientId,
                    (string)($_POST['client_secret'] ?? ''),
                    $profileName
                );
                $flash('success', "Saved client {$clientId} for {$label} with the {$record['scope_profile']} scope profile. "
                    . 'Its scopes in OpenEMR must match that profile.');
                break;

            case 'verify':
                $result = $registrar->verify($issuer, Config::clientFor($issuer));
                $flash($result['ok'] ? 'success' : 'warning', ["Check of {$label}:", ...$result['lines']]);
                break;

            case 'repair':
                $flash('success', $registrar->repairSecret($issuer));
                break;

            case 'forget':
                $client = Config::clientFor($issuer);
                Config::removeClient($issuer);
                $flash('info', "Forgot the credentials for {$label}"
                    . ($client !== null ? " (client {$client['client_id']}). Disable that client in OpenEMR if it is no longer used." : '.'));
                break;

            default:
                $flash('danger', 'Unknown action.');
        }
    } catch (Throwable $exception) {
        $flash('danger', $exception->getMessage());
    }
    $back();
}

// --- page ---------------------------------------------------------------------------------------
$sites = Sites::all();
$clients = Config::clients();
$siteIssuers = array_column($sites, 'iss');
$extraIssuers = array_values(array_diff(array_keys($clients), $siteIssuers));
$csrf = AdminAuth::csrf();

$profileSelect = static function (string $selected, string $id = ''): string {
    $html = '<select name="scope_profile" class="custom-select custom-select-sm w-auto mr-1" title="Scope profile"'
        . ($id !== '' ? ' id="' . h($id) . '"' : '') . '>';
    foreach (ScopeProfiles::names() as $name) {
        $html .= '<option value="' . h($name) . '"' . ($name === $selected ? ' selected' : '') . '>' . h($name) . '</option>';
    }

    return $html . '</select>';
};
$form = static function (string $action, array $fields, string $label, string $class, ?string $confirm = null, string $extra = '') use ($csrf): string {
    $html = '<form method="post" class="d-inline">'
        . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
        . '<input type="hidden" name="action" value="' . h($action) . '">';
    foreach ($fields as $name => $value) {
        $html .= '<input type="hidden" name="' . h($name) . '" value="' . h($value) . '">';
    }
    // data-confirm is handled by admin.js (inline handlers are blocked by the CSP).
    return $html . $extra . '<button type="submit" class="btn btn-sm ' . h($class) . '"'
        . ($confirm !== null ? ' data-confirm="' . h($confirm) . '"' : '') . '>' . h($label) . '</button></form> ';
};

$row = static function (string $name, string $issuer, ?array $client) use ($form, $profileSelect): string {
    $fields = $name !== '' ? ['site' => $name] : ['iss' => $issuer];
    $site = $name !== '' ? Sites::get($name) : null;
    $current = ScopeProfiles::forClient($client, $site)['profile'];
    $status = $client === null
        ? '<span class="badge badge-secondary">not registered</span>'
        : '<span class="badge badge-success">registered</span> <code class="small">' . h((string)$client['client_id']) . '</code>'
            . '<div class="small text-muted">' . h(substr((string)($client['registered'] ?? ''), 0, 10))
            . (!empty($client['recorded_manually']) ? ' · recorded manually' : '')
            . ' · <span class="badge badge-light border" title="Scope profile">' . h($current) . ' scopes</span></div>';
    $actions = $client === null
        ? $form('register', $fields, 'Register', 'btn-primary', null, $profileSelect($current))
        : $form(
            'register',
            $fields,
            'Re-register',
            'btn-outline-primary',
            'Register a new client for this site? OpenEMR keeps the current one until you disable it.',
            $profileSelect($current)
        )
            . $form('verify', $fields, 'Check', 'btn-outline-secondary')
            . (!empty($client['registration_client_uri']) ? $form('repair', $fields, 'Repair secret', 'btn-outline-warning') : '')
            . '<a class="btn btn-sm btn-outline-success" href="launch.php?' . h(http_build_query(['iss' => $issuer])) . '">Standalone launch</a> '
            . $form('forget', $fields, 'Forget', 'btn-outline-danger', 'Remove the saved credentials for this site?');

    return '<tr><td><strong>' . h($name !== '' ? $name : '—') . '</strong><div class="small text-muted text-break">' . h($issuer) . '</div></td>'
        . '<td>' . $status . '</td><td class="text-nowrap">' . $actions . '</td></tr>';
};

View::header('Administration');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><?= h(Config::string('app_name')) ?> — client registration</h1>
    <?= $form('logout', [], 'Sign out', 'btn-outline-secondary') ?>
</div>
<?php
foreach ($_SESSION['sq_flash'] ?? [] as $message) {
    echo '<div class="alert alert-' . h($message['type']) . '">';
    foreach ($message['lines'] as $index => $line) {
        echo $index === 0 ? '<strong>' . h($line) . '</strong>' : '<div>' . h($line) . '</div>';
    }
    echo '</div>';
}
unset($_SESSION['sq_flash']);
?>

<div class="card mb-3">
    <div class="card-header"><strong>This app's SMART endpoints</strong> <span class="small text-muted">— what each OpenEMR needs to know</span></div>
    <div class="card-body small">
        <dl class="row mb-0">
            <dt class="col-sm-3">Launch URI</dt><dd class="col-sm-9"><code><?= h(Config::launchUri()) ?></code></dd>
            <dt class="col-sm-3">Redirect URI</dt><dd class="col-sm-9"><code><?= h(Config::redirectUri()) ?></code></dd>
            <dt class="col-sm-3">Client type</dt><dd class="col-sm-9">Confidential (client_secret_post) with PKCE S256</dd>
        </dl>
        <table class="table table-sm table-borderless mb-0 mt-2">
            <thead><tr><th>Scope profile</th><th>Scopes registered</th></tr></thead>
            <tbody>
            <?php foreach (ScopeProfiles::all() as $name => $profile) : ?>
                <tr>
                    <td class="text-nowrap"><strong><?= h($name) ?></strong>
                        <?= $name === ScopeProfiles::defaultName() ? '<span class="badge badge-secondary">default</span>' : '' ?>
                        <div class="text-muted"><?= h($profile['label']) ?></div></td>
                    <td><code><?= h(Registrar::scopes($name)) ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Sites</strong>
        <?php if ($sites !== []) : ?>
            <?= $form('register_all', [], 'Register all unregistered sites', 'btn-primary') ?>
        <?php endif; ?>
    </div>
    <?php if ($sites === [] && $extraIssuers === []) : ?>
        <div class="card-body"><div class="alert alert-info mb-0">
            No sites yet. Add them to <code>'sites'</code> in <code>config.php</code>, or register a FHIR URL below.
        </div></div>
    <?php else : ?>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Site / FHIR base (iss)</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php
                foreach ($sites as $site) {
                    echo $row($site['name'], $site['iss'], $clients[$site['iss']] ?? null);
                }
                foreach ($extraIssuers as $issuer) {
                    echo $row((string)($clients[$issuer]['site'] ?? ''), $issuer, $clients[$issuer]);
                }
                ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="row">
    <div class="col-lg-6 mb-3">
        <form method="post" class="card card-body h-100">
            <h2 class="h6">Register with another server</h2>
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="register">
            <label for="reg-iss" class="small">FHIR base URL (iss)</label>
            <input class="form-control mb-2" id="reg-iss" name="iss" type="url" placeholder="https://ehr.example.org/apis/default/fhir" required>
            <label for="reg-profile" class="small">Scope profile</label>
            <div class="mb-2"><?= $profileSelect(ScopeProfiles::defaultName(), 'reg-profile') ?></div>
            <button class="btn btn-outline-primary align-self-start" type="submit">Register</button>
        </form>
    </div>
    <div class="col-lg-6 mb-3">
        <form method="post" class="card card-body h-100">
            <h2 class="h6">Record a client registered in OpenEMR</h2>
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="record">
            <label for="rec-site" class="small">Site</label>
            <select class="form-control mb-2" id="rec-site" name="site">
                <option value="">— use the FHIR URL below —</option>
                <?php foreach ($sites as $site) : ?>
                    <option value="<?= h($site['name']) ?>"><?= h($site['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <input class="form-control mb-2" name="iss" type="url" placeholder="or FHIR base URL">
            <input class="form-control mb-2" name="client_id" placeholder="Client ID" required autocomplete="off">
            <input class="form-control mb-2" name="client_secret" type="password" placeholder="Client secret" autocomplete="off">
            <label for="rec-profile" class="small">Scope profile the client was given in OpenEMR</label>
            <div class="mb-2"><?= $profileSelect(ScopeProfiles::defaultName(), 'rec-profile') ?></div>
            <button class="btn btn-outline-primary align-self-start" type="submit">Save</button>
        </form>
    </div>
</div>
<script src="assets/admin.js"></script>
<?php
View::footer();
