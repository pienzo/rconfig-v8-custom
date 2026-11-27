<script setup>
import { ref } from "vue";
import DeviceMultiSelect from "@/pages/Shared/FormFields/DeviceMultiSelect.vue";
import ExternalBackupPatternPreview from "@/pages/Tasks/ExternalBackupPatternPreview.vue";
import { initialLocalPresenceDraft, localPresenceDraftFromRule, localPresencePayload } from "./localPresenceRule.js";

const props = defineProps({
    busy: Boolean,
    rule: { type: Object, default: null },
    error: { type: String, default: "" },
});
const emit = defineEmits(["save", "back", "cancel"]);
const draft = ref(props.rule ? localPresenceDraftFromRule(props.rule) : initialLocalPresenceDraft());
const selectedDevice = ref({});
const validation = ref("");
const numericFields = [
    { key: "minimum_bytes", label: "Minimum size (bytes)", min: 1, max: 1000000000000 },
    { key: "maximum_age_seconds", label: "Maximum age (seconds, optional)", min: 1, max: 31536000 },
    { key: "quiet_seconds", label: "Quiet period (seconds, optional)", min: 1, max: 3600 },
    { key: "deadline_seconds", label: "Observation deadline (seconds)", min: 60, max: 2678400 },
    { key: "max_entries", label: "Maximum directory entries", min: 1, max: 10000 },
    { key: "max_matches", label: "Maximum matching files", min: 1, max: 1000 },
    { key: "max_scan_millis", label: "Maximum scan time (ms)", min: 1, max: 10000 },
];
function save() {
    if (props.busy) return;
    validation.value = "";
    try {
        emit("save", localPresencePayload(draft.value, selectedDevice.value?.id, props.rule !== null));
    } catch (error) {
        validation.value = error.message;
    }
}
</script>

<template>
    <section aria-label="Local backup presence rule" class="min-w-0 text-foreground">
        <div class="max-h-[65dvh] space-y-4 overflow-y-auto p-5">
            <div>
                <h3 class="text-lg font-semibold">Local backup presence</h3>
                <p class="mt-1 text-sm text-muted-foreground">Check files deposited by a device into an operator-approved folder. No SSH download, no receipt or content validation.</p>
            </div>
            <fieldset :disabled="busy" class="min-w-0 space-y-4">
                <div class="grid gap-3 md:grid-cols-2">
                    <label class="block text-sm">Rule name
                        <input v-model="draft.task_name" maxlength="255" class="mt-1 block w-full rounded border bg-background p-2" autocomplete="off" />
                    </label>
                    <div class="min-w-0 text-sm">
                        <span class="mb-1 block">Device (one per rule)</span>
                        <p v-if="rule" class="rounded border bg-muted p-2">{{ rule.device?.device_name || "Unavailable device" }} (fixed)</p>
                        <DeviceMultiSelect v-else v-model="selectedDevice" single-select />
                    </div>
                    <label class="block text-sm">Approved root alias
                        <input v-model="draft.directory_alias" maxlength="64" class="mt-1 block w-full rounded border bg-background p-2 font-mono" autocomplete="off" placeholder="site_backups" />
                    </label>
                    <label class="block text-sm">Relative directory (optional)
                        <input v-model="draft.relative_directory" maxlength="4095" class="mt-1 block w-full rounded border bg-background p-2 font-mono" autocomplete="off" placeholder="router01" />
                    </label>
                    <label class="block text-sm">Match mode
                        <select v-model="draft.pattern_mode" class="mt-1 block w-full rounded border bg-background p-2">
                            <option value="glob">Glob</option>
                            <option value="regex">Regex</option>
                        </select>
                    </label>
                    <label class="block text-sm">Filename pattern (basename only)
                        <input v-model="draft.filename_pattern" maxlength="255" class="mt-1 block w-full rounded border bg-background p-2 font-mono" autocomplete="off" />
                    </label>
                    <label class="block text-sm md:col-span-2">Schedule (five cron fields, server timezone)
                        <input v-model="draft.task_cron_text" maxlength="255" class="mt-1 block w-full rounded border bg-background p-2 font-mono" autocomplete="off" />
                    </label>
                </div>
                <p class="text-xs text-muted-foreground">The alias identifies a mapping configured by the operator; it is not an absolute path. Saving does not validate mount availability. Cron uses the application timezone; recorded observations use UTC.</p>
                <details class="rounded border p-3">
                    <summary class="cursor-pointer text-sm font-medium">Test example filenames</summary>
                    <ExternalBackupPatternPreview :key="draft.pattern_mode + ':' + draft.filename_pattern"
                        :initial-mode="draft.pattern_mode" :initial-pattern="draft.filename_pattern" local-presence class="mt-3" />
                </details>
                <h4 class="text-sm font-semibold">Freshness, stability and scan limits</h4>
                <div class="grid gap-3 md:grid-cols-2">
                    <label v-for="field in numericFields" :key="field.key" class="block text-sm">
                        {{ field.label }}
                        <input v-model="draft[field.key]" type="number" step="1" :min="field.min" :max="field.max"
                            class="mt-1 block w-full rounded border bg-background p-2" />
                    </label>
                </div>
                <p class="text-xs text-muted-foreground">Leave maximum age blank to omit freshness filtering; leave quiet period blank to omit stability sampling. A present file is metadata evidence only.</p>
            </fieldset>
            <p class="rounded border bg-muted p-3 text-sm text-foreground">New rules are saved inactive. Scheduled checks and Verify Now require separate operator approval.</p>
            </div>
        <p v-if="validation || error" role="alert" class="border-t px-5 py-2 text-sm text-red-700 dark:text-red-400">{{ validation || error }}</p>
        <div class="flex flex-wrap justify-end gap-2 border-t p-3">
            <button v-if="!rule" type="button" class="rounded border px-3 py-2 text-sm" :disabled="busy" @click="emit('back')">Back to task types</button>
            <button type="button" class="rounded border px-3 py-2 text-sm" :disabled="busy" @click="emit('cancel')">Cancel</button>
            <button type="button" class="rounded bg-blue-700 px-3 py-2 text-sm text-white disabled:opacity-50" :disabled="busy" @click="save">
                {{ busy ? "Saving..." : (rule ? "Update inactive rule" : "Save inactive rule") }}
            </button>
        </div>
    </section>
</template>
