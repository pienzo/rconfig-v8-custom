import { describe, expect, it } from "vitest";
import { initialLocalPresenceDraft, isManagedBackupTask, localPresencePayload, LOCAL_PRESENCE_COMMAND } from "../localPresenceRule.js";

function draft(changes = {}) {
    return { ...initialLocalPresenceDraft(), task_name: "Synthetic backup rule", directory_alias: "synthetic", ...changes };
}

describe("Local Presence explicit payload", () => {
    it("normalizes integers and optional values without allowing native flags or hidden properties", () => {
        const payload = localPresencePayload(draft({
            minimum_bytes: "10", quiet_seconds: "", maximum_age_seconds: "", task_command: "rconfig:download-device",
            enabled: true, task_email_notify: true, root: "/private", session_id: "ignored",
        }), "5");
        expect(payload).toEqual({
            task_name: "Synthetic backup rule", task_cron: ["0", "2", "*", "*", "*"],
            directory_alias: "synthetic", relative_directory: "", pattern_mode: "glob",
            filename_pattern: "backup_*.cfg", minimum_bytes: 10, maximum_age_seconds: null,
            quiet_seconds: null, deadline_seconds: 3600, max_entries: 1000, max_matches: 100,
            max_scan_millis: 2000, device_id: 5,
        });
    });

    it("does not transmit a device change when editing", () => {
        expect(localPresencePayload(draft(), 5, true)).not.toHaveProperty("device_id");
    });

    it.each([
        { task_name: "" }, { task_cron_text: "* *" }, { directory_alias: "/absolute" },
        { relative_directory: "../backup" }, { relative_directory: "/absolute" },
        { relative_directory: "one//two" }, { relative_directory: "one\\two" },
        { minimum_bytes: "" }, { minimum_bytes: true }, { minimum_bytes: 1.5 },
        { minimum_bytes: "1e2" }, { maximum_age_seconds: 0 }, { quiet_seconds: 60, deadline_seconds: 60 },
        { max_matches: 100, max_entries: 10 }, { max_scan_millis: 10001 }, { pattern_mode: "shell" },
        { filename_pattern: "" },
    ])("refuses invalid draft %j", changes => {
        expect(() => localPresencePayload(draft(changes), 5)).toThrow();
    });

    it.each([undefined, null, {}, 0, -1, "1.5"])("refuses invalid device %j", device => {
        expect(() => localPresencePayload(draft(), device)).toThrow();
    });

    it("distinguishes managed tasks from native commands", () => {
        expect(isManagedBackupTask({ task_command: LOCAL_PRESENCE_COMMAND })).toBe(true);
        expect(isManagedBackupTask({ task_command: "rconfig:check-external-backup" })).toBe(true);
        expect(isManagedBackupTask({ task_command: "rconfig:download-device" })).toBe(false);
        expect(isManagedBackupTask(null)).toBe(false);
    });
});
