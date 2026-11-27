// Local Presence is not a native download or a verified external receipt task.
export const LOCAL_PRESENCE_COMMAND = "rconfig:check-local-backup-presence";
export const LOCAL_PRESENCE_RULES = "/api/tasks/local-backup-presence-rules";

export function isManagedBackupTask(task) {
    return [LOCAL_PRESENCE_COMMAND, "rconfig:check-external-backup"].includes(task?.task_command);
}

export function initialLocalPresenceDraft() {
    return {
        task_name: "",
        task_cron_text: "0 2 * * *",
        directory_alias: "",
        relative_directory: "",
        pattern_mode: "glob",
        filename_pattern: "backup_*.cfg",
        minimum_bytes: 1,
        maximum_age_seconds: "",
        quiet_seconds: 30,
        deadline_seconds: 3600,
        max_entries: 1000,
        max_matches: 100,
        max_scan_millis: 2000,
    };
}

function integer(value, label, min, max, optional = false) {
    if (optional && (value === "" || value === null)) return null;
    if (typeof value !== "number" && (typeof value !== "string" || !/^\d+$/.test(value))) {
        throw new Error(label + " must be a whole number.");
    }
    const number = Number(value);
    if (!Number.isSafeInteger(number) || number < min || number > max) {
        throw new Error(label + " must be between " + min + " and " + max + ".");
    }
    return number;
}

// Explicit allowlist and JSON integer normalization. PHP remains authoritative
// for cron, regex, path syntax and all permission / lifecycle checks.
export function localPresencePayload(draft, deviceId, editing = false) {
    const name = draft.task_name.trim();
    const cron = draft.task_cron_text.trim().split(/\s+/);
    const alias = draft.directory_alias;
    const directory = draft.relative_directory;
    if (name.length < 3 || name.length > 255) throw new Error("Enter a rule name (3–255 characters).");
    if (cron.length !== 5) throw new Error("Enter exactly five cron fields (UTC).");
    if (!/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/.test(alias)) throw new Error("Enter an approved root alias.");
    if (directory !== "" && (directory.length > 4095 || directory.includes("\\") ||
        directory.split("/").length > 32 || directory.split("/").some(part => !part || part === "." || part === ".."))) {
        throw new Error("Enter a relative directory without traversal or empty components.");
    }
    if (!["glob", "regex"].includes(draft.pattern_mode) || !draft.filename_pattern || draft.filename_pattern.length > 255) {
        throw new Error("Enter a glob or regex filename pattern.");
    }
    const payload = {
        task_name: name,
        task_cron: cron,
        directory_alias: alias,
        relative_directory: directory,
        pattern_mode: draft.pattern_mode,
        filename_pattern: draft.filename_pattern,
        minimum_bytes: integer(draft.minimum_bytes, "Minimum bytes", 1, 1000000000000),
        maximum_age_seconds: integer(draft.maximum_age_seconds, "Maximum age", 1, 31536000, true),
        quiet_seconds: integer(draft.quiet_seconds, "Quiet period", 1, 3600, true),
        deadline_seconds: integer(draft.deadline_seconds, "Deadline", 60, 2678400),
        max_entries: integer(draft.max_entries, "Maximum entries", 1, 10000),
        max_matches: integer(draft.max_matches, "Maximum matches", 1, 1000),
        max_scan_millis: integer(draft.max_scan_millis, "Scan time", 1, 10000),
    };
    if (payload.quiet_seconds !== null && payload.quiet_seconds >= payload.deadline_seconds) {
        throw new Error("Quiet period must be shorter than the deadline.");
    }
    if (payload.max_matches > payload.max_entries) throw new Error("Maximum matches cannot exceed maximum entries.");
    if (!editing) payload.device_id = integer(deviceId, "Device", 1, Number.MAX_SAFE_INTEGER);
    return payload;
}
