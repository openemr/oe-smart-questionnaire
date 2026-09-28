# Third-party notices

## Distributed with the app

| Component | Location | License |
|-----------|----------|---------|
| OpenEMR native FHIR Questionnaire runtime (Jerry Padgett, OpenEMR) | `public/assets/runtime/` | GNU GPL v3 ([OpenEMR LICENSE](https://github.com/openemr/openemr/blob/master/LICENSE)) |
| Bootstrap 4.6.2 CSS (The Bootstrap Authors, Twitter, Inc.) | `public/assets/vendor/bootstrap-4.6.2.min.css` | MIT ([license](https://github.com/twbs/bootstrap/blob/v4.6.2/LICENSE)); the notice is kept in the file header |

## Development only (not distributed)

| Component | Used for | License |
|-----------|----------|---------|
| PHP_CodeSniffer | coding standard | BSD-3-Clause |
| PHPStan | static analysis | MIT |
| jsdom | browser test | MIT |

These are installed by `composer install` / `npm ci` into `vendor/` and `node_modules/`, which are
not part of the app or of release archives.
