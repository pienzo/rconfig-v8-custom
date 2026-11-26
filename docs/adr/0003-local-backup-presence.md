# ADR — F02 local file presence (issue #196)

Status: proposed, first pure policy candidate. User clarified after successful staging browser smoke that devices already deposit files in existing host directories. rConfig must observe rather than orchestrate transfers. This refines the original MASTER F02 requirement; it does not invalidate the strict receipt mode in ADR-0001 or its tests.

## Contract

- Ordinary local check: one approved directory per rule, one device association, basename glob/regex, schedule, minimum size (default 1 byte), optional maximum age evaluated against the actual observation time (inclusive), optional quiet interval and waiting deadline in later slices. A file delivered before a scheduled check is eligible. With no age limit, an old matching file can satisfy subsequent observations; display this explicitly.
- Presence evidence is distinct from verified transfer receipt. The new pure policy returns present/absent and a reason only; it never records SUCCESS, sends a notification, reads contents or creates a receipt. Future task success means matching file observed, not proof of complete upload, origin, integrity or restorability. Missing/invalid/access failures must be distinguished. No quiet interval is proof of upload completion. Prefer a reasonable age and stability default in the eventual UI, with explicit opt-out.
- Keep the existing strict receipt state machine unchanged. Do not bypass it or reuse its SUCCESS ledger or provider payloads as if a receipt exists. Design an explicit evidence discriminator and idempotent per-occurrence records before persistence/notifications.
- Host admin provisions named authorized roots or directory mappings, exposed read-only to the scanner in the container. UI selects an approved directory and shows its effective mapping. Never accept unrestricted absolute paths or symlinks; validate containment, permissions, directory identity and scan bounds. No remote device commands, no uploader changes, no file modifications. Existing numeric device folders remain supported by strict mode.
- Integrate into native New Task as fourth type, using native schedule picker, local directory and pattern preview, visible age/stability semantics, explicit activation and history. Do not route through native download endpoints. No new scheduler service.

## Incremental acceptance

1. Pure metadata policy and synthetic unit tests (this PR); no runtime wiring.
2. Authorized directory mapping and safe bounded scan adapter; traversal, link, shared-directory and permission negative tests.
3. Additive persistence and distinct task dispatch/activation/history; test occurrence replay, deadlines, optional quiet interval, prior file, stale and partial file; keep strict receipt invariants.
4. Native wizard and notification evidence labels, UI regression and synthetic deployed staging. All activation gates remain OFF until review, approved mount layout and operator consent.

Rollback first slice: revert code/docs PR, no database or runtime changes. Later migrations and operational rollback require their own plans. Refs #105, #75, #194.
