# atum.mailer Pre-Release Security Checklist

Use this checklist before tagging or packaging a release.

## 1. Dependency and Build Hygiene

1. Confirm no debug/test credentials are committed.
2. Confirm release ZIP excludes development-only files and secrets.
3. Confirm plugin version and changelog are correct.

## 2. Automated Validation

1. Run PHPUnit suite and require pass.
2. Run syntax check (`php -l`) on modified PHP files.
3. Verify no new failing/risky tests were introduced.

## 3. AuthN/AuthZ and CSRF Review

1. Confirm admin actions require `manage_options` (or stricter where applicable).
2. Confirm all state-changing admin/AJAX actions verify nonces.
3. Confirm REST webhook route is protected by secret/signature controls.

## 4. Input/Output Safety Review

1. Verify untrusted input is sanitized before use.
2. Verify dynamic output in admin UI is escaped.
3. Verify SQL paths use prepared statements for dynamic values.

## 5. Webhook and Network Trust Controls

1. Confirm webhook signature verification logic and replay lock are intact.
2. Confirm webhook body-size limit is enforced.
3. Confirm webhook rate limiting is enforced.
4. Confirm source IP allowlist support works (exact IP + CIDR).
5. Confirm forwarded-IP trust remains opt-in only.

## 6. Supply Chain / Update Path

1. Confirm updater only accepts trusted HTTPS hosts.
2. Confirm updater package URLs are repository-path-scoped.
3. Confirm token auth headers are only attached to expected GitHub API requests.

## 7. Secrets and Privacy

1. Confirm token + webhook secret are stored outside primary options blob.
2. Confirm secrets are not exposed in rendered admin HTML by default.
3. Confirm log mode default remains privacy-first (`metadata`).

## 8. Release Gate

Release only when:

1. All checks above pass.
2. Known security findings are resolved or explicitly accepted with rationale.
3. Security docs are updated for any new control, filter, or operational requirement.
