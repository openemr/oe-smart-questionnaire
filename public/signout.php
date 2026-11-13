<?php

/**
 * Signs a patient out of this app: forgets the launch and its token, then shows the sign-in page.
 *
 * POST with the launch's CSRF token, so another site can't sign a patient out. The patient's
 * session at the EHR is the EHR's own; it is not ended here.
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

use OpenEMR\SmartQuestionnaire\LaunchStore;
use OpenEMR\SmartQuestionnaire\Session;
use OpenEMR\SmartQuestionnaire\View;

Session::start();

$launchId = $_POST['lid'] ?? null;
$record = LaunchStore::get($launchId);
// A launch that is already gone (expired, or signed out in another tab) is signed out.
if ($record !== null) {
    if (
        ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_string($launchId)
        || !is_string($_POST['csrf'] ?? null) || !hash_equals((string)($record['csrf'] ?? ''), $_POST['csrf'])
    ) {
        View::error(403, 'Sign-out refused', 'The sign-out request could not be verified, so you are still signed in. '
            . 'Go back and use the Sign out button on the page.');
    }
    LaunchStore::forget($launchId);
    session_regenerate_id(true);
}

header('Location: launch.php?signed_out=1', true, 303);
exit;
