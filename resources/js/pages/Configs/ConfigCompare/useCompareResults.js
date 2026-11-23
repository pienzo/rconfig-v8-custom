import axios from "axios";
import { onMounted, ref } from "vue";
import { useToaster } from "@/composables/useToaster";

export function useCompareResults(props) {
    const isLoadingComponent = ref(true);
    const comparisonError = ref("");
    const configResultsLeft = ref(null);
    const configResultsRight = ref(null);
    const leftId = ref(Number(props.leftSelectedId?.[0]));
    const rightId = ref(Number(props.rightSelectedId?.[0]));
    const { toastError } = useToaster();

    async function getConfigFileContent(id) {
        const response = await axios.get("/api/configs/view-config/" + id);
        const content = response.data?.data?.content;

        if (typeof content !== "string") {
            throw new Error("Missing configuration content.");
        }

        return content;
    }

    async function loadSide(id) {
        const [metadata, content] = await Promise.all([
            axios.get("/api/configs/" + id),
            getConfigFileContent(id),
        ]);

        return { ...metadata.data, filecontent: content };
    }

    async function getConfigLeft(id) {
        const value = await loadSide(id);
        configResultsLeft.value = value;
        return value;
    }

    async function getConfigRight(id) {
        const value = await loadSide(id);
        configResultsRight.value = value;
        return value;
    }

    async function loadComparison() {
        isLoadingComponent.value = true;
        comparisonError.value = "";
        configResultsLeft.value = null;
        configResultsRight.value = null;

        if (!Number.isSafeInteger(leftId.value) || leftId.value <= 0
            || !Number.isSafeInteger(rightId.value) || rightId.value <= 0) {
            comparisonError.value = "Select two valid configurations for comparison.";
            isLoadingComponent.value = false;
            return;
        }

        try {
            await Promise.all([getConfigLeft(leftId.value), getConfigRight(rightId.value)]);
        } catch {
            // Never expose device configuration contents or paths in error logs.
            comparisonError.value = "Unable to load both configurations for comparison.";
            toastError("Error", comparisonError.value);
        } finally {
            isLoadingComponent.value = false;
        }
    }

    onMounted(loadComparison);

    return {
        isLoadingComponent,
        comparisonError,
        configResultsLeft,
        configResultsRight,
        getConfigLeft,
        getConfigRight,
        getConfigFileContent,
    };
}
