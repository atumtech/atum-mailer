# atum.mailer Setup Guide

## 1. Install and Activate

1. Copy plugin folder into `wp-content/plugins/atum-mailer`.
2. Activate `atum.mailer` in WordPress Admin.
3. Open `atum.mailer` from the admin menu.

## 2. Core Setup Checklist

1. Connect and verify your Postmark server token.
2. Set a verified sender (`From Email`).
3. Send a test email from `Send Test`.
4. Enable delivery routing.
5. Optional: configure webhook secret and Postmark webhook target.

## 3. Delivery Modes

- `Immediate`: send during request execution.
- `Queue`: enqueue and process with retries/backoff via cron.

Use `Queue` for production sites that need better resilience during provider/API spikes.

## 4. Recommended Production Defaults

- `Delivery Mode`: `queue`
- `Fallback to wp_mail()`: enabled (if native MTA is acceptable)
- `Log Detail Mode`: `metadata`
- `Retention`: enabled with 30-90 day window
- `Webhook Secret`: configured
- `Require Signature Verification`: enabled (default)
- `Webhook Replay Window (s)`: `300`
- `Webhook Source IP Allowlist`: configured (when provider CIDRs are known)

## 5. Verify Final State

On `Dashboard`, confirm:

- Setup Readiness = `Ready`
- Queue backend = database
- Queue processor cron scheduled
- No queue backlog growth trend

## 6. Optional Integrations

- WP-CLI:
  - `wp atum-mailer queue status --format=json`
  - `wp atum-mailer queue run`
  - `wp atum-mailer logs export --format=csv --output=/tmp/atum-mailer-logs.csv`
  - `wp atum-mailer health check --format=json`
- Alert hooks:
  - `atum_mailer_alert_failure_rate_threshold`
  - `atum_mailer_alert_queue_backlog_threshold`
  - `atum_mailer_alert_threshold_breach`

## 7. Security References

- `docs/security-hardening.md`
- `docs/pre-release-security-checklist.md`
