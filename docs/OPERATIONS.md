# Synthetic staging operations — operator gate #75

Status: read-only readiness plan, not installation or release authorization. Source inspected at `c3009772bd6f91017812f43144f78364bb016bed` (PR #192). The GitHub connector cannot execute OS commands. The operator must explicitly confirm the existing dedicated synthetic staging scope and isolation from corporate/production resources. Never include local paths, hostnames, addresses, secret values, dotenv contents, raw Docker inspect output or device backups in public issues.

## 1. Metadata-only inventory before sync or rebuild

Use the existing authorized local SSH session; no new agent, no remote Docker daemon. Confirm branch custom, Git cleanliness (no filenames), current HEAD, expected candidate SHA above. Use the known authorized checkout and owner; do not fetch, merge or build in this step. Inspect only images matching the previously agreed staging naming convention. Record image ID and validated 40-hex OCI revision; a label is self-reported provenance, not a reproducible-build guarantee.

Source contract: Dockerfile uses ARG SOURCE_REVISION and LABEL org.opencontainers.image.revision. compose.staging.yaml names the project rconfig-v8-custom-staging, two infra services db and redis, internal bridge, volumes staging_db_data and staging_redis_data. Compare actual project membership, container health, no port bindings, only the intended internal network, and expected volume destinations /var/lib/mysql and /data. Inspect selected fields only. Refuse remote Docker contexts; no full environment/inspect dumps. Do not report arbitrary bind source paths. A missing/stale image or drift is a stop for review, not permission to modify.

## 2. Protected configuration and empty-state preflight

After the inventory is reviewed, independently confirm the current checkout script and its approved config/secret directories. The existing docker/staging/bootstrap-preflight.sh --check is root-only and read-only: verifies canonical protected directories, .env root:33 0640, matching private DB/Redis passwords without printing values, log-only mail, APP_DEBUG=false, F02 queue disabled, two healthy infra containers and empty MariaDB schema/Redis DB0/1. It does not verify every optional activation flag or host egress; review the stricter role-entrypoint and bootstrap-container checks separately before any application start. Never invoke with shell tracing. No migrations, key generation, secret rotation or Docker starts. This is a point-in-time check, not a lock.

## 3. Subsequent gates — separate approval required

1. Resolve any inventory drift; pin a reviewed source and record image lineage. Only then approve a clean fast-forward, source build and image inspection separately, one mutation at a time.
2. Review isolation including actual host egress and protected volumes, snapshot/restore and rollback plan; no connectivity probes to external or corporate endpoints without approved scope.
3. Verify synthetic one-shot bootstrap configuration and marker semantics from current source, then separately approve bootstrap. Never use the all-in-one upstream installer, regenerate APP_KEY or erase volumes.
4. Start individually approved app/Horizon/scheduler roles only after bootstrap seal, with all custom activation flags OFF, no external provider traffic and no exposed management endpoints.
5. Use a separate approved F02 synthetic runtime test design for actual staging protected publisher and reader mount, including ordinary device completion semantics. The CI-only publisher and recovery are not operational deployment tools. F01 three-version comparison, UI/API authorization and F03/F04 staging checks remain required.
6. Stop at any divergence. Record sanitized results in issue #75 and PROGRESS, keep G1–G6 open until evidence and approvals are complete. No release tag, registry publication or production action.

## Recovery and safety

No rollback action is required for read-only inventory. For future changes, prepare coherent DB/Redis/storage/APP_KEY restore with writers stopped, tested independent restore and reviewed migration compatibility. Do not run docker compose down -v, redis flush, artisan migrate or key:generate as a readiness check. No real backup contents, account tokens or actual provider destinations in public repository. See TEST_PLAN and PROJECT_HANDOFF.

## Candidate operator bootstrap (#194)

The installed bootstrap entrypoint retains default --check. Explicit --apply additionally requires RCONFIG_STAGING_BOOTSTRAP_APPROVAL=operator-reviewed-v1, the exact provisioned log-only dotenv, no ambient overrides, all custom flags OFF and the strict existing coordinator. The token is an invocation guard, not proof of a snapshot or approval. Do not set it on ordinary roles. Hosted CI must validate the exact installed entrypoint before acceptance. No operator apply until reviewed final image, existing storage and fresh SQL/Redis, consistent powered-off snapshot/recovery, protected mounts, isolated synthetic scope and separately confirmed operator authorization. If apply fails, preserve attempt and state; no retry or marker repair. The default Compose command remains --check. The old integrated Core entrypoint remains unchanged.
