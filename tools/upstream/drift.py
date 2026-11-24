"""Read-only report for the approved public fork. Never pushes, merges or deploys."""

import json
import os
import re
import subprocess
import sys
import tempfile
import urllib.error
import urllib.request

API = "https://api.github.com/repos/"
UPSTREAM = "rconfig/rconfig"
FORK = "pienzo/rconfig-v8-custom"
BASELINE_TAG = "core-8.3.0"
BASELINE_SHA = "121cff1b243794af260f6c5850553b5f1b0af881"


def sha(value):
    if not isinstance(value, str) or not re.fullmatch(r"[0-9a-f]{40}", value):
        raise ValueError("Invalid commit or tag object SHA")
    return value


def core_tag(value):
    if not isinstance(value, str) or not re.fullmatch(r"core-\d+\.\d+\.\d+", value, re.ASCII):
        raise ValueError("Latest release is not a recognized stable Core tag")
    return value


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise ValueError("Unexpected API redirect")


def read_json(repo, path):
    if repo not in {UPSTREAM, FORK} or not re.fullmatch(r"[A-Za-z0-9/_.?=&-]+", path):
        raise ValueError("Unexpected API target")
    request = urllib.request.Request(API + repo + "/" + path, headers={
        "Accept": "application/vnd.github+json",
        "User-Agent": "rconfig-custom-readonly-drift",
        "X-GitHub-Api-Version": "2022-11-28",
    })
    token = os.environ.get("RCONFIG_DRIFT_READ_TOKEN")
    if token:
        request.add_header("Authorization", "Bearer " + token)
    with urllib.request.build_opener(NoRedirect()).open(request, timeout=20) as response:
        if response.geturl() != request.full_url:
            raise ValueError("Unexpected API redirect")
        data = response.read(8 * 1024 * 1024 + 1)
        if len(data) > 8 * 1024 * 1024:
            raise ValueError("API response exceeded report limit")
        return json.loads(data)


def resolve_tag(read, tag):
    obj = read(UPSTREAM, "git/ref/tags/" + core_tag(tag))["object"]
    for _ in range(5):
        digest = sha(obj["sha"])
        if obj["type"] == "commit":
            return digest
        if obj["type"] != "tag":
            raise ValueError("Stable release tag does not resolve to a commit")
        obj = read(UPSTREAM, "git/tags/" + digest)["object"]
    raise ValueError("Excessive tag indirection")


def git(directory, *args):
    env = dict(os.environ)
    env.update({"GIT_TERMINAL_PROMPT": "0", "GIT_CONFIG_NOSYSTEM": "1",
                "GIT_CONFIG_GLOBAL": os.devnull, "GIT_CONFIG_COUNT": "0"})
    for key in ("GIT_DIR", "GIT_WORK_TREE", "GIT_CONFIG", "GIT_CONFIG_PARAMETERS",
                "GIT_OBJECT_DIRECTORY", "GIT_ALTERNATE_OBJECT_DIRECTORIES"):
        env.pop(key, None)
    result = subprocess.run(
        ["git", "-C", directory, "-c", "credential.helper=", "-c", "http.followRedirects=false", *args],
        env=env, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
        text=True, check=True, timeout=90,
    )
    return result.stdout.strip()


def compare_git(directory, base, head):
    base, head = sha(base), sha(head)
    merge_base = sha(git(directory, "merge-base", base, head))
    behind, ahead = map(int, git(directory, "rev-list", "--left-right", "--count", base + "..." + head).split())
    status = ("diverged" if ahead and behind else "ahead" if ahead else
              "behind" if behind else "identical")
    if behind == 0 and merge_base != base:
        raise ValueError("Inconsistent ancestor evidence")
    return {"ahead": ahead, "behind": behind, "status": status, "merge_base": merge_base}


def snapshot(tag):
    # Fetch only named public refs into a disposable bare repository. No
    # checkout, hooks, submodules, persisted credentials or remote writes.
    with tempfile.TemporaryDirectory(prefix="rconfig-upstream-drift-") as directory:
        git(directory, "init", "--bare", "--template=")
        upstream_refs = ["+refs/heads/main:refs/check/upstream-main",
                         "+refs/tags/" + BASELINE_TAG + ":refs/check/baseline"]
        if tag != BASELINE_TAG:
            upstream_refs.append("+refs/tags/" + tag + ":refs/check/stable")
        git(directory, "fetch", "--no-tags", "--no-recurse-submodules",
            "https://github.com/" + UPSTREAM + ".git", *upstream_refs)
        git(directory, "fetch", "--no-tags", "--no-recurse-submodules",
            "https://github.com/" + FORK + ".git",
            "+refs/heads/main:refs/check/fork-main", "+refs/heads/custom:refs/check/custom")

        def commit(ref):
            return sha(git(directory, "rev-parse", "--verify", ref + "^{commit}"))

        baseline = commit("refs/check/baseline")
        if baseline != BASELINE_SHA:
            raise ValueError("Recorded baseline tag moved")
        stable = baseline if tag == BASELINE_TAG else commit("refs/check/stable")
        upstream_main = commit("refs/check/upstream-main")
        fork_main = commit("refs/check/fork-main")
        custom = commit("refs/check/custom")
        return (stable, upstream_main, fork_main, custom,
                compare_git(directory, upstream_main, fork_main),
                compare_git(directory, baseline, custom),
                compare_git(directory, stable, custom),
                compare_git(directory, stable, upstream_main))


def proposal(main, baseline, stable, main_contains_stable):
    if main["ahead"] != 0 or baseline["behind"] != 0 or not main_contains_stable:
        return "DEFER_INCONSISTENT_BASELINE_OR_FORK"
    if stable["behind"] != 0:
        return "DEFER_REVIEW_STABLE_INTEGRATION_PR"
    if main["behind"] != 0:
        return "DEFER_REVIEW_MAIN_FAST_FORWARD"
    return "NO_CHANGE"


def report(read=read_json, inspect=snapshot):
    release = read(UPSTREAM, "releases/latest")
    if release["draft"] is not False or release["prerelease"] is not False:
        raise ValueError("Unstable release")
    tag = core_tag(release["tag_name"])
    stable_api = resolve_tag(read, tag)
    (stable, upstream_main, fork_main, custom,
     main_delta, baseline_delta, stable_delta, stable_main_delta) = inspect(tag)
    if stable != stable_api:
        raise ValueError("Stable release tag changed between API and git fetch")
    decision = proposal(main_delta, baseline_delta, stable_delta, stable_main_delta["behind"] == 0)
    return {
        "baseline": {"tag": BASELINE_TAG, "sha": BASELINE_SHA},
        "latest_stable": {"tag": tag, "sha": stable},
        "upstream_main": upstream_main,
        "fork_main": fork_main,
        "custom": custom,
        "fork_vs_upstream_main": main_delta,
        "custom_vs_baseline": baseline_delta,
        "custom_vs_stable": stable_delta,
        "upstream_main_vs_stable": stable_main_delta,
        "proposal": decision,
        "automatic_apply": False,
        "review_required": ["changelog", "migration impact", "dependencies and licenses",
                            "security", "CI and staging", "rollback", "release approval"],
    }


def main():
    try:
        result = report()
    except urllib.error.HTTPError as error:
        print(f"UPSTREAM_DRIFT_REPORT_UNAVAILABLE: HTTP {error.code}; no change applied", file=sys.stderr)
        return 1
    except (OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError) as error:
        print(f"UPSTREAM_DRIFT_REPORT_UNAVAILABLE: {type(error).__name__}; no change applied", file=sys.stderr)
        return 1
    print(json.dumps(result, indent=2))
    if result["proposal"] == "DEFER_INCONSISTENT_BASELINE_OR_FORK":
        return 1
    print("UPSTREAM_READONLY_DRIFT_REPORT_OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
