# ADR 0003 — Native per-config backup outcome capture

Status: candidate, pending CI and review.

## Context

At custom baseline 6fc2fb47, Core DeviceDownloadJob and DeviceDownloadClass both call SaveConfigsToDiskAndDb for each returned command, or persist a failure row. DownloadConfigNowJob infers completion from command output; TaskReportJob groups native config rows. Neither job completion nor report completion alone means every backup file succeeded. PR #120 corrected the write-result classification.

## Decision

Capture one outcome per newly saved native config row at SaveConfigsToDiskAndDb's persistence boundary, before version comparison. The custom outcome snapshot is inserted in the same database transaction as the Core row when both RCONFIG_CUSTOM_BACKUP_NOTIFICATIONS_ENABLED and RCONFIG_NATIVE_BACKUP_NOTIFICATIONS_ENABLED are explicitly true and demo mode is off. No network call or queue dispatch occurs there. The native opt-in defaults false. Existing rows are not backfilled. The snapshot carries only the source config ID, device ID/name, normalized event, safe basename for success, duration and UTC capture time. No file content, file path, IP, commands, credentials or raw error. Invalid success metadata produces no success event.

## Consequences and limits

- The event means the per-config save reported a completed native result, not a complete multi-command device run, verified restorable backup, or proof against later overwrite.
- A database error inserting the event rolls back Core metadata in the same transaction when capture is enabled. A previously written file is not rolled back; this is an explicit staging/rollback concern. With capture off the existing save behavior is preserved.
- Config retention can delete source rows; outcome records do not cascade. Establish retention and recovery of undelivered outcomes before activation. Restores or manual ID reseeding require consistent restoration of source and outcome/notification ledgers.
- Keep native per-user mail and report behavior unchanged. Custom per-config dispatch must be separately opted in, reconciled from this durable source, and use existing F04/transport/claim machinery. No historical replay by default.
- No provider delivery path, production activation or data migration of existing configs is introduced here. CI must validate the additive schema and synthetic tests. Rollback: disable both gates, retain outcome data until audited; do not drop delivery history as routine rollback.
