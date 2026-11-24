<script setup>
import axios from "axios";
import { onMounted, ref } from "vue";

const drafts = ref([]);
const variables = ref([]);
const event = ref("backup.success");
const title = ref("");
const body = ref("");
const result = ref(null);
const busy = ref(false);
const error = ref("");
const message = ref("");

function selectDraft() {
    const draft = drafts.value.find((item) => item.event_type === event.value);
    title.value = draft?.title || "";
    body.value = draft?.body || "";
    result.value = null;
    message.value = "";
    error.value = "";
}

async function load() {
    busy.value = true;
    try {
        const response = await axios.get("/api/settings/whatsapp-template-drafts");
        drafts.value = response.data.drafts;
        variables.value = response.data.variables;
        selectDraft();
    } catch (e) {
        error.value = e.response?.data?.message || "Impossibile caricare le bozze WhatsApp.";
    } finally {
        busy.value = false;
    }
}

async function compile(save) {
    busy.value = true;
    error.value = "";
    message.value = "";
    result.value = null;
    const payload = { event_type: event.value, title: title.value, body: body.value };
    try {
        const response = save
            ? await axios.put("/api/settings/whatsapp-template-drafts", payload)
            : await axios.post("/api/settings/whatsapp-template-drafts/preview", payload);
        if (save) {
            const index = drafts.value.findIndex((item) => item.event_type === event.value);
            if (index !== -1) drafts.value[index] = { ...payload, saved: true };
        }
        result.value = response.data.draft;
        message.value = save ? "Bozza salvata, non approvata né attivata." : "Anteprima sintetica.";
    } catch (e) {
        error.value = e.response?.data?.message || "Bozza non valida.";
    } finally {
        busy.value = false;
    }
}

function addVariable(variable) {
    body.value += "{{" + variable + "}}";
    result.value = null;
}

onMounted(load);
</script>

<template>
    <section class="space-y-3 rounded-lg border p-4" aria-label="Bozze template WhatsApp">
        <h4 class="text-lg font-semibold">WhatsApp — bozze per approvazione Meta</h4>
        <p class="text-sm text-muted-foreground">
            Personalizza intestazione statica e testo. Salvataggio e anteprima non modificano i template approvati in uso e non inviano messaggi.
            Trasferisci il testo e gli esempi in Meta Business Manager; solo dopo approvazione configura il mapping sul server.
        </p>
        <label class="block text-sm">Evento
            <select v-model="event" :disabled="busy" class="mt-1 block w-full rounded border bg-background p-2" @change="selectDraft">
                <option value="backup.success">Backup riuscito</option>
                <option value="backup.failed">Backup fallito</option>
            </select>
        </label>
        <label class="block text-sm">Intestazione statica
            <input v-model="title" :disabled="busy" maxlength="60" class="mt-1 block w-full rounded border bg-background p-2" @input="result = null" />
        </label>
        <label class="block text-sm">Corpo della bozza
            <textarea v-model="body" :disabled="busy" rows="5" maxlength="1024" class="mt-1 block w-full rounded border bg-background p-2 font-mono" @input="result = null" />
        </label>
        <div class="flex flex-wrap gap-2">
            <button v-for="variable in variables" :key="variable" type="button" :disabled="busy" class="rounded border px-2 py-1 text-xs" @click="addVariable(variable)">
                {{ variable }}
            </button>
        </div>
        <div class="flex gap-2">
            <button type="button" :disabled="busy || !drafts.length" class="rounded bg-blue-600 px-3 py-2 text-white disabled:opacity-50" @click="compile(true)">Salva bozza</button>
            <button type="button" :disabled="busy || !drafts.length" class="rounded border px-3 py-2 disabled:opacity-50" @click="compile(false)">Anteprima</button>
        </div>
        <p v-if="error" role="alert" class="text-red-500">{{ error }}</p>
        <p v-if="message" role="status" class="text-green-600">{{ message }}</p>
        <div v-if="result" class="space-y-2 rounded border bg-muted p-3 text-sm">
            <p class="font-semibold">{{ result.title }}</p>
            <pre class="whitespace-pre-wrap break-all">{{ result.preview }}</pre>
            <p class="font-semibold">Corpo posizionale da sottoporre a Meta</p>
            <pre class="whitespace-pre-wrap break-all">{{ result.provider_body }}</pre>
            <p>Valori di esempio: {{ result.example_values.join(" | ") || "Nessuno" }}</p>
            <p class="break-all font-mono">Mapping: {{ result.fields.join(",") || "(vuoto)" }}</p>
            <p class="text-amber-600">Bozza locale — nessuna approvazione o invio verificati.</p>
        </div>
    </section>
</template>
