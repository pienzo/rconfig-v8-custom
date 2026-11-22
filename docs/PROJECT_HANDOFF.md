# rConfig V8 Custom — Cross-chat handoff and roadmap

**Verified checkpoint:** 2026-11-23 (GitHub connector, read-only verification before this document).
**Purpose:** durable handoff between ChatGPT conversations. **Operational checkpoint:** [PROGRESS.md](PROGRESS.md). **Source audit:** [UPSTREAM_AUDIT.md](UPSTREAM_AUDIT.md). **Original requirements:** `rConfig_V8_Custom_Piano_Sviluppo_MASTER.md` v2.0 in the ChatGPT project (not committed).
**Evidence rule:** GitHub commits, PRs, issue records, and CI are authoritative for repository changes; only approved staging results can establish deployment or end-to-end behavior. Never infer completed work merely from a plan.

## 2026-11-23 update — validated staging Compose infrastructure (#32)

[PR #31](https://github.com/pienzo/rconfig-v8-custom/pull/31) (docs), [PR #33](https://github.com/pienzo/rconfig-v8-custom/pull/33) (ADR-0004 design, still **Proposed**) and [PR #34](https://github.com/pienzo/rconfig-v8-custom/pull/34) (infra-only Compose) have all merged into `custom`. Current verified merge tip `be719158a6a6adc9a740f34824ca8a373618b1f7`; `main` unchanged `121cff1b243794af260f6c5850553b5f1b0af881`. PR #34 passed **7/7** checks on `eb855aa13caa91119baf8a8984be5969ece86cb4`: Pint, PHPStan, ESLint, Vitest, PHP Unit/Fasttests, Docker storage-seeding and a dedicated [synthetic MariaDB/Redis workflow](https://github.com/pienzo/rconfig-v8-custom/actions/runs/45083516277). The initial Redis startup failure was fixed by explicitly matching the Compose secret target with its private staging config loader. This test occurred **only on hosted disposable GitHub infrastructure**, not on the operator's test VM.

**Next gate:** refresh operator's local VM checkout of `custom`, inspect isolated Docker/Compose settings read-only, then provision synthetic secrets outside Git and test only MariaDB/Redis one step at a time. App/Horizon/scheduler/one-shot install remain unimplemented for the split-role staging profile; integrated upstream Core entrypoint/Supervisor unchanged. No VM migration, corporate deployment or F02 activation. See [issue #32](https://github.com/pienzo/rconfig-v8-custom/issues/32) and [PROGRESS.md](PROGRESS.md).

## 2026-11-23 update — first local Docker build and PR #30 merge

See [PROGRESS.md](PROGRESS.md) and [issue #28](https://github.com/pienzo/rconfig-v8-custom/issues/28) for detailed evidence. Fork `custom` was verified at merge SHA `e6b68a92b648830ef24093b55bda17634f1ef4c7` following [PR #30](https://github.com/pienzo/rconfig-v8-custom/pull/30) (6/6 required checks green, including the Docker storage seeding workflow). `main` stayed at `121cff1b243794af260f6c5850553b5f1b0af881`. A build image was successfully generated on a separate personal Debian 13 test VM **before committing the patch**, with an explicit `-dirty` source label. The repository uses a Node 22 + PHP 8.4 Docker multi-stage build and tighter build-context exclusions; no Compose deployment or runtime installation has yet occurred. Upstream-integrated entrypoint and Supervisor are unchanged.

**Unfinished next gate:** issue #28 isolated Compose/runtime design; do not start services until role separation/entrypoint behavior and secrets/volumes are reviewed. F02 queue opt-in remains off; no production deploy. [Issue #29](https://github.com/pienzo/rconfig-v8-custom/issues/29) tracks the 23 `npm ci` dependency advisories separately. The GPT chat has GitHub access only; authenticated Codex on the personal VM is a separate agent and does not give this chat shell access.

## 1. Identity, baseline and governance

- Source: [rconfig/rconfig](https://github.com/rconfig/rconfig); current verified upstream `main` commit **`121cff1b243794af260f6c5850553b5f1b0af881`**.
- Fork: [pienzo/rconfig-v8-custom](https://github.com/pienzo/rconfig-v8-custom). Verified as public and accessible through the authenticated GitHub account `pienzo`; this is a **personal-account public fork**, not evidence of ownership by an enterprise organization. The MASTER originally anticipated an authorized company organization/account, and an earlier PROGRESS checkpoint describes a public open-source extension. Confirm corporate governance, visibility, authorization, and data policies before using this repository for employer-owned code/data or deployment.
- Version baseline: rConfig V8 **Core** release [`core-8.3.0`](https://github.com/rconfig/rconfig/releases/tag/core-8.3.0), published 2026-11-20. Annotated tag object `c49b3fa38d965a8e2537f45705b7c5f8bd5e454f`; peeled commit `121cff1b243794af260f6c5850553b5f1b0af881`.
- Fork default branch `main` remains exactly at this upstream commit (read-only verified 2026-11-22); **do not put custom commits on main**. Persistent integration branch `custom` was at **`8d59e984dcfbb7135ee72f57a4d4f165ea8a80c0`** after merging PR #22. Verify this SHA again when resuming; it is a checkpoint, not a promise that the tip will never change.
- GitHub connector supports repository reads, branch creation, files, issues and PRs. It is **not** an OS/SSH connection. A `custom-pr-ci` ruleset (ID 27838809) is active on `custom`, requires PR and five named CI checks, with zero required approvals at this checkpoint; the user/company must apply appropriate human review policy before production.
- No VM, staging Linux environment, approved corporate host/network, OS connector, external ingestion transport or production installation exists in the verified project state. Do not treat GitHub Actions hosted runners as company staging.
- License discrepancy: upstream `LICENSE.txt` contains GPL-3.0 text, while Composer/npm package manifests state MIT; still unresolved. Preserve notices and obtain a release/legal determination before distribution.
- The original MASTER describes a **company-oriented Core extension**. The checked-in `PROGRESS.md` calls this a **public open-source extension also usable in authorized enterprise environments**. This governance/scope discrepancy is **open**, not silently resolved. Never add company secrets or actual backup contents to the public fork.

## 2. Verified chronological history

All listed PRs below were **merged into `custom` on 2026-11-22**, not deployed to a server. The dates and merged state were checked from the GitHub PR API; CI-specific claims are sourced from the existing `PROGRESS.md` checkpoint and linked runs.

| Step | Issue / PR | What was integrated | Outcome / boundary |
| --- | --- | --- | --- |
| 0: bootstrap | [#1](https://github.com/pienzo/rconfig-v8-custom/issues/1), [PR #2](https://github.com/pienzo/rconfig-v8-custom/pull/2) | Upstream code audit and `docs/PROGRESS.md` | Baseline pinned, main preserved, initial 5-check CI passed; issue closed. |
| F02-01 | [#3](https://github.com/pienzo/rconfig-v8-custom/issues/3), [PR #4](https://github.com/pienzo/rconfig-v8-custom/pull/4) | `ExternalBackupFilenameMatcher` and ADR-0001 | Safe basename matcher, synthetic tests; issue closed. |
| F02-02 | [#5](https://github.com/pienzo/rconfig-v8-custom/issues/5), [PR #6](https://github.com/pienzo/rconfig-v8-custom/pull/6) | Bounded, read-only per-device directory scanner | Metadata only, never backup SUCCESS. Issue #5 remains open. |
| F02-03 | [#7](https://github.com/pienzo/rconfig-v8-custom/issues/7), [PR #8](https://github.com/pienzo/rconfig-v8-custom/pull/8) | UTC freshness/two-observation stability policy | Only `pending`, `failed`, or `candidate_requires_safe_open`; issue closed. |
| F02-04 | [#9](https://github.com/pienzo/rconfig-v8-custom/issues/9), [PR #10](https://github.com/pienzo/rconfig-v8-custom/pull/10) | Additive external rule, occurrence, verified-file receipt ledger tables | Schema tested in hosted CI; **not migrated on staging/production**. Destructive rollback risk after data is populated. |
| F02-05 | [PR #11](https://github.com/pienzo/rconfig-v8-custom/pull/11) | UTC occurrence claim service with DB rule-row lock | Claims do not create backup success; issue #9 open. |
| F02-06 | [PR #12](https://github.com/pienzo/rconfig-v8-custom/pull/12) | ADR-0002 safe file-open threat model | Design **proposed**, not approved. |
| F02-07 | [PR #13](https://github.com/pienzo/rconfig-v8-custom/pull/13) | `ExternalBackupOpenedHandleInspector` | Inspects pre-opened stream and hashes bounded content; no trustworthy opener/receipt. |
| F02-08 | [PR #14](https://github.com/pienzo/rconfig-v8-custom/pull/14) | Isolated C/Linux `openat2()` probe and tests | 14/14 hosted Linux prototype tests; **no production integration**. |
| Checkpoint | [PR #15](https://github.com/pienzo/rconfig-v8-custom/pull/15) | Linux prototype merge/CI checkpoint documentation | Docs only. |
| F02-09 | [PR #16](https://github.com/pienzo/rconfig-v8-custom/pull/16) | Fail-closed run states, expiry and locking | Does not write SUCCESS or receipt. |
| F02-10 | [PR #17](https://github.com/pienzo/rconfig-v8-custom/pull/17) | Two PHP/MySQL process contention/retry harness | Actual two-process test in hosted CI; no Horizon/runtime scheduler. |
| Checkpoint | [PR #18](https://github.com/pienzo/rconfig-v8-custom/pull/18) | Document two-worker results and unresolved gates | Docs only. |
| F02-11 | [PR #19](https://github.com/pienzo/rconfig-v8-custom/pull/19) | SIGKILL MySQL transaction rollback/postcommit retry proof | Hosted crash/rollback proof, not queue delivery. |
| F02-12 | [PR #20](https://github.com/pienzo/rconfig-v8-custom/pull/20) | Real Redis `queue:work` SIGKILL/redelivery harness, ADR-0003 | Final [CI #45058657425](https://github.com/pienzo/rconfig-v8-custom/actions/runs/45058657425): five checks passed; 1,658 PHP tests passed, five skipped, 14/14 Linux tests, synthetic Redis redelivery marker. No F02 runtime job or SUCCESS path. |
| Checkpoint | [PR #22](https://github.com/pienzo/rconfig-v8-custom/pull/22) | Record PR #20 result, queue safety issue #21 and next gate | Docs-only merge `8d59e984dcfbb7135ee72f57a4d4f165ea8a80c0` (2026-11-22 19:16:32 UTC). |

**Closed issues:** #1, #3 and #7. **Open issues:** [#5 secure scanner/receipt](https://github.com/pienzo/rconfig-v8-custom/issues/5), [#9 ledger/idempotent receipt](https://github.com/pienzo/rconfig-v8-custom/issues/9), [#21 isolated Redis/Horizon queue](https://github.com/pienzo/rconfig-v8-custom/issues/21). PR numbering is not a continuous sequence because issues also use repository numbers.

## 3. Source audit decisions that must survive chat changes

### F01 — Diff (audit first, then only real gaps)
Core has configuration history/version comparisons and Monaco diff UI. Actual paths are documented in [UPSTREAM_AUDIT.md](UPSTREAM_AUDIT.md): `SaveConfigsToDiskAndDb`, `ConfigHistoryManager`, `ConfigCompareService`, `ConfigComparer`, `ConfigVersionCompareService`, `CompareDiffEditorComponent.vue`. **No second diff engine** without evidence from staging.

### F02 — External backup presence/freshness (P0)
Goal: verify files delivered into per-device inboxes, without pretending to SSH to the device. The current additive components detect *candidates* but deliberately cannot claim a verified valid file, write an authoritative success receipt, dispatch operational jobs, or expose a live Task Wizard workflow. A verified arrival does not imply restorability of a configuration.

Security boundary [ADR-0002](adr/0002-safe-external-file-open.md) blocks completion: trusted ancestor paths and mount semantics, race-resistant descriptor opening, writer/reader identity isolation, bounded reads with meaningful timeouts, immutable arrival/provenance contract, safe descriptor handoff, and deduplicated per-device receipt claim. Hosted `openat2` probe validates only a subset.

Queue boundary [ADR-0003](adr/0003-f02-redis-queue-reliability.md), issue #21: pinned Core `config/queue.php` sets `redis.retry_after=65` hard-coded; `.env.example` has `REDIS_QUEUE_RETRY_AFTER=130` but the selected config does **not** use it. `HorizonTwo` uses `timeout=86400`; both existing supervisors use `tries=1`. **Do not globally modify Core queue settings or relax its task command whitelist.** Use an isolated, bounded F02 queue design with `retry_after` safely greater than worker timeout. Redelivery proof in PR #20 used a test-only in-memory four-second reservation, **not** a production value.

### F03/F04 — Notifications and editable templates (not started)
Existing Core channels are DB/email; SQL constraint and request validation disallow other channels. Email subjects are partly hard-coded. After F02 security and queue gates: develop allowlisted, non-executable template rendering; editor for email/Telegram and authorized WhatsApp modes; adapt existing dispatcher. Telegram official Bot API; WhatsApp Meta official platform subject to eligibility/policy/cost approval. Never use WhatsApp Web automation.

## 4. Phase and gate matrix (MASTER phases 0–8)

| Phase | Status at checkpoint | Remaining acceptance gate |
| --- | --- | --- |
| 0 — Bootstrap | **Complete for the current personal/public fork** | Confirm whether public personal fork is approved for company use; protections/reviews as required by company. |
| 1 — Upstream audit | **Preliminary source audit documented** | Resolve license discrepancy and staging-dependent Core behavior/permissions, track upstream release changes. |
| 2 — Staging | **Not started** | Obtain company authorization, OS/hypervisor/network requirements, build from fork, data/Redis/MySQL/Horizon, synthetic inbox, backup/restore and smoke tests. |
| 3 — F02 MVP | **Partial: isolated libraries, schema and CI-only reliability tests** | Approve secure opener, queue/Horizon, atomic receipts, Task/scheduler/API/UI, failure states, E2E uploads and adversarial tests. |
| 4 — F04 + Telegram | **Not started** | Safe template editor, channel adapter, event/delivery audit, mock HTTP tests and authorized live test. |
| 5 — F01 diff gaps | **Native diff mapped; no custom changes** | Test native Core diff on staging, open narrow issues only if a gap is demonstrated. |
| 6 — WhatsApp | **Not started** | Explicit authorized Meta onboarding/eligibility, Utility Direct Send where allowed or approved templates, cost/policy review, mocks and permitted test. |
| 7 — Upstream/release | **Minimal branch/PR discipline established; automation not completed** | Controlled upstream sync PR, regression, migration/rollback rehearsal, versioned build and release approval. |
| 8 — Evolution | **Not started** | Backlog after MVP, optional import of external files into native diff/history subject to independent design. |

## 5. Next actions in strict order

1. **Documentation continuity:** keep this handoff current via a PR to `custom`, linked to [issue #23](https://github.com/pienzo/rconfig-v8-custom/issues/23). Read `PROGRESS.md` at the start of each chat. Do not edit `main`.
2. **Queue design (issue #21):** compare pinned `config/queue.php`, `config/horizon.php`, `.env.example` and native dispatch behavior; prepare a **small design-only ADR revision or new ADR** for isolated F02 Redis connection/supervisor and failure policy, with no runtime enablement until review. Explicitly state job upper bound, worker timeout, retry margin, attempts/backoff, restart policy, failed-job handling and test cases. Independent reviewer/company staging approval for actual values and activation.
3. **Secure file identity (issues #5/#9):** validate ADR-0002 against approved local Linux staging: trusted root, mount boundary and filesystem, uploader/reader permissions and atomic completion contract. No receipt writer or SUCCESS until the security gate passes.
4. **Staging (phase 2):** first ask for **company-specific authorization and platform/OS/network constraints**; do not invent or create VMs, IPs or secrets.
5. **Operational F02:** implement small PRs for scheduler/task/API/UI, safely opened final file/atomic receipt, duplicate/crash and upload testing; no native task whitelist bypass.
6. **Subsequent phases:** F04 + Telegram → only proven F01 diff gaps → Meta WhatsApp → upstream sync/release/E2E gates.

## 6. Procedure when opening any new chat

1. Read the project MASTER and this file, then fetch **current** `docs/PROGRESS.md`, latest `custom` SHA, open issues/PRs and CI from GitHub; do not assume this checkpoint is current.
2. Reconcile discrepancies with real GitHub data and document what was *actually* merged/tested. Check whether a newer upstream Core release requires a controlled review.
3. Choose one small issue/gate. Feature branch from `custom` → minimal patch/tests → draft PR → required CI → review → authorized merge → `PROGRESS.md` update.
4. Never merge or deploy automatically to production; no OS access assumed. Run commands that modify any system **one step at a time** with output verification. Read-only checks may be grouped.
5. Use **synthetic backups**, redact sensitive data and never commit credentials/real network configs. Before any corporate integration re-confirm repository visibility and authorized scope.

**Rollback:** until staging deployment, existing F02 production behavior stays disabled; all completed changes exist only as Git history on `custom`. Revert via reviewed PR if needed. Schema migration rollback may destroy populated ledger rows: never use `migrate:rollback` against a populated database without a tested backup/restore strategy.
