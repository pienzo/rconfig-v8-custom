<script setup>
import axios from "axios";
import { onMounted, onBeforeUnmount, ref } from "vue";
import LocalPresenceRuleForm from "./LocalPresenceRuleForm.vue";
import { LOCAL_PRESENCE_RULES, canEditLocalPresence } from "./localPresenceRule.js";

const emit = defineEmits(["changed"]);
const rows = ref([]);
const page = ref(1);
const lastPage = ref(1);
const loading = ref(false);
const busy = ref(false);
const denied = ref(false);
const activationAvailable = ref(false);
const manualAvailable = ref(false);
const editing = ref(null);
const confirmation = ref(null);
const error = ref("");
const info = ref("");
const historyRule = ref(null);
const historyRows = ref([]);
const historyPage = ref(1);
const historyLastPage = ref(1);
const historyLoading = ref(false);
const historyError = ref("");
let alive = true;
let listVersion = 0;
let historyVersion = 0;

function message(exception) {
    const status = exception.response?.status;
    if (status === 403 || status === 401) return "Administrator access is required.";
    if (status === 422) return "Rule validation failed. Check the name, schedule, directory, pattern and limits.";
    if (status === 409) return "Change refused by the server. Check rule state, pending checks, history and operator approval.";
    if (status === 404) return "Rule is no longer available. Refresh the list.";
    return "Unable to confirm the operation. Refresh before retrying.";
}

async function load(target = 1) {
    const version = ++listVersion;
    loading.value = true;
    error.value = "";
    try {
        const response = await axios.get(LOCAL_PRESENCE_RULES, { params: { page: target, per_page: 20 } });
        if (!alive || version !== listVersion) return;
        const data = response.data;
        if (data.current_page > data.last_page) return await load(data.last_page);
        rows.value = data.data;
        page.value = data.current_page;
        lastPage.value = data.last_page;
        activationAvailable.value = data.activation_available === true;
        manualAvailable.value = data.manual_available === true;
    } catch (exception) {
        if (!alive || version !== listVersion) return;
        rows.value = [];
        activationAvailable.value = false;
        manualAvailable.value = false;
        denied.value = [401, 403].includes(exception.response?.status);
        error.value = message(exception);
    } finally {
        if (alive && version === listVersion) loading.value = false;
    }
}

function closeHistory() {
    historyVersion++;
    historyRule.value = null;
    historyRows.value = [];
    historyLoading.value = false;
    historyError.value = "";
}

async function loadHistory(rule, target = 1) {
    const version = ++historyVersion;
    historyRule.value = rule;
    historyLoading.value = true;
    historyError.value = "";
    historyRows.value = [];
    try {
        const response = await axios.get(LOCAL_PRESENCE_RULES + "/" + rule.id + "/runs", {
            params: { page: target, per_page: 10 },
        });
        if (!alive || version !== historyVersion) return;
        historyRows.value = response.data.data;
        historyPage.value = response.data.current_page;
        historyLastPage.value = response.data.last_page;
    } catch (exception) {
        if (alive && version === historyVersion) historyError.value = message(exception);
    } finally {
        if (alive && version === historyVersion) historyLoading.value = false;
    }
}

function edit(rule) {
    if (busy.value || !canEditLocalPresence(rule)) return;
    confirmation.value = null;
    closeHistory();
    error.value = "";
    info.value = "";
    editing.value = rule;
}
function cancelEdit() {
    if (busy.value) return;
    editing.value = null;
    error.value = "";
}
function ask(rule, action) {
    if (busy.value || editing.value || (action === "delete" && !canEditLocalPresence(rule))
        || (action === "enable" && (!activationAvailable.value || rule.enabled))
        || (action === "disable" && !rule.enabled)) return;
    closeHistory();
    error.value = "";
    info.value = "";
    confirmation.value = { rule, action };
}

async function mutate(action, rule, payload) {
    if (busy.value || !alive || denied.value) return;
    busy.value = true;
    error.value = "";
    info.value = "";
    try {
        const url = LOCAL_PRESENCE_RULES + "/" + rule.id;
        if (action === "edit") await axios.patch(url, payload);
        else if (action === "delete") await axios.delete(url);
        else await axios.patch(url + "/activation", { enabled: action === "enable" });
        if (!alive) return;
        editing.value = null;
        confirmation.value = null;
        closeHistory();
        info.value = action === "edit" ? "Inactive rule updated."
            : action === "delete" ? "Inactive rule deleted."
            : action === "enable" ? "Rule activated for future scheduled checks." : "Rule suspended.";
        emit("changed");
        await load(page.value);
    } catch (exception) {
        if (alive) error.value = message(exception);
    } finally {
        if (alive) busy.value = false;
    }
}

function saveEdit(payload) {
    if (editing.value && canEditLocalPresence(editing.value)) return mutate("edit", editing.value, payload);
}
function confirmChange() {
    if (confirmation.value) return mutate(confirmation.value.action, confirmation.value.rule);
}

onMounted(() => load());
onBeforeUnmount(() => {
    alive = false;
    listVersion++;
    historyVersion++;
});
</script>

<template>
    <section aria-label="Local presence rules" class="rounded-md border p-4 text-left text-foreground">
        <h3 class="text-lg font-semibold">Local backup presence rules</h3>
        <p class="mt-1 text-sm text-muted-foreground">Metadata presence only. A present file is not a verified backup or receipt. Create new rules using New Task.</p>
        <p class="mt-2 text-sm text-muted-foreground">Scheduled activation: {{ activationAvailable ? "Approved" : "Not approved" }} · Verify Now: {{ manualAvailable ? "Approved" : "Not approved" }} (configuration only).</p>
        <button type="button" class="mt-3 rounded border px-3 py-1 text-sm" :disabled="busy || loading || !!editing || !!confirmation"
            @click="load(page)">Refresh rules</button>
        <p v-if="error && !editing" role="alert" class="mt-2 text-sm text-red-700 dark:text-red-400">{{ error }}</p>
        <p v-if="info" role="status" class="mt-2 text-sm text-green-800 dark:text-green-400">{{ info }}</p>
        <p v-if="loading" role="status" class="mt-2 text-sm">Loading rules...</p>
        <p v-else-if="!rows.length && !error" class="mt-3 text-sm text-muted-foreground">No Local Presence rules configured.</p>
        <ul v-else-if="!denied" class="mt-3 space-y-3" aria-label="Configured Local Presence rules">
            <li v-for="row in rows" :key="row.id" class="rounded border p-3 text-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h4 class="font-semibold">{{ row.task?.task_name }}</h4>
                        <p>{{ row.device?.device_name }} · Rule {{ row.id }} · Task {{ row.task_id }}</p>
                        <p class="break-all font-mono text-xs">{{ row.directory_alias }} / {{ row.relative_directory || "(root)" }}</p>
                        <p class="break-all font-mono text-xs">{{ row.pattern_mode }}: {{ row.filename_pattern }}</p>
                        <p class="text-xs text-muted-foreground">Cron: {{ row.task?.task_cron?.join(" ") }} (server timezone)</p>
                        <p :class="row.enabled ? 'text-green-800 dark:text-green-400' : 'text-amber-800 dark:text-amber-400'">
                            {{ row.enabled ? "ACTIVE" : (row.activated_at_utc ? "SUSPENDED" : "INACTIVE DRAFT") }}
                        </p>
                        <p v-if="!canEditLocalPresence(row)" class="text-xs text-muted-foreground">Previously activated rules cannot be edited or deleted.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="rounded border px-2 py-1" :disabled="busy || loading || !!editing || !!confirmation || !canEditLocalPresence(row)" @click="edit(row)">Edit</button>
                        <button type="button" class="rounded border px-2 py-1 text-red-700 dark:text-red-400" :disabled="busy || loading || !!editing || !!confirmation || !canEditLocalPresence(row)" @click="ask(row, 'delete')">Delete</button>
                        <button type="button" class="rounded border px-2 py-1" :disabled="busy || loading || !!editing || !!confirmation || (!row.enabled && !activationAvailable)"
                            @click="ask(row, row.enabled ? 'disable' : 'enable')">{{ row.enabled ? "Suspend" : "Activate" }}</button>
                        <button type="button" class="rounded border px-2 py-1" :disabled="busy || loading || !!editing || !!confirmation"
                            @click="historyRule?.id === row.id ? closeHistory() : loadHistory(row)">{{ historyRule?.id === row.id ? "Close history" : "History" }}</button>
                    </div>
                </div>
                <div v-if="confirmation?.rule.id === row.id" class="mt-3 space-y-2 rounded border bg-muted p-3" role="group" aria-label="Confirm rule change">
                    <p>{{ confirmation.action === "delete" ? "Delete this never-activated rule and its linked task?" : confirmation.action === "enable" ? "Activate future scheduled metadata checks? Confirm operator approval and invalidate any manual diagnostic." : "Suspend future scheduled checks? Pending observations may prevent this change." }}</p>
                    <button type="button" class="mr-2 rounded border bg-background px-3 py-1" :disabled="busy" @click="confirmation = null">Cancel</button>
                    <button type="button" class="rounded bg-blue-700 px-3 py-1 text-white" :disabled="busy" @click="confirmChange">Confirm {{ confirmation.action === "enable" ? "activation" : confirmation.action === "disable" ? "suspension" : "deletion" }}</button>
                </div>
                <LocalPresenceRuleForm v-if="editing?.id === row.id" :key="row.id" :rule="editing" :busy="busy" :error="error"
                    class="mt-3 rounded border" @save="saveEdit" @cancel="cancelEdit" />
                <div v-if="historyRule?.id === row.id" aria-label="Scheduled presence history" class="mt-3 rounded border bg-muted p-3">
                    <h5 class="font-semibold">Scheduled presence history</h5>
                    <p class="text-xs text-muted-foreground">UTC timestamps · metadata evidence only.</p>
                    <p v-if="historyLoading" role="status">Loading history...</p>
                    <p v-else-if="historyError" role="alert" class="text-red-700 dark:text-red-400">{{ historyError }}</p>
                    <p v-else-if="!historyRows.length">No scheduled observations recorded.</p>
                    <ul v-else class="mt-2 space-y-2">
                        <li v-for="run in historyRows" :key="run.id" class="rounded border p-2 text-xs">
                            <strong>{{ String(run.status).toUpperCase() }}</strong> · {{ run.reason || "—" }}
                            <p>Scheduled: {{ run.scheduled_at_utc || "—" }} · Deadline: {{ run.deadline_at_utc || "—" }}</p>
                            <p>Last observed: {{ run.last_observed_at_utc || "—" }}</p>
                            <p v-if="run.matched_filename" class="break-all font-mono">File: {{ run.matched_filename }}</p>
                        </li>
                    </ul>
                    <div v-if="historyLastPage > 1" class="mt-2 flex items-center gap-2">
                        <button type="button" :disabled="historyLoading || historyPage <= 1" @click="loadHistory(row, historyPage - 1)">Previous history</button>
                        <span>{{ historyPage }} / {{ historyLastPage }}</span>
                        <button type="button" :disabled="historyLoading || historyPage >= historyLastPage" @click="loadHistory(row, historyPage + 1)">Next history</button>
                    </div>
                </div>
            </li>
        </ul>
        <div v-if="lastPage > 1" class="mt-3 flex items-center gap-3 text-sm">
            <button type="button" class="rounded border px-2 py-1" :disabled="loading || busy || !!editing || !!confirmation || page <= 1" @click="closeHistory(); load(page - 1)">Previous page</button>
            <span>{{ page }} / {{ lastPage }}</span>
            <button type="button" class="rounded border px-2 py-1" :disabled="loading || busy || !!editing || !!confirmation || page >= lastPage" @click="closeHistory(); load(page + 1)">Next page</button>
        </div>
    </section>
</template>
