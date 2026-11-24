import os
import tempfile
import unittest

from drift import BASELINE_SHA, BASELINE_TAG, UPSTREAM, compare_git, git, proposal, report, resolve_tag

A = BASELINE_SHA
B = "b" * 40
C = "c" * 40
T = "d" * 40


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
            }
            return data[repo, path]
        clean = {"ahead": 0, "behind": 0, "status": "identical", "merge_base": A}
        contains = {"ahead": 100, "behind": 0, "status": "ahead", "merge_base": A}
        result = report(read, lambda tag: (A, A, A, B, clean, contains, contains, clean))
        self.assertEqual(result["proposal"], "NO_CHANGE")
        self.assertFalse(result["automatic_apply"])
        self.assertEqual(result["custom"], B)

    def test_real_git_ancestry_on_disposable_synthetic_history(self):
        with tempfile.TemporaryDirectory() as directory:
            git(directory, "init", "--bare", "--template=")
            tree = git(directory, "hash-object", "-t", "tree", "-w", os.devnull)
            def commit(parent=None):
                args = ["-c", "user.name=CI", "-c", "user.email=ci@example.invalid",
                        "commit-tree", tree, "-m", "synthetic " + ("child" if parent else "root")]
                if parent:
                    args += ["-p", parent]
                return git(directory, *args)
            root = commit()
            child = commit(root)
            self.assertEqual(compare_git(directory, root, child),
                             {"ahead": 1, "behind": 0, "status": "ahead", "merge_base": root})
            self.assertEqual(compare_git(directory, child, root)["behind"], 1)
            self.assertEqual(compare_git(directory, root, root)["status"], "identical")

    def test_tag_change_between_api_and_git_fails_closed(self):
        def read(repo, path):
            if path == "releases/latest":
                return {"tag_name": BASELINE_TAG, "draft": False, "prerelease": False}
            return {"object": {"type": "commit", "sha": A}}
        with self.assertRaises(ValueError):
            report(read, lambda tag: (B, A, A, B, {}, {}, {}, {}))


if __name__ == "__main__":
    unittest.main()
