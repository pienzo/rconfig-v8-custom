# rConfig V8 Custom — Progress

**Checkpoint:** 2026-11-22  
**Project:** public open-source extension of rConfig V8 Core, also usable in authorized enterprise environments; not an employer-owned private project.  
**Master:** `rConfig_V8_Custom_Piano_Sviluppo_MASTER.md` v2.0 (project specification held in ChatGPT; not yet committed here).  
**Current gate:** GitHub bootstrap complete; F02 matcher and metadata-only scanner merged into `custom`. Issue #7 builds deterministic UTC freshness/stability candidate policy. Staging still required before any F02 acceptance or release.

## Verified GitHub bootstrap
- Source repository: [rconfig/rconfig](https://github.com/rconfig/rconfig); fork: [pienzo/rconfig-v8-custom](https://github.com/pienzo/rconfig-v8-custom), **public, true GitHub fork**.
- The fork default branch is `main`, reserved as an unmodified mirror of upstream; persistent development integration branch: `custom`.
- Both branches were observed at `121cff1b243794af260f6c5850553b5f1b0af881` before the first documentation change.
- Selected Core baseline: release [`core-8.3.0`](https://github.com/rconfig/rconfig/releases/tag/core-8.3.0) (2026-11-20). Annotated tag object: `c49b3fa38d965a8e2537f45705b7c5f8bd5e454f`; dereferenced commit: `121cff1b243794af260f6c5850553b5f1b0af881`. Source `VERSION` = `8.3.0`.
- GitHub connection: authenticated as `pienzo`, read/write repository capabilities verified. The connector supports branches, files, issues, and PRs; no native fork/repository-creation or repository-settings mutation tool was found.
- GitHub Issues enabled. [Issue #1](https://github.com/pienzo/rconfig-v8-custom/issues/1) was closed after [PR #2](https://github.com/pienzo/rconfig-v8-custom/pull/2) merged to `custom` on 2026-11-22. Merge SHA: `17a6c8e06fc7e8e57d0af78d36bdec9863fb0be1`.
- F02 matcher: [issue #3](https://github.com/pienzo/rconfig-v8-custom/issues/3) closed, [PR #4](https://github.com/pienzo/rconfig-v8-custom/pull/4) merged to `custom` after **5/5 GitHub CI checks passed**; merge SHA `ac825ea7b65619d90c29318f33db01b6f8d9b917`.
- F02 scanner: [PR #6](https://github.com/pienzo/rconfig-v8-custom/pull/6) merged on 2026-11-22 after [CI #45034475773](https://github.com/pienzo/rconfig-v8-custom/actions/runs/45034475773) passed 5/5 checks. Merge SHA: `2e1bbc4e51ff6d239bc3e6ba0209bf0dcc332b90`. [Issue #5](https://github.com/pienzo/rconfig-v8-custom/issues/5) remains open for safe-open/operational validation.
- Current work: [issue #7](https://github.com/pienzo/rconfig-v8-custom/issues/7), branch `feature/f02-observation-policy` from `custom`.
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
- F02 observation policy in issue #7: new unused `ExternalBackupObservationPolicy` evaluates two UTC metadata observations against scheduled/deadline windows, minimum size and quiet interval. It yields `pending`, `failed` or `candidate_requires_safe_open`; no `SUCCESS`. PHP 8.4.24 syntax checks on source and Pest tests plus **12 standalone smoke assertions** passed locally using synthetic arrays. GitHub CI for #7 **pending**, not claimed passed.
- Pending: safe-opening files by validated handle (including TOCTOU), robust upload completeness, per-run file consumption/idempotency persistence, Task/scheduler/manual run, UI/API RBAC, production build, staging/E2E and GPL/MIT licensing clarification. No integration with real devices or customer data.

## Next gates
1. Open a draft PR from `feature/f02-observation-policy` into `custom` for issue #7; inspect all five CI checks before any merge.
2. Keep issue #5 open. Do not treat two matching metadata snapshots as backup SUCCESS: a secure handle-based open, file permissions/replacement checks, source trust and a consumption ledger are not implemented.
3. Design persistence + idempotent state machine in separate issue/PR, with unique `(rule_id, scheduled_at_utc)` and safe file identity bookkeeping.
4. Once backend tests pass, integrate native Task validation, scheduler/manual execution, UI/API RBAC and notifications. Keep `main` unchanged.
5. Agree on an isolated staging VM/platform and obtain authorization before setting up actual SFTP/FTPS, workers or testing E2E. No production actions.

**Rollback:** PR #2 (docs), PR #4 (matcher) and PR #6 (scanner) are merged into `custom`, but their libraries are not activated in Tasks. Policy #7 is also standalone; revert any slice via reviewed PR if needed. Schema migrations will need dedicated rollback. `main` unchanged.
