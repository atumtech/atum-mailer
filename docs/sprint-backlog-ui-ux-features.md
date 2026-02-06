# atum.mailer Sprint Backlog

## Scope
This backlog covers UI/UX and feature overhauls for `atum.mailer`.

Hardening/security work is assumed to be owned by a separate track, except where UI/UX delivery depends on runtime stability.

## Planning Assumptions
- Team: 1 engineer full-time.
- Sprint length: 1 week.
- Capacity target: 18-24 story points (SP) per sprint.
- Estimate scale:
  - `1 SP`: 0.5 day
  - `2 SP`: 1 day
  - `3 SP`: 1.5 days
  - `5 SP`: 2-3 days
  - `8 SP`: 4-5 days

## Milestones
| Milestone | Outcome | Target Sprint |
| --- | --- | --- |
| M0 | Runtime and queue architecture stable | Sprint 1 |
| M1 | Guided setup + readiness dashboard live | Sprint 2 |
| M2 | Logs and queue operations center live | Sprint 3 |
| M3 | Replay/resend workflows + integrations base | Sprint 4 |

## Backlog
| ID | Sprint | Item | SP | Depends On | Acceptance Criteria |
| --- | --- | --- | --- | --- | --- |
| AM-001 | Sprint 1 | Fix runtime class loading for contracts/repositories in plugin bootstrap (`contracts`, queue repos). | 3 | None | Plugin activates without fatal errors; contract and queue classes autoload/require cleanly in production and tests. |
| AM-002 | Sprint 1 | Unify queue engine to repository pattern (remove option-queue logic from mail interceptor runtime path). | 8 | AM-001 | Queue enqueue/claim/release/fail flow uses repository abstraction only; backlog counts match processing reality. |
| AM-003 | Sprint 1 | Add regression tests for queue repository flow (enqueue, retry, success, stale recovery). | 5 | AM-002 | Automated tests cover repository-backed queue lifecycle and cron scheduling behavior. |
| AM-004 | Sprint 1 | Add uninstall cleanup for new queue table/options. | 2 | AM-002 | Uninstall removes queue DB table and migration/version options. |
| AM-005 | Sprint 1 | Add admin health card for runtime integrity (queue backend, cron hooks, DB versions). | 3 | AM-002 | Dashboard displays actionable warnings when queue tables/hooks are missing or stale. |
| AM-006 | Sprint 2 | Build Setup Checklist UI (`Connect token -> Verify sender -> Send test -> Enable delivery -> Configure webhook`). | 8 | AM-001 | Checklist appears in Dashboard with completion states and deep links to exact action panels. |
| AM-007 | Sprint 2 | Add "Readiness" top card with blocking/non-blocking states and clear CTA. | 3 | AM-006 | Readiness status updates immediately after each successful setup action. |
| AM-008 | Sprint 2 | Redesign Settings screen with grouped cards and progressive disclosure for advanced settings. | 5 | AM-006 | First-time users see only core setup settings; advanced controls are expandable. |
| AM-009 | Sprint 2 | Improve Send Test workflow UX with delivery mode context and better result messaging. | 3 | AM-007 | Success/failure notices include mode-aware text and direct links to related logs entries. |
| AM-010 | Sprint 2 | Add accessibility improvements for tabs/forms/alerts (keyboard navigation, focus order, aria labels). | 3 | AM-008 | Admin UI passes manual keyboard-only walkthrough and basic screen reader checks. |
| AM-011 | Sprint 3 | Expand Logs filters: date range, delivery mode, retry state, provider message ID. | 5 | AM-002 | Users can filter logs by all added criteria; pagination and export preserve filters. |
| AM-012 | Sprint 3 | Add bulk actions in logs (`retry selected`, `export selected`, `purge filtered`). | 8 | AM-011 | Multi-select actions work safely with nonce/cap checks; results shown via inline notices. |
| AM-013 | Sprint 3 | Upgrade log detail drawer to event timeline view (attempts, retries, webhook events). | 5 | AM-011 | Drawer shows ordered event timeline and key metadata without raw JSON-first UX. |
| AM-014 | Sprint 3 | Improve modal accessibility (focus trap, focus restore, ARIA live status). | 3 | AM-013 | Drawer is fully operable via keyboard and returns focus to trigger on close. |
| AM-015 | Sprint 3 | Add queue operations panel (backlog size, oldest queued age, next attempt ETA, "process now"). | 5 | AM-002 | Dashboard displays live queue metrics and allows manual queue trigger with result notice. |
| AM-016 | Sprint 4 | Add replay/resend from log entry with editable recipient/subject safeguards. | 8 | AM-012 | Admin can resend failed/sent logs; replay actions are logged with source log reference. |
| AM-017 | Sprint 4 | Preserve richer webhook event semantics in UI (delivery, open, click, bounce, complaint). | 3 | AM-013 | Timeline and status chips show event types without collapsing all events into a single state. |
| AM-018 | Sprint 4 | Add WP-CLI operations (`queue:run`, `queue:stats`, `logs:export`, `health:check`). | 5 | AM-002 | Commands execute reliably and output machine-readable summaries for support workflows. |
| AM-019 | Sprint 4 | Add alerting hooks for failure-rate and queue-backlog thresholds (Slack/email integration points). | 5 | AM-015 | Threshold breaches fire documented actions with sample integration snippets. |
| AM-020 | Sprint 4 | Ship docs refresh (setup guide, operations runbook, troubleshooting map). | 3 | AM-006, AM-015, AM-018 | Docs reflect final flows and include incident handling procedures. |

## Critical Path
1. `AM-001`
2. `AM-002`
3. `AM-006`
4. `AM-011`
5. `AM-012`
6. `AM-016`

If this path slips, all downstream UI and feature work slows.

## Recommended Sprint Commitments
| Sprint | Planned Items | Total SP |
| --- | --- | --- |
| Sprint 1 | AM-001, AM-002, AM-003, AM-004, AM-005 | 21 |
| Sprint 2 | AM-006, AM-007, AM-008, AM-009, AM-010 | 22 |
| Sprint 3 | AM-011, AM-012, AM-013, AM-014, AM-015 | 26 |
| Sprint 4 | AM-016, AM-017, AM-018, AM-019, AM-020 | 24 |

## Notes on Risk
- Sprint 3 is over capacity; move `AM-015` to Sprint 4 if velocity is below 22 SP.
- `AM-016` depends on good log data quality and clear event modeling from Sprints 2-3.
- Keep test coverage gates active during Sprint 1 to avoid feature work on unstable foundations.

