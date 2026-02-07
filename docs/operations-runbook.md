# atum.mailer Operations Runbook

## Monitoring Cadence

- Daily: check `Dashboard` health cards and queue backlog.
- Weekly: review failure trend, dead-letter count, and retry error codes.
- Monthly: verify retention settings still align with policy.

## Key Signals

- `Queue Backlog`
- `Oldest queued age`
- `Failure rate (24h)` and trend
- `Dead letter count`
- `Last API outage`

## Incident Workflow

1. Confirm Postmark status and token validity.
2. Check `Mail Logs` for `last_error_code` patterns.
3. If backlog exists, run manual queue processing:
   - Admin: `Process Queue Now`
   - CLI: `wp atum-mailer queue run`
4. Use bulk retry or selective resend with overrides for impacted messages.
5. For webhook-related issues, verify shared secret, signature requirements, and source IP allowlist/proxy trust settings.
6. If persistent failures continue, enable fallback and notify stakeholders.

## Queue Recovery Commands

- Snapshot:
  - `wp atum-mailer queue status --format=json`
  - `wp atum-mailer health check --format=json`
- Process:
  - `wp atum-mailer queue run`
- Purge stale queue (last resort):
  - `wp atum-mailer queue purge --older-than=86400`

## Log Export for Investigation

- Filter in UI and export CSV, or:
- `wp atum-mailer logs export --status=failed --search="http_5" --format=csv --output=/tmp/failed-mail.csv`

## Alert Integration Hooks

- Failure rate threshold breach:
  - `atum_mailer_alert_failure_rate_threshold`
- Queue backlog threshold breach:
  - `atum_mailer_alert_queue_backlog_threshold`
- Generic breach hook:
  - `atum_mailer_alert_threshold_breach`

Context payload includes threshold values and evaluated metrics for downstream Slack/email/webhook dispatchers.

## Recommended Alert Thresholds

- Failure rate 24h: 5-10%
- Queue backlog: 50-200 (depends on traffic profile)
- Cooldown: 30-60 minutes to avoid alert storms

Configure with filters:

- `atum_mailer_alert_failure_rate_threshold`
- `atum_mailer_alert_queue_backlog_threshold`
- `atum_mailer_alert_cooldown_seconds`

## Security Maintenance

Before major version bumps or release packaging, run:

- `docs/pre-release-security-checklist.md`
