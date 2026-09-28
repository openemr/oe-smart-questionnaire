# Security Policy

This app handles OAuth client secrets and access tokens for EHR systems, and writes clinical data.
Please report vulnerabilities privately.

## Reporting a vulnerability

- **Preferred:** [report privately on GitHub](https://github.com/openemr/oe-smart-questionnaire/security/advisories/new).
  See [privately reporting a security vulnerability](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability).
- **Or** email the OpenEMR Security Team at security@open-emr.org, encrypted if possible with the
  [OpenEMR security PGP key](https://www.open-emr.org/files/openemr-security-pgp-key.asc).

Please don't open public issues for security problems, and don't include real client secrets,
tokens or patient data in a report.

Vulnerabilities in OpenEMR itself (its FHIR API, OAuth2 server or consent screen) go to OpenEMR:
see [OpenEMR's security policy](https://github.com/openemr/openemr/blob/master/.github/SECURITY.md).

## Supported versions

Security fixes are made on `master` and released in the next version. Run the latest release.

## Deployment responsibilities

The app's protections assume it is deployed as documented in
[INSTALLATION.md](INSTALLATION.md#14-security-notes):

- only `public/` is web-accessible, and `var/` (client secrets, admin password hash) and
  `config.php` are not;
- it is served over HTTPS;
- `verify_tls` stays `true` outside local development;
- the admin password is strong and known only to administrators.
