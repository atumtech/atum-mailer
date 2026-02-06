# atum.mailer Troubleshooting Map

## Symptom: Messages Are Not Sending

- Check plugin `enabled` state.
- Verify Postmark token is connected and verified.
- Send test from `Send Test` tab.
- Inspect latest `failed` logs for `http_status` and `last_error_code`.

## Symptom: Queue Backlog Keeps Growing

- Confirm queue cron schedule exists.
- Run `Process Queue Now` or `wp atum-mailer queue run`.
- Inspect retry/backoff settings.
- Check for repeated `429`/`5xx` provider responses.

## Symptom: Many Dead Letters

- Open logs filtered to `dead_letter`.
- Use `View` drawer timeline to see retry attempts and webhook outcomes.
- Use `Retry selected` or resend with guarded overrides.
- Consider temporary fallback to native `wp_mail()`.

## Symptom: Webhook Events Missing

- Verify webhook URL:
  - `/wp-json/atum-mailer/v1/postmark/webhook`
- Confirm `X-Atum-Webhook-Secret` matches plugin secret.
- Validate Postmark webhook configuration and event selections.

## Symptom: Token Reveal/Connect Issues

- Ensure user has `manage_options`.
- Confirm nonces are fresh (retry action).
- If reveal is disabled, enable `Allow API Key Reveal` temporarily.

## Symptom: CSV Export Is Empty

- Clear restrictive filters (`status`, `date`, `provider message id`).
- Check selected/bulk mode inputs.
- Re-run with broad filter and compare counts.

## Fast Diagnosis Commands

- `wp atum-mailer queue status --format=json`
- `wp atum-mailer health check --format=json`
- `wp atum-mailer logs export --status=failed --format=json --limit=50`

## Escalation Data Package

For support handoff collect:

- `health check` JSON output
- queue status JSON output
- failed/dead-letter CSV export
- one example log detail timeline screenshot
