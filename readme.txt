=== atum.mailer ===
Contributors: atum
Tags: email, postmark, mailer, transactional-email
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.5.0
License: MIT
License URI: https://opensource.org/license/mit/

Route WordPress mail through Postmark with security-first defaults, queue retries, and delivery telemetry.

== Description ==

atum.mailer replaces default WordPress outbound mail transport with Postmark API delivery.

Key capabilities:

* Postmark transport for `wp_mail()` with stream selection
* Safer token handling (reveal disabled by default; explicit confirmed reveal flow)
* Postmark token verification with message stream discovery
* Delivery modes: immediate or queued with retry backoff
* Privacy-aware log detail modes (metadata or full)
* Database-backed logs with telemetry fields (attempts, next retry, last error code, delivery mode)
* Daily cron-based retention cleanup
* Dashboard delivery-health metrics and queue backlog visibility
* Optional webhook endpoint for Postmark event ingestion
* Built-in Send Test Email workflow with multi-recipient chip UI

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the `Plugins` screen in WordPress.
3. Open the `atum.mailer` menu in WordPress Admin.
4. Connect and verify your Postmark Server Token.
5. Configure stream, delivery mode, and privacy/reliability options.

== Frequently Asked Questions ==

= Does this plugin send all WordPress email? =

It sends messages delivered through `wp_mail()` when atum.mailer is enabled and configured.

= What happens if Postmark settings are missing? =

If disabled or no server token is set, WordPress falls back to normal `wp_mail()` behavior.

= Can I keep sensitive payloads out of local logs? =

Yes. Use `Log Detail Mode = Metadata` (default) to avoid persisting full message/payload/response bodies.

= How do webhooks authenticate? =

Set `Webhook Shared Secret` in settings. By default, atum.mailer also requires signed webhook timestamp headers (`X-Atum-Webhook-Timestamp` and `X-Atum-Webhook-Signature`) plus the shared secret header (`X-Atum-Webhook-Secret`).

= Can I wire alerts to Slack/email? =

Yes. Use alert action hooks:
* `atum_mailer_alert_failure_rate_threshold`
* `atum_mailer_alert_queue_backlog_threshold`
* `atum_mailer_alert_threshold_breach`

Tune thresholds/cooldown with filters:
* `atum_mailer_alert_failure_rate_threshold`
* `atum_mailer_alert_queue_backlog_threshold`
* `atum_mailer_alert_cooldown_seconds`

= Are there WP-CLI operations commands? =

Yes:
* `wp atum-mailer queue status`
* `wp atum-mailer queue run`
* `wp atum-mailer logs export`
* `wp atum-mailer health check`

= Can this plugin update from GitHub Releases? =

Yes. atum.mailer includes a GitHub updater that injects update metadata into WordPress plugin updates and supports one-click upgrades.
For first-time installs, upload/install the plugin ZIP normally, then future upgrades can come from GitHub releases.

Defaults:
* Repo: `atum/atum-mailer`
* Release asset: `atum-mailer.zip`

Optional constants:
* `ATUM_MAILER_GITHUB_REPO`
* `ATUM_MAILER_GITHUB_TOKEN` (for private repos)
* `ATUM_MAILER_GITHUB_RELEASE_ASSET`

Filters:
* `atum_mailer_github_updates_enabled`
* `atum_mailer_github_repo`
* `atum_mailer_github_token`
* `atum_mailer_github_release_asset`
* `atum_mailer_github_release_cache_ttl`
* `atum_mailer_github_tested_up_to`

== Changelog ==

= 0.5.0 =
* Hardened GitHub release packaging with version-alignment validation across tag, plugin header, and readme.
* Added reusable local/CI release build script for WordPress plugin ZIP creation.

= 0.4.5 =
* Version bump and rebuilt package for latest updates.

= 0.4.4 =
* Version bump and rebuilt package for latest UI/state updates.

= 0.4.3 =
* Visual facelift across the WordPress admin UI (hero, tabs, cards, buttons, and log table interactions).
* Version bump and rebuilt distribution package for this release.

= 0.4.2 =
* Version bump and fresh packaged build for the latest code snapshot.

= 0.4.1 =
* Version bump for current feature-complete packaging refresh.
* Rebuilt distribution ZIP with the latest modular runtime files and UI improvements.

= 0.4.0 =
* Refactored runtime into modular components while keeping `Atum_Mailer` facade compatibility.
* Added safer token reveal flow (disabled by default, explicit confirmation, short-lived reveal session nonce).
* Added Postmark message stream sync during token verification.
* Added log redaction modes (`metadata` default, `full` optional).
* Added queue delivery mode with retry policy hook (`atum_mailer_retry_policy`) and exponential backoff.
* Added telemetry columns: `attempt_count`, `next_attempt_at`, `last_error_code`, `delivery_mode`.
* Added webhook endpoint (`/wp-json/atum-mailer/v1/postmark/webhook`) with shared-secret auth.
* Moved retention cleanup off send path to daily cron (`atum_mailer_daily_cleanup`).
* Added log redaction/filter hook (`atum_mailer_log_record`).
* Completed missing admin JS/CSS behaviors for sender quick-fill and test-recipient chips.
* Added GitHub release updater support for plugin update checks and one-click upgrades.
* Added CI and tag-based release workflows in GitHub Actions.

= 0.3.5 =
* Switched Message Stream setting to a dropdown populated from Postmark server streams.
* Added From Email builder with domain-based quick-fill and local-part composer.
* Added multi-recipient test email adder with chip UI and backend list parsing.

= 0.3.4 =
* Fixed token verification state persistence in settings sanitization.
* Removed "Replace API key" flow from the API card (disconnect to connect a new key).

= 0.3.3 =
* Moved Postmark token persistence to a dedicated option key for reliable verified-state UI updates.
* Added token migration on activation and uninstall cleanup for the dedicated token option.

= 0.3.2 =
* Fixed Postmark API connect/disconnect routing when forms submit as `action=update`.
* Added defensive fallback handler for misrouted admin-post requests.

= 0.3.1 =
* Fixed API key connect/disconnect flow to avoid admin-post blank nonce screen.
* Added graceful redirect notices on security check failures.

= 0.3.0 =
* Added CSV export for filtered logs.
* Added AJAX-powered detailed log drawer.
* Added Postmark API key connect/verify/disconnect flow with verified card UX.

= 0.2.1 =
* Updated all visible branding to `atum.mailer`.
* Refreshed WordPress dashboard UI with a stronger visual design system.

= 0.2.0 =
* Added dashboard UI with atum.tech branding.
* Added Send Test Email tab.
* Added retained mail logs with status tracking and filters.
* Added retention cleanup controls.

= 0.1.0 =
* Initial release.
