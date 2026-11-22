# rConfig V8 Custom — Progress

**Checkpoint:** 2026-11-22  
**Project:** public open-source extension of rConfig V8 Core, also usable in authorized enterprise environments; not an employer-owned private project.  
**Master:** `rConfig_V8_Custom_Piano_Sviluppo_MASTER.md` v2.0 (project specification held in ChatGPT; not yet committed here).  
**Current gate:** Phase 0 and preliminary code audit complete; F02 matcher, metadata scanner and UTC observation policy merged into `custom`. Issue #9 schema/model/testing slice is in development. Staging and security acceptance still pending.

## Verified GitHub bootstrap
- Source repository: [rconfig/rconfig](https://github.com/rconfig/rconfig); fork: [pienzo/rconfig-v8-custom](https://github.com/pienzo/rconfig-v8-custom), **public, true GitHub fork**.
- The fork default branch is `main`, reserved as an unmodified mirror of upstream; persistent development integration branch: `custom`.
- Both branches were observed at `121cff1b243794af260f6c5850553b5f1b0af881` before the first documentation change.
- Selected Core baseline: release [`core-8.3.0`](https://github.com/rconfig/rconfig/releases/tag/core-8.3.0) (2026-11-20). Annotated tag object: `c49b3fa38d965a8e2537f45705b7c5f8bd5e454f`; dereferenced commit: `121cff1b243794af260f6c5850553b5f1b0af881`. Source `VERSION` = `8.3.0`.
- GitHub connection: authenticated as `pienzo`, read/write repository capabilities verified. The connector supports branches, files, issues, and PRs; no native fork/repository-creation or repository-settings mutation tool was found.
- GitHub Issues enabled. [Issue #1](https://github.com/pienzo/rconfig-v8-custom/issues/1) was closed after [PR #2](https://github.com/pienzo/rconfig-v8-custom/pull/2) merged to `custom` on 2026-11-22. Merge SHA: `17a6c8e06fc7e8e57d0af78d36bdec9863fb0be1`.
- F02 matcher: [issue #3](https://github.com/pienzo/rconfig-v8-custom/issues/3) closed, [PR #4](https://github.com/pienzo/rconfig-v8-custom/pull/4) merged to `custom` after **5/5 GitHub CI checks passed**; merge SHA `ac825ea7b65619d90c29318f33db01b6f8d9b917`.
- F02 scanner: [PR #6](https://github.com/pienzo/rconfig-v8-custom/pull/6) merged on 2026-11-22 after [CI #45034475773](https://github.com/pienzo/rconfig-v8-custom/actions/runs/45034475773) passed 5/5 checks. Merge SHA: `2e1bbc4e51ff6d239bc3e6ba0209bf0dcc332b90`. [Issue #5](https://github.com/pienzo/rconfig-v8-custom/issues/5) remains open for safe-open/operational validation.
- F02 observation policy: [PR #8](https://github.com/pienzo/rconfig-v8-custom/pull/8) merged after all [CI #45036302484](https://github.com/pienzo/rconfig-v8-custom/actions/runs/45036302484) checks passed; merge SHA `8c08c3dc4bb0abbfe1843a6f33501aeb662e7487`. Issue #7 closed.
- Current work: [issue #9](https://github.com/pienzo/rconfig-v8-custom/issues/9), branch `feature/f02-receipt-ledger-schema` from `custom`. Issue #5 remains open for secure filesystem acceptance.
- GitHub Actions was enabled on 2026-11-22. [CI run #45016582452](https://github.com/pienzo/rconfig-v8-custom/actions/runs/45016582452) completed successfully: Pint, PHPStan, ESLint, Vitest and Unit + Fasttests (5/5 passing).
- Active branch ruleset [`custom-pr-ci`](https://github.com/pienzo/rconfig-v8-custom/settings/rules/27838809) targets **only `refs/heads/custom`**. Pull requests are mandatory (0 approvals currently), branch deletion and non-fast-forward pushes are blocked; the five named GitHub Actions checks are required. Required status checks do not currently require the branch to be up to date (`strict_required_status_checks_policy=false`). `main` remains outside this ruleset.
- No staging VM, OS connector, cloud resources, enterprise infrastructure, or production deployment has been configured.

## Phase 1 — preliminary source audit
See [UPSTREAM_AUDIT.md](UPSTREAM_AUDIT.md), pinned to the selected upstream commit.
- **F01 diff:** Core already implements config history, version tracking, stored comparisons and a Monaco diff UI. Reuse; audit gaps in staging before changing it.
- **F02 external-file check (P0):** Task scheduling uses a whitelist of download commands; UI exposes download task types only. Prefer a dedicated check job integrated into existing tasks/scheduler, with additive data models and strict filesystem containment; design still subject to review.
- **F03/F04 notifications:** Core routes DB/mail events. `NotificationChannel`, HTTP validation and SQL check constraints require coordinated changes for additional channels. Template editor is not yet verified.
- **Important discrepancies:** `LICENSE.txt` contains GPL-3.0 text while Composer/npm manifests say MIT (clarify before distributing modified builds); `composer.json` specifies Laravel `^13.0`, not the Laravel 12 quoted in the initial MASTER.
- **Release 8.3.0 risk:** upstream notes that migration clears pending jobs on Redis `default` queue; task reports and config-changed mail use `rConfigDefault`.

## Tests and changes
- Completed: GitHub read-only checks for repository, release, tag, file paths and source; issue #1, feature branch and draft PR #2; two documentation files committed. GitHub Actions activation acknowledged by the user.
- Passed: fork CI workflow with five checks on PR #2 at commit `82dcbaea78110dc2e79bfa2f367e1b7353d2b69a` (documentation-only; checks will rerun on subsequent commits).
- PR #2 merged: last documentation CI run [#45017487952](https://github.com/pienzo/rconfig-v8-custom/actions/runs/45017487952) passed 5/5 checks, and `main` stayed at upstream commit `121cff1b243794af260f6c5850553b5f1b0af881`.
- F02 matcher completed in `custom`: `ExternalBackupFilenameMatcher` plus synthetic Pest tests and [ADR-0001](adr/0001-external-backup-monitor.md). [CI run #45033406255](https://github.com/pienzo/rconfig-v8-custom/actions/runs/45033406255) passed all five required checks for PR #4.
- F02 scanner merged: `ExternalBackupDirectoryScanner` returns bounded direct-child file **metadata**, never backup success. [CI #45034475773](https://github.com/pienzo/rconfig-v8-custom/actions/runs/45034475773) passed all five required checks.
- F02 observation policy merged: `ExternalBackupObservationPolicy` returns `pending`, `failed`, or `candidate_requires_safe_open`, **not backup success**. [CI #45036302484](https://github.com/pienzo/rconfig-v8-custom/actions/runs/45036302484) passed all five checks.
- Issue #9 schema/model slice (not merged): three additive tables for rules, UTC scheduled occurrences, and opaque receipt tokens; matching Eloquent models and synthetic MySQL/Pest schema tests. Cross-device foreign keys, per-rule occurrence uniqueness and per-device file-token uniqueness are enforced in the proposed migration. New rules default to disabled. CI status for this branch **pending**.
- Not implemented: atomic claim services, trustworthy receipt-token generation via secure file handle, filesystem TOCTOU hardening, scheduled jobs, API/GUI, notifications or release. No real backups or environments have been modified. License discrepancy and isolated E2E staging remain blockers.

## Next gates
1. Review a draft PR from `feature/f02-receipt-ledger-schema` into `custom`; verify all five GitHub Actions checks, particularly MySQL migration/index/foreign key tests.
2. Keep issue #9 **open** for the future atomic occurrence and file claim service. No receipt insertion or success state until the safe-open identity token and retry semantics are verified.
3. Keep issue #5 open: hardened filesystem handle validation and confidence in transfer completeness are still missing. Neither a quiet file nor a unique DB token proves a valid backup.
4. Only after secure backend tests: connect explicit F02 task dispatch, scheduler, manual triggers, UI/API RBAC and reporting without loosening Core download command whitelist.
5. Authorize isolated staging before testing real SFTP/FTPS, workers, rollback or production release. Keep `main` tracking clean upstream.

**Rollback:** PRs #2, #4, #6 and #8 are merged to `custom` and have no enabled F02 operator workflow. This first issue #9 migration is **not deployed**, and its `down()` drops its three tables (data-destructive once populated); require tested DB backup/restore and formal authorization before staging/production changes. `main` unchanged.
