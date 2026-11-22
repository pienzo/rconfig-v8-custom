# rConfig V8 Custom — Progress

**Checkpoint:** 2026-11-22  
**Project:** public open-source extension of rConfig V8 Core, also usable in authorized enterprise environments; not an employer-owned private project.  
**Master:** `rConfig_V8_Custom_Piano_Sviluppo_MASTER.md` v2.0 (project specification held in ChatGPT; not yet committed here).  
**Current gate:** Phase 0 partially complete; Phase 1 source audit preliminary, runtime verification pending.

## Verified GitHub bootstrap
- Source repository: [rconfig/rconfig](https://github.com/rconfig/rconfig); fork: [pienzo/rconfig-v8-custom](https://github.com/pienzo/rconfig-v8-custom), **public, true GitHub fork**.
- The fork default branch is `main`, reserved as an unmodified mirror of upstream; persistent development integration branch: `custom`.
- Both branches were observed at `121cff1b243794af260f6c5850553b5f1b0af881` before the first documentation change.
- Selected Core baseline: release [`core-8.3.0`](https://github.com/rconfig/rconfig/releases/tag/core-8.3.0) (2026-11-20). Annotated tag object: `c49b3fa38d965a8e2537f45705b7c5f8bd5e454f`; dereferenced commit: `121cff1b243794af260f6c5850553b5f1b0af881`. Source `VERSION` = `8.3.0`.
- GitHub connection: authenticated as `pienzo`, read/write repository capabilities verified. The connector supports branches, files, issues, and PRs; no native fork/repository-creation or repository-settings mutation tool was found.
- GitHub Issues enabled. [Issue #1](https://github.com/pienzo/rconfig-v8-custom/issues/1) tracks bootstrap documentation and initial audit.
- Working branch: `docs/bootstrap-upstream-audit`, branched from `custom`.
- Branch rules / required checks: **not configured**; the official CI workflow exists at `.github/workflows/ci.yml`. The user enabled fork Actions on 2026-11-22. Immediately afterward GitHub returned **0 workflow runs and 0 PR check runs**. This documentation commit is intended to trigger a new `pull_request` CI run; its success/failure must be checked separately.
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
- Pending: merge PR, fork CI results, test suite, lint, frontend build, runtime tests, staging/E2E, branch protections, license review.
- No application source code has been changed. No integration with real devices or customer data.

## Next gates
1. Review [draft PR #2](https://github.com/pienzo/rconfig-v8-custom/pull/2) and confirm workflow check runs are emitted after the documentation update.
2. Review diff, CI checks and licensing uncertainty; merge only with explicit approval.
3. Configure appropriate branch protection/rulesets with repository owner, after identifying the actual CI check names. Keep `main` tracking upstream only.
4. Complete remaining Phase 1 source review, including permission checks and functional test plan; open a separate design issue for F02.
5. Discuss staging platform and authorization **only before provisioning**. Never assume enterprise network details or deploy automatically.

**Rollback of this documentation-only PR:** revert its commit on `custom` through a reviewed PR; `main` must remain unchanged.
