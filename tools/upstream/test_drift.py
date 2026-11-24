import unittest

from drift import BASELINE_SHA, BASELINE_TAG, FORK, UPSTREAM, compare, proposal, report, resolve_tag

A = BASELINE_SHA
B = "b" * 40
C = "c" * 40
T = "d" * 40


def delta(ahead, behind, base):
    return {
        "ahead_by": ahead, "behind_by": behind,
        "status": "diverged" if ahead and behind else "ahead" if ahead else "behind" if behind else "identical",
        "base_commit": {"sha": base},
        "merge_base_commit": {"sha": base if not behind else A},
    }


class DriftTest(unittest.TestCase):
    def test_annotated_tag_and_invalid_object(self):
        def read(repo, path):
            self.assertEqual(repo, UPSTREAM)
            if path.startswith("git/ref/"):
                return {"object": {"type": "tag", "sha": T}}
            self.assertEqual(path, "git/tags/" + T)
            return {"object": {"type": "commit", "sha": A}}
        self.assertEqual(resolve_tag(read, BASELINE_TAG), A)
        with self.assertRaises(ValueError):
            resolve_tag(read, "core-8.3.1-rc1")
        with self.assertRaises(ValueError):
            resolve_tag(lambda *_: {"object": {"type": "tree", "sha": A}}, BASELINE_TAG)

    def test_proposal_never_auto_applies(self):
        clean = {"ahead": 0, "behind": 0}
        contains = {"ahead": 100, "behind": 0}
        self.assertEqual(proposal(clean, contains, contains, True), "NO_CHANGE")
        self.assertEqual(proposal(clean, contains, {"ahead": 100, "behind": 2}, True),
                         "DEFER_REVIEW_STABLE_INTEGRATION_PR")
        self.assertEqual(proposal({"ahead": 0, "behind": 1}, contains, contains, True),
                         "DEFER_REVIEW_MAIN_FAST_FORWARD")
        self.assertEqual(proposal({"ahead": 1, "behind": 0}, contains, contains, True),
                         "DEFER_INCONSISTENT_BASELINE_OR_FORK")
        self.assertEqual(proposal(clean, {"ahead": 0, "behind": 1}, contains, True),
                         "DEFER_INCONSISTENT_BASELINE_OR_FORK")
        self.assertEqual(proposal(clean, contains, contains, False), "DEFER_INCONSISTENT_BASELINE_OR_FORK")

    def test_current_baseline_report(self):
        def read(repo, path):
            data = {
                (UPSTREAM, "releases/latest"): {"tag_name": BASELINE_TAG, "draft": False, "prerelease": False},
                (UPSTREAM, "git/ref/tags/" + BASELINE_TAG): {"object": {"type": "commit", "sha": A}},
                (UPSTREAM, "branches/main"): {"commit": {"sha": A}},
                (FORK, "branches/main"): {"commit": {"sha": A}},
                (FORK, "branches/custom"): {"commit": {"sha": B}},
                (UPSTREAM, f"compare/{A}...{A}?per_page=1"): delta(0, 0, A),
                (UPSTREAM, f"compare/{A}...{B}?per_page=1"): delta(100, 0, A),
            }
            return data[repo, path]
        result = report(read)
        self.assertEqual(result["proposal"], "NO_CHANGE")
        self.assertFalse(result["automatic_apply"])
        self.assertEqual(result["custom"], B)

    def test_comparison_rejects_inconsistent_response(self):
        with self.assertRaises(ValueError):
            compare(lambda *_: delta(0, 0, C), A, B)
        wrong = delta(1, 0, A)
        wrong["merge_base_commit"]["sha"] = C
        with self.assertRaises(ValueError):
            compare(lambda *_: wrong, A, B)


if __name__ == "__main__":
    unittest.main()
