<script setup>
import axios from "axios";
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { LOCAL_PRESENCE_RULES } from "./localPresenceRule.js";

const props = defineProps({ ruleId: { type: Number, required: true }, available: Boolean });
const emit = defineEmits(["close"]);
const session = ref(null);
const busy = ref(false);
const error = ref("");
const expired = ref(false);
const stopped = ref(false);
const recoverByStart = ref(false);
const terminal = computed(() => ["present", "absent", "error"].includes(session.value?.status));
const labels = {
    queued: "QUEUED", pending: "PENDING — waiting for stability",
    present: "FILE PRESENT", absent: "FILE ABSENT", error: "OBSERVATION ERROR",
};
const reasons = {
    stability_wait: "Waiting for a stable file.",
    matching_file_observed: "Matching file observed.",
    stable_matching_file_observed: "Stable matching file observed.",
    directory_missing: "Configured directory is missing.",
    no_matching_file: "No matching file found.",
    no_eligible_file: "No file meets the criteria.",
    invalid_configuration: "Invalid or changed configuration.",
    scan_failed: "Metadata scan failed.",
    directory_identity_changed: "Directory changed during observation.",
    manual_probe_changed_or_invalid: "Observation configuration changed or is invalid.",
    manual_probe_expired: "Observation deadline reached.",
    observation_clock_moved_backwards: "Observation clock changed unexpectedly.",
};
const base = computed(() => LOCAL_PRESENCE_RULES + "/" + props.ruleId + "/verify-now");
const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
let alive = true;
let generation = 0;
let timer = null;
let polls = 0;
const options = { timeout: 10000 };

function clearTimer() {
    if (timer !== null) clearTimeout(timer);
    timer = null;
}
function stop() {
    generation++;
    clearTimer();
    busy.value = false;
    stopped.value = true;
}
function validResponse(data, expectedId) {
    return data && data.rule_id === props.ruleId && typeof data.id === "string" && uuid.test(data.id)
        && (!expectedId || data.id === expectedId)
        && Object.hasOwn(labels, data.status)
        && data.evidence_type === "metadata_presence" && data.persistent_check_run === false
        && data.verified_success === false && Number.isFinite(Date.parse(data.expires_at_utc));
}
function schedule(response) {
    clearTimer();
    if (!alive || !props.available || stopped.value) return;
    if (terminal.value) {
        // Use server time when available, not the browser clock. Expiry is
        // advisory here; the API remains authoritative for every restart.
        const serverTime = Date.parse(response.headers?.date ?? "");
        const remaining = Date.parse(session.value.expires_at_utc) - serverTime;
        if (Number.isFinite(remaining)) {
            timer = setTimeout(() => {
                expired.value = true;
                stopped.value = true;
            }, Math.max(0, Math.min(remaining, 7200000)));
        }
        return;
    }
    if (polls >= 1440) {
        stopped.value = true;
        error.value = "Polling limit reached. Resume status explicitly.";
        return;
    }
    timer = setTimeout(() => request("poll"), 5000);
}

async function request(action) {
    if (!alive || !props.available || busy.value) return;
    if (action === "poll" && (!session.value || expired.value || terminal.value)) return;
    if (action === "restart" && (!terminal.value || expired.value || recoverByStart.value)) return;
    clearTimer();
    const version = ++generation;
    const previous = session.value?.id;
    const ruleId = props.ruleId;
    busy.value = true;
    stopped.value = false;
    error.value = "";
    if (action !== "poll") polls = 0;
    else polls++;
    try {
        const response = action === "poll"
            ? await axios.get(base.value + "/" + previous, options)
            : await axios.post(action === "restart" ? base.value + "/" + previous + "/restart" : base.value, {}, options);
        if (!alive || version !== generation || ruleId !== props.ruleId || !props.available) return;
        const data = response.data?.data;
        if (!validResponse(data, action === "poll" ? previous : null) || (action === "restart" && data.id === previous)) {
            throw new Error("Invalid diagnostic response");
        }
        session.value = data;
        expired.value = false;
        recoverByStart.value = false;
        schedule(response);
    } catch (exception) {
        if (!alive || version !== generation || ruleId !== props.ruleId) return;
        stopped.value = true;
        const status = exception.response?.status;
        if (status === 404) {
            expired.value = true;
            error.value = "Session expired or no longer available. Start or resume explicitly.";
        } else if (status === 409) {
            error.value = "Request refused. Check operator approval, rule state or capacity.";
        } else if (status === 429) {
            error.value = "Rate limit reached. Wait before retrying manually.";
        } else if (status === 401 || status === 403) {
            error.value = "Administrator access is required.";
        } else {
            error.value = "Unable to confirm the diagnostic state. No automatic retry was made.";
        }
        // A restart might have committed despite a lost response. Never
        // replay the old CAS automatically; recover through idempotent start.
        recoverByStart.value = action !== "poll" || status === 404 || status === 409;
    } finally {
        if (alive && version === generation) busy.value = false;
    }
}
watch(() => props.available, approved => {
    if (!approved) {
        stop();
        session.value = null;
        error.value = "Verify Now is not approved.";
        recoverByStart.value = true;
    }
});
watch(() => props.ruleId, () => {
    stop();
    session.value = null;
    expired.value = false;
    error.value = "";
    recoverByStart.value = true;
});
onMounted(() => request("start"));
onBeforeUnmount(() => {
    alive = false;
    generation++;
    clearTimer();
});
</script>

<template>
    <section aria-label="Manual presence diagnostic" class="mt-3 space-y-3 rounded border bg-muted p-3 text-foreground">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h5 class="font-semibold">Verify Now — manual diagnostic</h5>
            <button type="button" class="rounded border bg-background px-2 py-1" @click="emit('close')">Close diagnostic</button>
        </div>
        <p class="text-xs text-muted-foreground">Metadata presence only. This is not a verified backup, receipt or scheduled history entry. Closing the panel stops polling, not the server observation.</p>
        <p v-if="!available" role="status">Verify Now is not approved.</p>
        <p v-if="busy && !session" role="status">Admitting diagnostic...</p>
        <div v-if="session" class="space-y-1">
            <p role="status" class="font-semibold" :class="expired ? 'text-amber-800 dark:text-amber-400' : session.status === 'present' ? 'text-green-800 dark:text-green-400' : session.status === 'error' ? 'text-red-700 dark:text-red-400' : 'text-foreground'">
                {{ expired ? "SESSION NO LONGER AVAILABLE" : labels[session.status] }}
            </p>
            <template v-if="!expired">
                <p v-if="session.result?.reason">{{ reasons[session.result.reason] || "Observation detail unavailable." }}</p>
                <p v-if="session.result?.filename" class="break-all font-mono text-xs">File: {{ session.result.filename }}</p>
                <p v-if="session.result?.observed_at_utc" class="text-xs">Observed (UTC): {{ session.result.observed_at_utc }}</p>
                <p v-if="session.next_observation_at_utc" class="text-xs">Next eligible observation (UTC): {{ session.next_observation_at_utc }}</p>
                <p class="text-xs">Expires (UTC): {{ session.expires_at_utc }}</p>
            </template>
        </div>
        <p v-if="error" role="alert" class="text-sm text-red-700 dark:text-red-400">{{ error }}</p>
        <div v-if="available" class="flex flex-wrap gap-2">
            <button v-if="expired || recoverByStart || (!session && !busy)" type="button" :disabled="busy"
                class="rounded bg-blue-700 px-3 py-1 text-white disabled:opacity-50" @click="request('start')">Start or resume</button>
            <button v-else-if="terminal" type="button" :disabled="busy"
                class="rounded bg-blue-700 px-3 py-1 text-white disabled:opacity-50" @click="request('restart')">Restart diagnostic</button>
            <button v-else-if="stopped" type="button" :disabled="busy"
                class="rounded border bg-background px-3 py-1" @click="request('poll')">Resume status</button>
            <span v-else-if="session" class="text-xs text-muted-foreground">Checking status every five seconds.</span>
        </div>
    </section>
</template>
