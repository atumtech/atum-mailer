# atum.mailer Security Hardening Guide

## Security Objective

Prevent `atum.mailer` from being an initial compromise path by minimizing attack surface, constraining trust boundaries, and enforcing secure defaults.

## Baseline Production Settings

Use these values as your default baseline:

1. `Log Detail Mode`: `metadata`
2. `Allow API Key Reveal`: disabled
3. `Webhook Shared Secret`: set (high-entropy random value)
4. `Require Signature Verification`: enabled (default)
5. `Webhook Replay Window (s)`: `300` (or lower if clocks are stable)
6. `Webhook Rate Limit (/min/IP)`: set for your traffic profile (for most sites: 60-240)
7. `Webhook Source IP Allowlist`: set to sender CIDRs whenever possible

## Webhook Ingress Guardrails

`atum.mailer` enforces:

1. Secret header validation
2. Optional HMAC signature + timestamp verification
3. Replay detection
4. Request body size cap
5. Per-IP rate limiting
6. Optional source IP allowlist (exact IP or CIDR)

### Source IP Allowlist Recommendations

Set allowlisted sources in plugin settings (`Webhook Source IP Allowlist`), one entry per line:

```text
203.0.113.10
203.0.113.0/24
2001:db8::/32
```

If your origin is behind a trusted proxy/CDN, enable forwarded-IP trust explicitly in `wp-config.php` or a small must-use plugin:

```php
add_filter(
	'atum_mailer_webhook_trust_forwarded_ip_headers',
	static function () {
		return true;
	}
);
```

Do not enable forwarded-header trust unless your edge strips spoofed client IP headers.

## WAF / Reverse Proxy Recommendations

Protect `/wp-json/atum-mailer/v1/postmark/webhook` at the edge:

1. Restrict method to `POST`
2. Enforce request size limit at edge and app
3. Apply IP allowlist at edge when provider CIDRs are known
4. Add route-specific rate limiting
5. Keep TLS required end-to-end

## CSP Guidance

`atum.mailer` runs in wp-admin and does not require frontend script injection. Use a strict CSP for public frontend pages and avoid broad `unsafe-inline`/`unsafe-eval` policies where possible.

For wp-admin, apply CSP changes carefully because WordPress core/admin plugins may rely on inline scripts. Test admin workflows before enforcing strict CSP in admin paths.

## Secrets Handling

1. Postmark token and webhook secret are stored in dedicated options, not in the main settings blob.
2. Secrets are encrypted at rest when WordPress salt keys are available.
3. Webhook secret is not re-rendered into admin HTML once saved.
4. Blank webhook secret saves preserve existing secret.
5. Clearing webhook secret requires explicit operator intent.

## Deployment Verification

After any deploy/update:

1. Send a test email from `Send Test`
2. Confirm webhook events are accepted
3. Confirm non-allowlisted source test is blocked (if allowlist is enabled)
4. Check `Mail Logs` for unexpected `failed` spikes or repeated `webhook_auth_failed`/signature failures
