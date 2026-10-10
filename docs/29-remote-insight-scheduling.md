# M4 — Bounded Research Insight scheduling

## Product decision

Scheduling belongs in M4 because the Research Theme treats Insights as an editorial stream ordered and presented by publication date. The operating workflow must therefore support preparing an Insight now and publishing it later without returning to generic wp-admin editing.

Taxonomies and generic WordPress authorship are not added in this milestone. The active Research Theme/Manager contract does not currently depend on category/tag navigation or an author-management surface, so adding those features would imitate wp-admin rather than advance the operating model.

## Remote contract

Scheduling reuses the existing exact `insight-update` transport. The only scheduling field is:

- `scheduled_at`

When `scheduled_at` is present it must be the only requested change in that Preview. Content changes, publication-state changes and scheduling remain independently reviewable and reversible.

The accepted value is an RFC3339-compatible date/time and is canonicalised to UTC as `Y-m-dTH:i:sZ`.

## Eligibility

Only explicitly managed Research Insights are accepted.

Scheduling is allowed when the current Insight status is:

- `draft`
- `future`

A published Insight cannot be directly rescheduled. It must first use the bounded publication-state control to return to draft.

The requested publication time must be safely in the future. The current implementation requires more than 60 seconds of lead time when Apply runs.

## Storage and WordPress semantics

Apply writes the WordPress-native scheduling state:

- `post_status = future`
- `post_date` in the configured WordPress site timezone
- `post_date_gmt` in UTC

The Manager exposes a canonical `scheduled_at` derived from `post_date_gmt`.

The generic Research Manager mutation-plan contract is not widened to permit arbitrary `post_status`, `post_date` or `post_date_gmt` writes. Scheduling remains an Insight Editor capability.

## Safety lifecycle

Scheduling uses the same remote lifecycle already established for M4:

`Plan / Preview → explicit Apply → Verify → Rollback`

The Insight baseline checksum includes `scheduled_at`, so external rescheduling after Preview invalidates Apply with the existing stale-Preview guard.

Before Apply, a snapshot records the exact prior values of:

- `post_status`
- `post_date`
- `post_date_gmt`

Rollback therefore cancels a new schedule or restores the previous schedule and calendar state exactly.

## Verification

Stored verification requires:

- status `future`;
- canonical `scheduled_at` equal to the requested value.

A scheduled Insight is intentionally non-public before its publication time. Rendered verification therefore succeeds as a bounded skip with reason `draft-or-non-public`, consistent with draft Insight verification.

SEO/GEO inspection exposes scheduling state, canonical publication time and schedule readiness.

## Acceptance

Fresh-WordPress CI proves:

- scheduling capability discovery;
- past-date rejection;
- mixed content + schedule rejection;
- direct scheduling of a published Insight rejection;
- Preview non-mutation;
- explicit Apply confirmation;
- native WordPress `future` scheduling;
- canonical schedule inspection;
- scheduling diagnostics;
- stored verification and safe rendered skip;
- stale reschedule rejection after an external calendar change;
- rollback to the exact prior draft status and date fields.

## M4 scope decision

With scheduling, M4's intended Research Insight operating surface is complete at repository/CI level:

- inventory and inspection;
- create and edit;
- draft/publish control;
- scheduled publication;
- EN/ES pairing;
- structured Research Line relations and Theme-derived internal links;
- stored and rendered SEO/GEO inspection;
- exact Preview / Apply / Verify / Rollback safety.

Categories/tags and generic author administration are intentionally deferred because they are not part of the current Research Theme product contract.

The remaining M4 task is an aggregate fresh-WordPress acceptance proving these capabilities compose correctly in one editorial workflow. Real operation on `eduardoyauriluna.com` remains a later real-site milestone.
