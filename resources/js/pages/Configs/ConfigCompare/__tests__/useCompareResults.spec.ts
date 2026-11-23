import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent } from "vue";

vi.mock("axios", () => ({ default: { get: vi.fn() } }));
const toastError = vi.fn();
vi.mock("@/composables/useToaster", () => ({
    useToaster: () => ({ toastError }),
}));

import axios from "axios";
import { useCompareResults } from "../useCompareResults";

type CompareState = ReturnType<typeof useCompareResults>;

function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((fn) => { resolve = fn; });
    return { resolve, promise };
}

function mountedComparison(left = [11], right = [22]) {
    let state!: CompareState;
    const wrapper = mount(defineComponent({
        setup() {
            state = useCompareResults({ leftSelectedId: left, rightSelectedId: right });
            return () => null;
        },
    }));
    return { state, wrapper };
}

describe("Core F01 config diff loading", () => {
    beforeEach(() => {
        vi.mocked(axios.get).mockReset();
        toastError.mockReset();
    });

    it("waits for both configuration files before showing the diff editor", async () => {
        const slowRight = deferred<{ data: { data: { content: string } } }>();
        vi.mocked(axios.get).mockImplementation(async (url: string) => {
            if (url === "/api/configs/11") return { data: { id: 11 } };
            if (url === "/api/configs/22") return { data: { id: 22 } };
            if (url === "/api/configs/view-config/11") return { data: { data: { content: "first" } } };
            if (url === "/api/configs/view-config/22") return slowRight.promise;
            throw new Error("Unexpected URL");
        });

        const { state } = mountedComparison();
        await flushPromises();

        expect(state.isLoadingComponent.value).toBe(true);
        expect(state.configResultsLeft.value?.filecontent).toBe("first");
        expect(state.configResultsRight.value).toBe(null);

        slowRight.resolve({ data: { data: { content: "second" } } });
        await flushPromises();

        expect(state.isLoadingComponent.value).toBe(false);
        expect(state.configResultsRight.value?.filecontent).toBe("second");
        expect(state.comparisonError.value).toBe("");
    });

    it("does not render a partial diff when a file is unreadable", async () => {
        vi.mocked(axios.get).mockImplementation(async (url: string) => {
            if (url.endsWith("/22")) throw new Error("Read failed");
            if (url.includes("/view-config/")) return { data: { data: { content: "synthetic" } } };
            return { data: { id: 11 } };
        });

        const { state } = mountedComparison();
        await flushPromises();

        expect(state.isLoadingComponent.value).toBe(false);
        expect(state.comparisonError.value).toMatch(/Unable to load/);
        expect(toastError).toHaveBeenCalledTimes(1);
    });

    it("rejects invalid selected ids without making API requests", async () => {
        const { state } = mountedComparison([0], [22]);
        await flushPromises();

        expect(axios.get).not.toHaveBeenCalled();
        expect(state.isLoadingComponent.value).toBe(false);
        expect(state.comparisonError.value).toMatch(/Select two/);
    });
});
