<script setup>
import axios from "axios";
import { computed, onMounted, ref } from "vue";

const templates = ref([]);
const variables = ref([]);
const key = ref("email:backup.success");
const title = ref("");
const body = ref("");
const preview = ref(null);
const busy = ref(false);
const error = ref("");
const message = ref("");
const current = computed(() => templates.value.find((item) => item.channel + ":" + item.event_type === key.value));
const tokenHelp = computed(() => variables.value.map((item) => `{{${item}}}`).join("  "));

function selectTemplate() {
    title.value = current.value?.title || "";
    body.value = current.value?.body || "";
    preview.value = null;
    error.value = "";
    message.value = "";
}

async function load() {
    busy.value = true;
    try {
        const response = await axios.get("/api/settings/notification-templates");
        templates.value = response.data.templates;
        variables.value = response.data.variables;
        selectTemplate();
    } catch (e) {
        error.value = e.response?.data?.message || "Impossibile caricare i template.";
    } finally {
        busy.value = false;
    }
}

async function save() {
    if (!current.value) return;
    busy.value = true;
    error.value = "";
    try {
        await axios.put("/api/settings/notification-templates/" + current.value.channel + "/" + current.value.event_type, {
            title: title.value, body: body.value,
        });
        await load();
        message.value = "Template salvato.";
    } catch (e) {
        error.value = e.response?.data?.message || "Salvataggio non riuscito.";
    } finally {
        busy.value = false;
    }
}

async function showPreview() {
    if (!current.value) return;
    busy.value = true;
    error.value = "";
    try {
        const response = await axios.post("/api/settings/notification-templates/preview", {
            channel: current.value.channel, event_type: current.value.event_type,
            title: title.value, body: body.value,
        });
        preview.value = response.data.preview;
    } catch (e) {
        error.value = e.response?.data?.message || "Anteprima non disponibile.";
    } finally {
        busy.value = false;
    }
}

async function sendTelegramTest() {
    if (current.value?.channel !== "telegram"
        || !window.confirm("Inviare un messaggio Telegram di prova al destinatario configurato sul server?")) return;
    busy.value = true;
    error.value = "";
    message.value = "";
    try {
        const response = await axios.post("/api/settings/notification-templates/telegram/test", {
            event_type: current.value.event_type,
        });
        if (response.data.sent === true) message.value = "Messaggio di prova inviato via Telegram.";
    } catch (e) {
        error.value = e.response?.data?.message || "Test Telegram non riuscito.";
    } finally {
        busy.value = false;
    }
}

async function restoreDefaults() {
    if (!current.value || !window.confirm("Ripristinare il template predefinito?")) return;
    busy.value = true;
    try {
        await axios.delete("/api/settings/notification-templates/" + current.value.channel + "/" + current.value.event_type);
        await load();
        message.value = "Default ripristinato.";
    } catch (e) {
        error.value = e.response?.data?.message || "Ripristino non riuscito.";
    } finally {
        busy.value = false;
    }
}

onMounted(load);
</script>

<template>
    <section class="w-full space-y-4 rounded-lg border p-5 text-left" aria-label="Notification template editor">
        <h3 class="text-xl font-semibold">Template notifiche backup</h3>
        <p class="text-sm text-muted-foreground">Oggetto/titolo e corpo email o Telegram. L'anteprima usa solo dati sintetici.</p>
        <p class="text-sm text-amber-600 dark:text-amber-400">
            Editor disponibile. L'invio tramite questi template non è ancora collegato ai canali di notifica.
        </p>
        <label class="block text-sm">Canale ed evento
            <select v-model="key" class="mt-1 block w-full rounded border bg-background p-2" @change="selectTemplate">
                <option v-for="item in templates" :key="item.channel + ':' + item.event_type" :value="item.channel + ':' + item.event_type">
                    {{ item.channel === "email" ? "Email" : "Telegram" }} —
                    {{ item.event_type === "backup.success" ? "Backup riuscito" : "Backup fallito" }}
                    {{ item.customized ? "(personalizzato)" : "" }}
                </option>
            </select>
        </label>
        <label class="block text-sm">Oggetto / titolo
            <input v-model="title" class="mt-1 block w-full rounded border bg-background p-2" maxlength="255" />
        </label>
        <label class="block text-sm">Corpo messaggio
            <textarea v-model="body" rows="7" maxlength="8192" class="mt-1 block w-full rounded border bg-background p-2 font-mono text-sm" />
        </label>
        <p class="rounded border p-3 text-xs break-all font-mono">
            Variabili: {{ tokenHelp }}
        </p>
        <div class="flex flex-wrap gap-2">
            <button type="button" :disabled="busy || !current" class="rounded bg-blue-600 px-3 py-2 text-white disabled:opacity-50" @click="save">Salva</button>
            <button type="button" :disabled="busy || !current" class="rounded border px-3 py-2 disabled:opacity-50" @click="showPreview">Anteprima</button>
            <button v-if="current?.channel === 'telegram'" type="button" :disabled="busy" class="rounded border px-3 py-2 disabled:opacity-50" @click="sendTelegramTest">Invia test Telegram</button>
            <button type="button" :disabled="busy || !current" class="rounded border px-3 py-2 disabled:opacity-50" @click="restoreDefaults">Ripristina</button>
        </div>
        <p v-if="error" role="alert" class="text-red-500">{{ error }}</p>
        <p v-if="message" role="status" class="text-green-600">{{ message }}</p>
        <div v-if="preview" class="rounded border bg-muted p-3 text-sm">
            <h4 class="font-semibold">Anteprima (nessun invio)</h4>
            <p class="my-2 font-semibold whitespace-pre-wrap">{{ preview.title }}</p>
            <pre class="font-sans whitespace-pre-wrap break-all">{{ preview.body }}</pre>
        </div>
    </section>
</template>
