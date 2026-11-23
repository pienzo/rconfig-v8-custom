<script setup>
import axios from "axios";
import { onMounted, ref } from "vue";
import DeviceMultiSelect from "@/pages/Shared/FormFields/DeviceMultiSelect.vue";

const selectedDevice = ref({});
const rows = ref([]);
const loading = ref(false);
const saving = ref(false);
const errorMessage = ref("");
const infoMessage = ref("");
const editingId = ref(null);
const currentPage = ref(1);
const lastPage = ref(1);
const previewRuleId = ref(null);
const previewLoading = ref(false);
const previewError = ref("");
const previewData = ref(null);
const probeState = ref({});
const probeLoadingId = ref(null);

async function verifyNow(rule) {
    probeLoadingId.value = rule.id;
    const existing = probeState.value[rule.id];
    try {
        const response = await axios.post("/api/tasks/external-backup-rules/" + rule.id + "/verify-now", {
            checkpoint: existing?.checkpoint || null,
        });
        probeState.value[rule.id] = response.data;
    } catch (error) {
        probeState.value[rule.id] = {
            status: "error",
            reason: error.response?.data?.message || "Verification request failed.",
            checkpoint: null,
        };
    } finally {
        probeLoadingId.value = null;
    }
}


async function previewFiles(rule) {
    previewRuleId.value = rule.id;
    previewLoading.value = true;
    previewError.value = "";
    previewData.value = null;
    try {
        const response = await axios.get(`/api/tasks/external-backup-rules/${rule.id}/files-preview`);
        previewData.value = response.data;
    } catch (error) {
        previewError.value = error.response?.data?.message || "Cannot inspect the configured inbox.";
    } finally {
        previewLoading.value = false;
    }
}

function initial() {
    return {
        task_name: "",
        task_cron_text: "0 2 * * *",
        pattern_mode: "glob",
        filename_pattern: "backup_*.cfg",
        minimum_bytes: 1,
        quiet_seconds: 30,
        deadline_seconds: 3600,
    };
}
const form = ref(initial());

async function load(page = 1) {
    loading.value = true;
    errorMessage.value = "";
    try {
        const response = await axios.get("/api/tasks/external-backup-rules", { params: { page, per_page: 20 } });
        rows.value = response.data.data;
        currentPage.value = response.data.current_page;
        lastPage.value = response.data.last_page;
    } catch {
        errorMessage.value = "Unable to load external backup rules.";
    } finally {
        loading.value = false;
    }
}

function edit(rule) {
    editingId.value = rule.id;
    selectedDevice.value = rule.device;
    form.value = {
        task_name: rule.task.task_name,
        task_cron_text: rule.task.task_cron.join(" "),
        pattern_mode: rule.pattern_mode,
        filename_pattern: rule.filename_pattern,
        minimum_bytes: rule.minimum_bytes,
        quiet_seconds: rule.quiet_seconds,
        deadline_seconds: rule.deadline_seconds,
    };
    errorMessage.value = "";
    infoMessage.value = "";
}

function reset() {
    editingId.value = null;
    selectedDevice.value = {};
    form.value = initial();
}

async function save() {
    errorMessage.value = "";
    infoMessage.value = "";
    const cron = form.value.task_cron_text.trim().split(/\s+/);
    if (cron.length !== 5) {
        errorMessage.value = "The schedule must contain five cron fields.";
        return;
    }
    const payload = {
        task_name: form.value.task_name,
        task_cron: cron,
        pattern_mode: form.value.pattern_mode,
        filename_pattern: form.value.filename_pattern,
        minimum_bytes: Number(form.value.minimum_bytes),
        quiet_seconds: Number(form.value.quiet_seconds),
        deadline_seconds: Number(form.value.deadline_seconds),
    };
    if (!editingId.value) {
        payload.device_id = selectedDevice.value?.id;
    }
    saving.value = true;
    try {
        if (editingId.value) {
            await axios.patch(`/api/tasks/external-backup-rules/${editingId.value}`, payload);
        } else {
            await axios.post("/api/tasks/external-backup-rules", payload);
        }
        reset();
        infoMessage.value = "Rule saved. Execution remains disabled until the F02 checker is connected.";
        await load();
    } catch (error) {
        const details = error.response?.data?.errors;
        errorMessage.value = details
            ? Object.values(details).flat().join(" ")
            : (error.response?.data?.message || "Cannot save this rule.");
    } finally {
        saving.value = false;
    }
}

async function remove(rule) {
    if (!window.confirm(`Delete the inactive external backup rule for ${rule.device?.device_name || "this device"}?`)) return;
    errorMessage.value = "";
    try {
        await axios.delete(`/api/tasks/external-backup-rules/${rule.id}`);
        if (editingId.value === rule.id) reset();
        infoMessage.value = "Rule deleted.";
        await load(currentPage.value);
    } catch (error) {
        errorMessage.value = error.response?.data?.message || "Cannot delete this rule.";
    }
}

onMounted(() => load());
</script>

<template>
    <section class="rounded-md border border-gray-300 p-4 text-left dark:border-gray-700" aria-label="External backup rules">
        <h3 class="text-lg font-semibold">External filesystem backup checks</h3>
        <p class="mb-3 text-sm text-muted-foreground">
            Configure a device backup filename and schedule. These rules are saved inactive until the F02 job is ready;
            no remote command or file check will run yet.
        </p>
        <div class="grid gap-3 md:grid-cols-2">
            <label class="block text-sm">Rule name
                <input v-model="form.task_name" maxlength="255" class="mt-1 block w-full rounded border p-2 bg-background" placeholder="Daily firewall backup" />
            </label>
            <div class="text-sm">
                <span>Device (one per rule)</span>
                <div v-if="editingId" class="mt-2 text-sm">{{ selectedDevice?.device_name }} (fixed for this rule)</div>
                <DeviceMultiSelect v-else v-model="selectedDevice" single-select />
            </div>
            <label class="block text-sm">Schedule (5-field cron, stored only)
                <input v-model="form.task_cron_text" class="mt-1 block w-full rounded border p-2 font-mono bg-background" placeholder="0 2 * * *" />
            </label>
            <label class="block text-sm">Pattern mode
                <select v-model="form.pattern_mode" class="mt-1 block w-full rounded border p-2 bg-background">
                    <option value="glob">Glob</option>
                    <option value="regex">Regex</option>
                </select>
            </label>
            <label class="block text-sm">Filename pattern (basename only)
                <input v-model="form.filename_pattern" maxlength="255" class="mt-1 block w-full rounded border p-2 font-mono bg-background" placeholder="backup_*.cfg" />
            </label>
            <label class="block text-sm">Minimum file size (bytes)
                <input v-model.number="form.minimum_bytes" type="number" min="1" max="1000000000000" class="mt-1 block w-full rounded border p-2 bg-background" />
            </label>
            <label class="block text-sm">Quiet period (seconds)
                <input v-model.number="form.quiet_seconds" type="number" min="1" max="3600" class="mt-1 block w-full rounded border p-2 bg-background" />
            </label>
            <label class="block text-sm">Deadline (seconds)
                <input v-model.number="form.deadline_seconds" type="number" min="60" max="2678400" class="mt-1 block w-full rounded border p-2 bg-background" />
            </label>
        </div>
        <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" :disabled="saving" class="rounded bg-blue-600 px-3 py-2 text-sm text-white disabled:opacity-50" @click="save">
                {{ saving ? "Saving..." : (editingId ? "Update inactive rule" : "Save inactive rule") }}
            </button>
            <button v-if="editingId" type="button" class="rounded border px-3 py-2 text-sm" @click="reset">Cancel edit</button>
        </div>
        <p v-if="errorMessage" class="mt-2 text-sm text-red-500" role="alert">{{ errorMessage }}</p>
        <p v-if="infoMessage" class="mt-2 text-sm text-green-600" role="status">{{ infoMessage }}</p>
        <h4 class="mt-5 font-semibold">Configured rules</h4>
        <div v-if="loading" class="text-sm">Loading...</div>
        <ul v-else class="mt-2 space-y-2">
            <li v-for="row in rows" :key="row.id" class="flex flex-wrap items-center justify-between gap-3 rounded border p-2 text-xs">
                <div>
                    <strong>{{ row.task?.task_name }}</strong> — {{ row.device?.device_name }}
                    <div class="font-mono break-all">{{ row.pattern_mode }}: {{ row.filename_pattern }}</div>
                    <span class="text-amber-600 dark:text-amber-400">INACTIVE · no file verification</span>
                </div>
                <div class="flex gap-2">
                    <button type="button" class="rounded border px-2 py-1" @click="edit(row)">Edit</button>
                    <button type="button" class="rounded border px-2 py-1" @click="previewFiles(row)">Preview files</button>
                    <button type="button" class="rounded border px-2 py-1"
                        :disabled="probeLoadingId === row.id" @click="verifyNow(row)">
                        {{ probeLoadingId === row.id ? "Verifica..." : (probeState[row.id]?.checkpoint ? "Ricontrolla stabilità" : "Verifica ora") }}
                    </button>
                    <button type="button" class="rounded border px-2 py-1 text-red-500" @click="remove(row)">Delete</button>
                </div>
                <div v-if="probeState[row.id]" class="w-full rounded bg-muted p-2 text-xs" aria-live="polite">
                    <p class="font-semibold">Valutazione preliminare: {{ probeState[row.id].status }}</p>
                    <p>Motivo: {{ probeState[row.id].reason }} <span v-if="probeState[row.id].filename">({{ probeState[row.id].filename }})</span></p>
                    <p v-if="probeState[row.id].checkpoint">Attendi {{ probeState[row.id].retry_after_seconds }} secondi prima di ricontrollare.</p>
                    <p v-if="probeState[row.id].window_start_utc">Finestra manuale: {{ probeState[row.id].window_start_utc }} → {{ probeState[row.id].window_end_utc }}</p>
                    <p class="text-amber-600 dark:text-amber-400">
                        Nessun SUCCESS registrato: anche un file stabile richiede la verifica sicura del contenuto e della ricevuta.
                    </p>
                </div>
                <div v-if="previewRuleId === row.id" class="w-full rounded bg-muted p-2" aria-live="polite">
                    <p v-if="previewLoading">Reading filenames...</p>
                    <p v-else-if="previewError" class="text-red-500" role="alert">{{ previewError }}</p>
                    <template v-else-if="previewData">
                        <p class="font-semibold">Metadata only — backup completion and validity NOT verified.</p>
                        <p v-if="!previewData.directory_present">Device folder not present.</p>
                        <p v-else>Inspected {{ previewData.scanned_entries }} entries.</p>
                        <ul class="mt-1 space-y-1">
                            <li v-for="file in previewData.files" :key="file.filename" class="break-all font-mono">
                                {{ file.filename }} ({{ file.size_bytes }} bytes · {{ file.mtime_utc }})
                            </li>
                            <li v-if="previewData.files.length === 0">No matching regular files.</li>
                        </ul>
                    </template>
                </div>
            </li>
            <li v-if="rows.length === 0" class="text-sm text-muted-foreground">No external backup rules configured.</li>
        </ul>
        <div v-if="lastPage > 1" class="mt-3 flex gap-2">
            <button type="button" class="rounded border px-2 py-1 text-sm" :disabled="currentPage <= 1" @click="load(currentPage - 1)">Previous</button>
            <span class="text-sm">{{ currentPage }} / {{ lastPage }}</span>
            <button type="button" class="rounded border px-2 py-1 text-sm" :disabled="currentPage >= lastPage" @click="load(currentPage + 1)">Next</button>
        </div>
    </section>
</template>
