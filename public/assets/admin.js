/**
 * Confirmation prompts for admin.php (the Content-Security-Policy blocks inline handlers).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @link      https://github.com/openemr/oe-smart-questionnaire
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

document.addEventListener('submit', (event) => {
    const button = event.submitter;
    const message = button && button.dataset ? button.dataset.confirm : null;
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});
