"""Read-only report for the approved public fork. Never pushes, merges or deploys."""

import json
import re
import sys
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


def read_json(repo, path):
    if repo not in {UPSTREAM, FORK} or not re.fullmatch(r"[A-Za-z0-9/_.?=&-]+", path):
        raise ValueError("Unexpected API target")
    request = urllib.request.Request(API + repo + "/" + path, headers={
        "Accept": "application/vnd.github+json",
        "User-Agent": "rconfig-custom-readonly-drift",
        "X-GitHub-Api-Version": "2022-11-28",
    })
    with urllib.request.urlopen(request, timeout=20) as response:
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


def compare(read, base, head):
    base, head = sha(base), sha(head)
    data = read(UPSTREAM, "compare/" + base + "..." + head + "?per_page=1")
    ahead, behind = data["ahead_by"], data["behind_by"]
    if type(ahead) is not int or type(behind) is not int or min(ahead, behind) < 0:
        raise ValueError("Invalid comparison counts")
    expected = ("diverged" if ahead and behind else "ahead" if ahead else
                "behind" if behind else "identical")
    if data["status"] != expected or sha(data["base_commit"]["sha"]) != base:
        raise ValueError("Unexpected comparison identity or status")
    merge_base = sha(data["merge_base_commit"]["sha"])
    if behind == 0 and merge_base != base:
        raise ValueError("Inconsistent ancestor evidence")
    return {"ahead": ahead, "behind": behind, "status": expected, "merge_base": merge_base}


def proposal(main, baseline, stable, main_contains_stable):
    if main["ahead"] != 0 or baseline["behind"] != 0 or not main_contains_stable:
        return "DEFER_INCONSISTENT_BASELINE_OR_FORK"
    if stable["behind"] != 0:
        return "DEFER_REVIEW_STABLE_INTEGRATION_PR"
    if main["behind"] != 0:
        return "DEFER_REVIEW_MAIN_FAST_FORWARD"
    return "NO_CHANGE"


def report(read=read_json):
    release = read(UPSTREAM, "releases/latest")
    if release["draft"] is not False or release["prerelease"] is not False:
        raise ValueError("Unstable release")
    tag = core_tag(release["tag_name"])
    stable = resolve_tag(read, tag)
    if resolve_tag(read, BASELINE_TAG) != BASELINE_SHA:
        raise ValueError("Recorded baseline tag moved")
    upstream_main = sha(read(UPSTREAM, "branches/main")["commit"]["sha"])
    fork_main = sha(read(FORK, "branches/main")["commit"]["sha"])
    custom = sha(read(FORK, "branches/custom")["commit"]["sha"])
    main_delta = compare(read, upstream_main, fork_main)
    baseline_delta = compare(read, BASELINE_SHA, custom)
    stable_delta = compare(read, stable, custom)
    stable_main_delta = compare(read, stable, upstream_main)
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
    except (OSError, ValueError, KeyError, TypeError):
        print("UPSTREAM_DRIFT_REPORT_UNAVAILABLE; no change applied", file=sys.stderr)
        return 1
    print(json.dumps(result, indent=2))
    if result["proposal"] == "DEFER_INCONSISTENT_BASELINE_OR_FORK":
        return 1
    print("UPSTREAM_READONLY_DRIFT_REPORT_OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
