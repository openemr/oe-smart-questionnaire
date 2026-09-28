# Vendored OpenEMR questionnaire runtime

`openemr_questionnaire.js` and `openemr_questionnaire.css` are unmodified copies of the OpenEMR native
FHIR Questionnaire runtime (openemr/openemr [#12880](https://github.com/openemr/openemr/pull/12880)):

| File | Source in openemr/openemr |
|------|---------------------------|
| `openemr_questionnaire.js`  | `interface/forms/questionnaire_assessments/native/openemr_questionnaire.js`  |
| `openemr_questionnaire.css` | `interface/forms/questionnaire_assessments/native/openemr_questionnaire.css` |

- Copied from openemr/openemr `master` at `fd70ec0`.
- Author: Jerry Padgett <sjpadgett@gmail.com>.
- License: GNU General Public License 3, as OpenEMR (https://github.com/openemr/openemr/blob/master/LICENSE).

Keep these files byte-identical to upstream so they can be re-synced. Change the runtime in OpenEMR,
then copy it here and update the commit above. CI checks that both files are present and unmodified
against the SHA-256 values in `runtime.sha256`.
