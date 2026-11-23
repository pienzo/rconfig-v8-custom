<script setup>
import axios from "axios";
import { ref } from "vue";

const mode = ref("glob");
const pattern = ref("backup_*.cfg");
const sampleNames = ref("backup_20261123.cfg\nbackup_old.txt");
const results = ref([]);
const errorMessage = ref("");
const isLoading = ref(false);

async function preview() {
    results.value = [];
    errorMessage.value = "";

    const filenames = sampleNames.value.split(/\r?\n/).map((name) => name.trim()).filter(Boolean);
    if (filenames.length < 1 || filenames.length > 50) {
        errorMessage.value = "Enter between 1 and 50 sample filenames.";
        return;
    }

    isLoading.value = true;
    try {
        const response = await axios.post("/api/tasks/external-backup-pattern-preview", {
            pattern_mode: mode.value,
            filename_pattern: pattern.value,
            filenames,
        });
        results.value = response.data.results;
    } catch (error) {
        const messages = error.response?.data?.errors;
        errorMessage.value = messages
            ? Object.values(messages).flat().join(" ")
            : "Unable to preview the pattern.";
    } finally {
        isLoading.value = false;
    }
}
</script>

<template>
    <section class="rounded-md border border-gray-300 p-4 text-left dark:border-gray-700" aria-label="External backup filename pattern tester">
        <h3 class="font-semibold text-sm mb-1">External backup file pattern tester</h3>
        <p class="text-xs text-muted-foreground mb-3">
            Test glob or regex against example filenames. This does not read any server folders or create backup checks.
        </p>
        <div class="grid gap-3 md:grid-cols-[8rem_1fr]">
            <label class="text-sm" for="f02-preview-mode">Match mode
                <select id="f02-preview-mode" v-model="mode" class="block w-full rounded border p-2 text-sm bg-background">
                    <option value="glob">Glob</option>
                    <option value="regex">Regex</option>
                </select>
            </label>
            <label class="text-sm" for="f02-preview-pattern">Filename pattern
                <input id="f02-preview-pattern" v-model="pattern" type="text" maxlength="255"
                    class="block w-full rounded border p-2 text-sm bg-background" autocomplete="off"
                    placeholder="backup_*.cfg" />
            </label>
        </div>
        <label class="mt-3 block text-sm" for="f02-preview-filenames">Example filenames (one per line, max 50)
            <textarea id="f02-preview-filenames" v-model="sampleNames" rows="3"
                class="mt-1 block w-full rounded border p-2 font-mono text-xs bg-background" />
        </label>
        <button type="button" :disabled="isLoading" class="mt-3 rounded bg-blue-600 px-3 py-2 text-sm text-white disabled:opacity-50" @click="preview">
            {{ isLoading ? "Testing..." : "Preview matches" }}
        </button>
        <p v-if="errorMessage" role="alert" class="mt-2 text-sm text-red-500">{{ errorMessage }}</p>
        <ul v-if="results.length" class="mt-3 space-y-1 text-xs" aria-label="Filename match results">
            <li v-for="(result, index) in results" :key="index" class="flex gap-3">
                <span :class="result.matches ? 'text-green-600 dark:text-green-400' : 'text-amber-600 dark:text-amber-400'">
                    {{ result.matches ? "MATCH" : "NO MATCH" }}
                </span>
                <span class="break-all font-mono">{{ result.filename }}</span>
            </li>
        </ul>
    </section>
</template>
