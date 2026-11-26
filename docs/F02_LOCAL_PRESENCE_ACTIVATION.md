# Local Presence — activation approval and operational gates

This is a pre-release operator checklist, not an approved deployment or a worker health attestation.

## HTTP contract

Authenticated administrators only: PATCH /api/tasks/local-backup-presence-rules/{id}/activation with exactly {"enabled":true} or {"enabled":false}. Configuration-level `activation_available` in the list response reflects the static approval flag and runtime gates; it is not a test of the selected mapping, capacity, worker health, or mount accessibility. Conflicts return 409; invalid payloads 422. No file contents or absolute root paths are returned.

## Before approving activation

- On an authorized isolated staging installation, confirm exact source SHA, migration status, backup/restore and rollback readiness.
- Verify the actual Horizon identity can traverse and read each approved mapped directory, the storage is mounted read-only for rConfig, ancestors are protected, and symlinks or unexpected mounts are excluded. Use synthetic fixtures; do not read or copy real backup contents for this test.
- Verify identical trusted static configuration across Apache, scheduler and Horizon; verify cache refresh/recreation and worker readiness. No request or environment binding for the approval flag.
- Confirm scheduler heartbeat, dedicated LocalBackupPresence supervisor, retry/timeout ordering, pending ledger handling, and the 100 enabled rule limit. Keep catch-up disabled until the separate recovery policy is approved.
- Only after operator approval of these checks, enable the four existing execution flags and the new activation_api_enabled flag in the approved deployment configuration. The source defaults remain false. A true approval flag is an operator assertion, not an automatically measured health check.

## Maintenance and rollback

First refuse new enables by closing the activation API approval gate across HTTP roles. Drain existing occurrences while execution gates still permit reconciliation/observation. Coordinate maintenance to prevent new scheduled reservations (stop scheduler reservations through the approved operator procedure, without disabling pending reconciliation). Suspend rules via the dedicated service/API only after pending checks finish. Confirm no pending runs or active rules, then stop the relevant roles and close runtime/queue/scheduler/dispatch gates consistently. If a pending run cannot finish, investigate and use an approved recovery procedure; do not edit ledger rows or force-delete backups. Suspending an active rule retains history and activation timestamp and clears scheduler checkpoints. Global gates alone do not reset checkpoints; avoid re-enabling previously active rules without a deliberate suspend/resume cycle. Verify the rollback plan with the exact deployment and database snapshot before any schema rollback.

No production activation, VM change, or external notification is authorized by this document.
