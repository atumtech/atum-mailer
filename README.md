# atum.mailer

atum.mailer is a WordPress plugin by [atum.tech](https://atum.tech) that routes `wp_mail()` through Postmark.

## Highlights in 0.4.5

- Modular architecture (`Bootstrap`, `AdminController`, `PostmarkClient`, `MailInterceptor`, `LogRepository`, `SettingsRepository`)
- Safer token reveal flow (disabled by default, explicit confirmation, short-lived reveal session)
- Stream discovery during token verification (`/message-streams`)
- Privacy-aware log modes: `metadata` (default) and `full`
- Queue delivery mode with retry backoff (`429`/`5xx` retryable)
- Expanded telemetry fields (`attempt_count`, `next_attempt_at`, `last_error_code`, `delivery_mode`)
- Daily cron retention cleanup (moved off send path)
- Optional Postmark webhook ingestion endpoint (`/wp-json/atum-mailer/v1/postmark/webhook`)
- Completed admin UX behavior for:
  - From email quick-fill and local-part builder
  - Multi-recipient test email chips

## Requirements

- WordPress 6.5+
- PHP 8.1+

## Installation

1. Copy this plugin folder into `wp-content/plugins/atum-mailer`.
2. Activate **atum.mailer** in WordPress Admin -> Plugins.
3. Open **atum.mailer** in WordPress Admin.
4. Connect and verify your Postmark Server Token.
5. Configure stream, sender defaults, delivery mode, and privacy settings.

## GitHub Updates (Release Provider)

The plugin now supports GitHub Releases as an update provider, including WordPress update checks and one-click plugin upgrades for already-installed sites. First-time installation is still via plugin upload/ZIP.

### Defaults

- Repository: `atum/atum-mailer`
- Release API endpoint: `repos/{owner}/{repo}/releases/latest`
- Preferred asset: `atum-mailer.zip`
- Release metadata cache: 6 hours

### Private repository support

Set a GitHub token (with `contents:read` for the repo) so WordPress can request release metadata and download private assets:

```php
define( 'ATUM_MAILER_GITHUB_REPO', 'your-org/atum-mailer' );
define( 'ATUM_MAILER_GITHUB_TOKEN', 'ghp_xxx' );
define( 'ATUM_MAILER_GITHUB_RELEASE_ASSET', 'atum-mailer.zip' );
```

### Available filters

- `atum_mailer_github_updates_enabled` (bool)
- `atum_mailer_github_repo` (string)
- `atum_mailer_github_token` (string)
- `atum_mailer_github_release_asset` (string)
- `atum_mailer_github_release_cache_ttl` (int seconds)
- `atum_mailer_github_tested_up_to` (string)

## GitHub Release Workflow

Two workflows are included:

- `.github/workflows/ci.yml` runs PHPUnit on push/PR.
- `.github/workflows/release.yml` runs tests, builds `dist/atum-mailer.zip`, and publishes it to a GitHub Release for `v*` tags.

Tag example:

```bash
git tag v0.4.5
git push origin v0.4.5
```

## Operations Docs

- Setup guide: `docs/setup-guide.md`
- Operations runbook: `docs/operations-runbook.md`
- Troubleshooting map: `docs/troubleshooting-map.md`

## Alert Hooks

- `atum_mailer_alert_failure_rate_threshold`
- `atum_mailer_alert_queue_backlog_threshold`
- `atum_mailer_alert_threshold_breach`

Threshold and cooldown tuning filters:

- `atum_mailer_alert_failure_rate_threshold`
- `atum_mailer_alert_queue_backlog_threshold`
- `atum_mailer_alert_cooldown_seconds`
