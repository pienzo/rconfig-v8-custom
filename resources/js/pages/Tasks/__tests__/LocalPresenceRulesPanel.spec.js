import { mount, flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import axios from "axios";
import LocalPresenceRulesPanel from "../LocalPresenceRulesPanel.vue";
import { initialLocalPresenceDraft, localPresenceDraftFromRule, localPresencePayload } from "../localPresenceRule.js";

vi.mock("axios", () => ({ default: { get: vi.fn(), patch: vi.fn(), delete: vi.fn() } }));
const base = "/api/tasks/local-backup-presence-rules";
const rule = {
    ...localPresencePayload({ ...initialLocalPresenceDraft(), task_name: "Synthetic draft", directory_alias: "synthetic" }, 42),
    id: 11, task_id: 21, enabled: false, activated_at_utc: null,
    task: { task_name: "Synthetic draft", task_cron: ["0", "2", "*", "*", "*"] },
    device: { id: 42, device_name: "Synthetic router" },
};
const suspended = { ...rule, id: 12, task_id: 22, activated_at_utc: "2026-11-27 00:00:00", task: { ...rule.task, task_name: "Synthetic suspended" } };
const active = { ...suspended, id: 13, task_id: 23, enabled: true, task: { ...rule.task, task_name: "Synthetic active" } };
const response = (rows = [rule, suspended, active], approval = false) => ({
    data: { data: rows, current_page: 1, last_page: 1, activation_available: approval, manual_available: false },
});
const findButton = (wrapper, label) => wrapper.findAll("button").find(button => button.text() === label);
const row = (wrapper, name) => wrapper.findAll('ul[aria-label="Configured Local Presence rules"] > li').find(item => item.text().includes(name));
async function start(approval = false) {
    axios.get.mockResolvedValue(response(undefined, approval));
    const wrapper = mount(LocalPresenceRulesPanel, {
        global: { stubs: { LocalPresenceRuleForm: { props: ["rule", "busy", "error"], emits: ["save", "cancel"],
            template: '<button type="button" @click="$emit(\'save\', { task_name: \'Updated\' })">Save fixture edit</button>' } } },
    });
    await flushPromises();
    return wrapper;
}

beforeEach(() => {
    vi.resetAllMocks();
    axios.patch.mockResolvedValue({});
    axios.delete.mockResolvedValue({});
});

describe("Local Presence rule management", () => {
    it("only offers edits/deletes to never-activated drafts, permits suspension with activation gate closed", async () => {
        const wrapper = await start();
        expect(findButton(row(wrapper, "Synthetic draft"), "Edit").attributes("disabled")).toBeUndefined();
        for (const name of ["Synthetic suspended", "Synthetic active"]) {
            expect(findButton(row(wrapper, name), "Edit").attributes()).toHaveProperty("disabled");
            expect(findButton(row(wrapper, name), "Delete").attributes()).toHaveProperty("disabled");
        }
        expect(findButton(row(wrapper, "Synthetic draft"), "Activate").attributes()).toHaveProperty("disabled");
        expect(findButton(row(wrapper, "Synthetic active"), "Suspend").attributes("disabled")).toBeUndefined();
        expect(wrapper.text()).toContain("Not approved");
        wrapper.unmount();
    });

    it("requires explicit confirmation and uses only the dedicated activation route", async () => {
        const wrapper = await start(true);
        await findButton(row(wrapper, "Synthetic draft"), "Activate").trigger("click");
        expect(axios.patch).not.toHaveBeenCalled();
        await findButton(wrapper, "Confirm activation").trigger("click");
        await flushPromises();
        expect(axios.patch).toHaveBeenCalledExactlyOnceWith(base + "/11/activation", { enabled: true });
        expect(wrapper.emitted("changed")).toHaveLength(1);
        wrapper.unmount();
    });

    it("suspends with approval closed and preserves safe server conflicts", async () => {
        const wrapper = await start();
        axios.patch.mockRejectedValueOnce({ response: { status: 409, data: { message: "/private/path" } } });
        await findButton(row(wrapper, "Synthetic active"), "Suspend").trigger("click");
        await findButton(wrapper, "Confirm suspension").trigger("click");
        await flushPromises();
        expect(axios.patch).toHaveBeenCalledExactlyOnceWith(base + "/13/activation", { enabled: false });
        expect(wrapper.get('[role="alert"]').text()).toContain("Change refused");
        expect(wrapper.text()).not.toContain("/private/path");
        expect(wrapper.emitted("changed")).toBeUndefined();
        wrapper.unmount();
    });

    it("edits using dedicated PATCH and deletes only after confirmation", async () => {
        const wrapper = await start();
        await findButton(row(wrapper, "Synthetic draft"), "Edit").trigger("click");
        await findButton(wrapper, "Save fixture edit").trigger("click");
        await flushPromises();
        expect(axios.patch).toHaveBeenCalledExactlyOnceWith(base + "/11", { task_name: "Updated" });
        await findButton(row(wrapper, "Synthetic draft"), "Delete").trigger("click");
        expect(axios.delete).not.toHaveBeenCalled();
        await findButton(wrapper, "Confirm deletion").trigger("click");
        await flushPromises();
        expect(axios.delete).toHaveBeenCalledExactlyOnceWith(base + "/11");
        expect(wrapper.emitted("changed")).toHaveLength(2);
        wrapper.unmount();
    });

    it("uses fixed device, preserves nullable criteria, and excludes device_id in edit payload", () => {
        const draft = localPresenceDraftFromRule(rule);
        expect(draft.task_cron_text).toBe("0 2 * * *");
        expect(localPresencePayload(draft, undefined, true)).toEqual(
            Object.fromEntries(Object.entries(localPresencePayload(draft, 42)).filter(([key]) => key !== "device_id"))
        );
    });

    it("scopes history, paginates it and ignores old responses after closing", async () => {
        const wrapper = await start();
        axios.get.mockResolvedValueOnce({ data: { data: [{ id: 1, status: "present", reason: "matching_file_observed", matched_filename: "backup.cfg" }], current_page: 1, last_page: 2 } });
        await findButton(row(wrapper, "Synthetic draft"), "History").trigger("click");
        await flushPromises();
        expect(axios.get).toHaveBeenLastCalledWith(base + "/11/runs", { params: { page: 1, per_page: 10 } });
        expect(wrapper.text()).toContain("backup.cfg");
        let resolve;
        axios.get.mockImplementationOnce(() => new Promise(done => { resolve = done; }));
        await findButton(wrapper, "Next history").trigger("click");
        expect(axios.get).toHaveBeenLastCalledWith(base + "/11/runs", { params: { page: 2, per_page: 10 } });
        await findButton(row(wrapper, "Synthetic draft"), "Close history").trigger("click");
        resolve({ data: { data: [{ id: 2, matched_filename: "stale.cfg" }], current_page: 2, last_page: 2 } });
        await flushPromises();
        expect(wrapper.text()).not.toContain("stale.cfg");
        expect(axios.patch).not.toHaveBeenCalled();
        wrapper.unmount();
    });
});
