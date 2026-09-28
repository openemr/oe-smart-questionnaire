# Contributing

Thanks for helping improve OpenEMR SMART Questionnaires. This project follows OpenEMR's conventions,
so its [contributing guide](https://github.com/openemr/openemr/blob/master/CONTRIBUTING.md) applies
unless this file says otherwise.

## Where changes belong

| Change | Repository |
|--------|------------|
| This app: launch, workspace, saving, registration, docs | **here** |
| The questionnaire renderer (`openemr_questionnaire.js`/`.css`) | [openemr/openemr](https://github.com/openemr/openemr). It is vendored here unmodified. |
| OpenEMR's FHIR API, OAuth2 server, consent screen, scopes | [openemr/openemr](https://github.com/openemr/openemr) |

If unsure, open an issue or ask on the [forum](https://community.open-emr.org/).

## Development setup

```bash
git clone https://github.com/openemr/oe-smart-questionnaire.git
cd oe-smart-questionnaire
composer install        # PHP_CodeSniffer, PHPStan (dev only)
npm ci                  # jsdom for the browser test (dev only)
composer check          # everything CI runs
```

The app itself has no runtime dependencies. Keep it that way: it must run from a plain checkout
with PHP 8.1+ and `curl`. `vendor/` and `node_modules/` are tooling only.

To try changes against a real OpenEMR, follow [INSTALLATION.md](INSTALLATION.md). A local OpenEMR
with a self-signed certificate needs `'verify_tls' => false` in `config.php`.

## Checks

| Command | What it does |
|---------|--------------|
| `composer lint` | PHP syntax of every file |
| `composer cs` / `composer cs-fix` | PSR-12 (`phpcs.xml.dist`) / auto-fix |
| `composer stan` | PHPStan level 8 on `src/`, `public/`, `bin/` |
| `composer test-unit` | `tests/unit.php` |
| `composer test-e2e` | `tests/e2e.php`: real HTTP through launch → authorize → save against `tests/mock/ehr.php`, plus the jsdom browser test when installed |
| `composer check` | All of the above |

CI runs these on PHP 8.1–8.5 on Linux and PHP 8.3 on Windows, and also checks that:
- every source file carries the license header;
- the vendored runtime matches `public/assets/runtime/runtime.sha256`.

## Standards

- **PHP:** PSR-12, `declare(strict_types=1)` in every file, typed properties and signatures,
  PHPStan level 8 clean. Classes live in `src/` under `OpenEMR\SmartQuestionnaire`.
- **Output:** escape with `h()` in HTML, and embed data for scripts as JSON in
  `<script type="application/json">`. The CSP forbids inline script, so behavior goes in
  `public/assets/*.js`.
- **Security-sensitive rules stay server-side.** The browser never gets a token. The app decides the
  patient, questionnaire and which responses may be written. Keep that property when changing
  `api.php` or `ResponseStamper`.
- **File header.** Every new PHP, JS or CSS file starts with the standard OpenEMR header:

  ```php
  /**
   * One-line description of the file.
   *
   * @package   OpenEMR
   * @link      https://www.open-emr.org
   * @link      https://github.com/openemr/oe-smart-questionnaire
   * @author    Your Name <you@example.org>
   * @copyright Copyright (c) 2026 Your Name <you@example.org>
   * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
   */
  ```

  When you substantially change someone else's file, add yourself as an additional `@author` and
  `@copyright` line; don't remove existing ones.

## Tests

- Add or adjust a test with every behavior change. Use `tests/unit.php` for pure logic (references,
  scopes, stamping), `tests/e2e.php` for anything crossing HTTP, and `tests/browser-smoke.js` for
  workspace behavior.
- `tests/mock/ehr.php` imitates OpenEMR, **including its quirks**: separate read/write scope
  advertisement, the consent screen's merge-and-drop of v2 scopes, OpenEMR's create response shape,
  and registration read-back. When OpenEMR's behavior changes, change the mock to match and keep
  both behaviors covered if older versions still matter.
- Never commit real client IDs, secrets, tokens or patient data, including in fixtures and
  screenshots.

## Vendored runtime

`public/assets/runtime/` is a byte-identical copy of OpenEMR's questionnaire runtime. Don't edit it
here. Change it in openemr/openemr, then copy both files over, update the commit in
`public/assets/runtime/README.md`, and regenerate the checksums:

```bash
cd public/assets/runtime && sha256sum openemr_questionnaire.js openemr_questionnaire.css > runtime.sha256
```

## Commits and pull requests

- PR titles follow [Conventional Commits](https://www.conventionalcommits.org/), as in OpenEMR:
  `feat(admin): …`, `fix(launch): …`, `docs: …`, `test: …`, `ci: …`, `refactor: …`, `chore: …`.
- Keep PRs focused. Update `CHANGELOG.md` under **Unreleased**, and update `INSTALLATION.md` or
  `docs/ARCHITECTURE.md` when setup or behavior changes.
- CI must be green.

## License

Contributions are accepted under the GNU General Public License v3.0, the license of this project
and of OpenEMR. By submitting a pull request you confirm you have the right to license your
contribution that way.

## Conduct

This project follows the [OpenEMR Code of Conduct](CODE_OF_CONDUCT.md).
