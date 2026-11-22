# rConfig V8 Custom — Progress

**Checkpoint:** 2026-11-22  
**Project:** public open-source extension of rConfig V8 Core, also usable in authorized enterprise environments; not an employer-owned private project.  
**Master:** `rConfig_V8_Custom_Piano_Sviluppo_MASTER.md` v2.0 (project specification held in ChatGPT; not yet committed here).  
**Current gate:** Phase 0 GitHub bootstrap completed; Phase 1 source audit documented; F02 first implementation slice in progress. Phase 2 staging not provisioned (not needed for isolated source/CI development; required before F02 E2E acceptance).

## Verified GitHub bootstrap
- Source repository: [rconfig/rconfig](https://github.com/rconfig/rconfig); fork: [pienzo/rconfig-v8-custom](https://github.com/pienzo/rconfig-v8-custom), **public, true GitHub fork**.
- The fork default branch is `main`, reserved as an unmodified mirror of upstream; persistent development integration branch: `custom`.
- Both branches were observed at `121cff1b243794af260f6c5850553b5f1b0af881` before the first documentation change.
- Selected Core baseline: release [`core-8.3.0`](https://github.com/rconfig/rconfig/releases/tag/core-8.3.0) (2026-11-20). Annotated tag object: `c49b3fa38d965a8e2537f45705b7c5f8bd5e454f`; dereferenced commit: `121cff1b243794af260f6c5850553b5f1b0af881`. Source `VERSION` = `8.3.0`.
- GitHub connection: authenticated as `pienzo`, read/write repository capabilities verified. The connector supports branches, files, issues, and PRs; no native fork/repository-creation or repository-settings mutation tool was found.
- GitHub Issues enabled. [Issue #1](https://github.com/pienzo/rconfig-v8-custom/issues/1) was closed after [PR #2](https://github.com/pienzo/rconfig-v8-custom/pull/2) merged to `custom` on 2026-11-22. Merge SHA: `17a6c8e06fc7e8e57d0af78d36bdec9863fb0be1`.
- Current F02 issue: [#3](https://github.com/pienzo/rconfig-v8-custom/issues/3); working branch: `feature/f02-filename-matcher` from `custom`.
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
- First F02 code slice (issue #3) adds only `ExternalBackupFilenameMatcher` plus synthetic Pest unit tests and [ADR-0001](adr/0001-external-backup-monitor.md). Local PHP syntax checks and 23 standalone matcher assertions passed using PHP 8.4.24; **GitHub CI for this slice has not yet been run at the time of this commit**.
- Pending: F02 feature PR review/CI, filesystem scanner, persistence, native Task/scheduler/manual run and UI, production frontend build, E2E staging, licensing clarification. Passing CI is **not** deployment authorization.
- No application source code has been changed. No integration with real devices or customer data.

## Next gates
1. Open a **draft PR** from `feature/f02-filename-matcher` into `custom` referencing [issue #3](https://github.com/pienzo/rconfig-v8-custom/issues/3); review diff and require all five GitHub Actions checks.
2. Do not merge until design, tests and PR are reviewed. Keep `main` clean; do not claim F02 works end-to-end based on matcher tests.
3. Implement scanner with strict filesystem containment, observation-based stability and freshness, then persistence/state machine as **separate work items**.
4. Integrate F02 through native task dispatch and Task Wizard only after the backend is secure; audit permissions and run synthetic integration tests.
5. Before staging E2E and before any real SFTP/FTP/FTPS setup, agree on an isolated platform/OS/repository build and obtain any required administrative authorization. Never assume company assets or deploy automatically.

**Rollback:** PR #2 is document-only and merged. The F02 matcher slice adds an unused class and test; revert through a reviewed PR if needed. Later schema migrations need their own rollback plan. `main` must remain unchanged.
