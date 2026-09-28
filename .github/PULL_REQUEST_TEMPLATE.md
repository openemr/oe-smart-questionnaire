<!--
PR title must follow Conventional Commits, as in OpenEMR:
  feat(scope): …   fix(scope): …   docs: …   test: …   ci: …   refactor: …   chore: …
-->

## What and why

## How it was tested

- [ ] `composer check` passes (lint, PSR-12, PHPStan level 8, unit + end-to-end tests)
- [ ] Browser test ran (`npm ci` first) — or explain why not
- [ ] Tried against a real OpenEMR where relevant (EHR launch, standalone launch, save)

## Checklist

- [ ] New/changed files carry the standard OpenEMR file header (author, copyright, GPL-3 license)
- [ ] `INSTALLATION.md` / `README.md` / `docs/ARCHITECTURE.md` updated if behavior or setup changed
- [ ] `CHANGELOG.md` updated under **Unreleased**
- [ ] No secrets, tokens or patient data in code, tests, fixtures or screenshots
- [ ] Vendored runtime (`public/assets/runtime/`) unchanged, or re-synced from OpenEMR with `runtime.sha256` updated
