import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import axios from "axios";
import ExternalBackupRulesPanel from "../ExternalBackupRulesPanel.vue";

vi.mock("axios", () => ({
    default: { get: vi.fn(), patch: vi.fn(), post: vi.fn(), delete: vi.fn() },
}));
vi.mock("@/pages/Shared/FormFields/DeviceMultiSelect.vue", () => ({
    default: { template: "<div />" },
}));

const url = "/api/tasks/external-backup-rules";
const rule = {
    id: 7, enabled: false, task: { task_name: "Synthetic", task_cron: ["0", "2", "*", "*", "*"] },
    device: { id: 3, device_name: "DEMO" }, pattern_mode: "glob", filename_pattern: "*.cfg",
};
function listing(row = rule, admin = true, available = true) {
    return { data: { data: [row], can_manage_activation: admin, activation_available: available, current_page: 1, last_page: 1 } };
}
function button(wrapper, label) {
    return wrapper.findAll("button").find((item) => item.text() === label);
}
let wrapper;
beforeEach(() => vi.resetAllMocks());
afterEach(() => {
    wrapper?.unmount();
    vi.restoreAllMocks();
});

describe("External backup rules", () => {
    it("hides activation for non-admins and disables it when server approval is absent", async () => {
        axios.get.mockResolvedValueOnce(listing(rule, false, true));
        wrapper = mount(ExternalBackupRulesPanel);
        await flushPromises();
        expect(button(wrapper, "Attiva")).toBeUndefined();
        wrapper.unmount();

        axios.get.mockResolvedValueOnce(listing(rule, true, false));
        wrapper = mount(ExternalBackupRulesPanel);
        await flushPromises();
        expect(button(wrapper, "Attiva").element.disabled).toBe(true);
        expect(axios.patch).not.toHaveBeenCalled();
    });

    it("requires confirmation and shows enabled status only after server reload", async () => {
        const confirm = vi.spyOn(window, "confirm").mockReturnValueOnce(false).mockReturnValueOnce(true);
        axios.get.mockResolvedValueOnce(listing()).mockResolvedValueOnce(listing({ ...rule, enabled: true }));
        axios.patch.mockResolvedValueOnce({ data: {} });
        wrapper = mount(ExternalBackupRulesPanel);
        await flushPromises();
        await button(wrapper, "Attiva").trigger("click");
        expect(axios.patch).not.toHaveBeenCalled();
        await button(wrapper, "Attiva").trigger("click");
        await flushPromises();
        expect(confirm).toHaveBeenCalledTimes(2);
        expect(axios.patch).toHaveBeenCalledExactlyOnceWith(url + "/7/activation", { enabled: true });
        expect(wrapper.text()).toContain("ENABLED");
        expect(button(wrapper, "Edit").element.disabled).toBe(true);
        expect(button(wrapper, "Delete").element.disabled).toBe(true);
    });

    it("keeps enabled state if pause is refused and exposes the server error", async () => {
        axios.get.mockResolvedValueOnce(listing({ ...rule, enabled: true }, true, false));
        axios.patch.mockRejectedValueOnce({ response: { data: { message: "Pending check" } } });
        wrapper = mount(ExternalBackupRulesPanel);
        await flushPromises();
        expect(button(wrapper, "Sospendi").element.disabled).toBe(false);
        await button(wrapper, "Sospendi").trigger("click");
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toBe("Pending check");
        expect(wrapper.text()).toContain("ENABLED");
    });

    it("does not display a success without a verified receipt as a valid result", async () => {
        axios.get.mockResolvedValueOnce(listing()).mockResolvedValueOnce({
            data: { data: [{ id: 9, status: "success", receipt_exists: false }], current_page: 1, last_page: 1 },
        });
        wrapper = mount(ExternalBackupRulesPanel);
        await flushPromises();
        await button(wrapper, "Storico").trigger("click");
        await flushPromises();
        expect(wrapper.text()).toContain("INCONSISTENT: missing receipt");
        expect(wrapper.text()).not.toContain("ricevuta presente");
    });
});
