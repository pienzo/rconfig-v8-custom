# Local presence optional stability (#204)

Independent of the pending schema PR #203. Pure policy on the accepted #201 base.
When quiet time is null, preserve the existing presence semantics. Otherwise
require two bounded scanner observations, a minimum elapsed interval, and
unchanged basename/device/inode/size/mtime/ctime. Reject incomplete metadata
and invalid observation times. Evaluate freshness and minimum size at the
later instant. A newer unstable match does not mask another eligible stable
match. Pending means stability evidence is not yet available; absent means
no eligible file at the observation, not necessarily a terminal failed task.

The lifecycle layer must persist and bind observations to the same rule
revision, authorized directory identity, occurrence and trusted UTC clock;
no caller-supplied filesystem evidence or arbitrary paths. It must implement
deadline, restart/replay, edit/pause and terminal state transitions separately.
Metadata stability is not proof of upload completion, origin, integrity,
restorability or an atomic safe-open receipt. Keep strict receipt mode intact.
No API, queue, scheduler, database, activation or deployment changes here.
Rollback: revert this code-only PR. CI and subsequent integration pending.
