import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import axios from "axios";
import WhatsAppTemplateDraftsPanel from "../WhatsAppTemplateDraftsPanel.vue";

vi.mock("axios", () => ({
    default: { get: vi.fn(), put: vi.fn(), post: vi.fn() },
}));

const url = "/api/settings/whatsapp-template-drafts";
const drafts = [
    { event_type: "backup.success", title: "Success", body: "Backup completed", saved: false },
    { event_type: "backup.failed", title: "Failure", body: "Backup failed", saved: false },
];
function button(wrapper, label) {
    return wrapper.findAll("button").find((item) => item.text() === label);
}
let wrapper;
beforeEach(async () => {
    vi.resetAllMocks();
    axios.get.mockResolvedValueOnce({ data: { drafts: drafts.map((draft) => ({ ...draft })), variables: ["backup.status"] } });
    wrapper = mount(WhatsAppTemplateDraftsPanel);
    await flushPromises();
});
afterEach(() => wrapper.unmount());

describe("WhatsApp local drafts", () => {
    it("previews a synthetic positional template without saving or sending", async () => {
        await wrapper.get("select").setValue("backup.failed");
        await wrapper.get("textarea").setValue("Result: ");
        await button(wrapper, "backup.status").trigger("click");
        const draft = { title: "Failure", preview: "Result: FAILED", provider_body: "Result: {{1}}", example_values: ["FAILED"], fields: ["backup.status"] };
        axios.post.mockResolvedValueOnce({ data: { draft, saved: false, approved: false, sent: false } });
        await button(wrapper, "Anteprima").trigger("click");
        await flushPromises();
        expect(axios.post).toHaveBeenCalledExactlyOnceWith(url + "/preview", {
            event_type: "backup.failed", title: "Failure", body: "Result: {{backup.status}}",
        });
        expect(axios.put).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain("Result: {{1}}");
        expect(wrapper.text()).toContain("Result: FAILED");
        expect(wrapper.text()).toContain("nessuna approvazione o invio verificati");
    });

    it("preserves saved local edits when switching events", async () => {
        await wrapper.get("input").setValue("Local draft");
        await wrapper.get("textarea").setValue("Synthetic body");
        axios.put.mockResolvedValueOnce({
            data: { draft: { title: "Local draft", preview: "Synthetic body", provider_body: "Synthetic body", example_values: [], fields: [] }, saved: true },
        });
        await button(wrapper, "Salva bozza").trigger("click");
        await flushPromises();
        expect(axios.put).toHaveBeenCalledExactlyOnceWith(url, {
            event_type: "backup.success", title: "Local draft", body: "Synthetic body",
        });
        expect(wrapper.text()).toContain("non approvata né attivata");
        await wrapper.get("select").setValue("backup.failed");
        await wrapper.get("select").setValue("backup.success");
        expect(wrapper.get("input").element.value).toBe("Local draft");
        expect(wrapper.get("textarea").element.value).toBe("Synthetic body");
    });

    it("preserves unsaved input and shows validation refusal without a preview", async () => {
        await wrapper.get("textarea").setValue("Invalid {{secret}}");
        axios.put.mockRejectedValueOnce({ response: { data: { message: "Unsupported variable" } } });
        await button(wrapper, "Salva bozza").trigger("click");
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toBe("Unsupported variable");
        expect(wrapper.get("textarea").element.value).toBe("Invalid {{secret}}");
        expect(wrapper.text()).not.toContain("Bozza salvata");
        expect(wrapper.text()).not.toContain("Corpo posizionale da sottoporre a Meta");
    });
});
