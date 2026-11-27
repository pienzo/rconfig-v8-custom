import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import LocalPresenceRuleForm from "../LocalPresenceRuleForm.vue";

function mountForm(props = {}) {
    return mount(LocalPresenceRuleForm, {
        props,
        global: {
            stubs: {
                DeviceMultiSelect: {
                    template: '<button type="button" @click="$emit(\'update:modelValue\', { id: 42, device_name: \'Synthetic\' })">Choose fixture device</button>',
                    emits: ["update:modelValue"],
                },
                ExternalBackupPatternPreview: true,
            },
        },
    });
}
const inputFor = (wrapper, label) => wrapper.findAll("label")
    .find(element => element.text().includes(label)).find("input");
const buttonFor = (wrapper, label) => wrapper.findAll("button").find(button => button.text() === label);

describe("Local Presence wizard form", () => {
    it("validates without emitting an invalid save", async () => {
        const wrapper = mountForm();
        await buttonFor(wrapper, "Save inactive rule").trigger("click");
        expect(wrapper.get('[role="alert"]').text()).toContain("rule name");
        expect(wrapper.emitted("save")).toBeUndefined();
        wrapper.unmount();
    });

    it("emits only a normalized dormant-rule payload with a single selected device", async () => {
        const wrapper = mountForm();
        await inputFor(wrapper, "Rule name").setValue("Synthetic wizard rule");
        await inputFor(wrapper, "Approved root alias").setValue("synthetic");
        await inputFor(wrapper, "Quiet period").setValue("");
        await buttonFor(wrapper, "Choose fixture device").trigger("click");
        await buttonFor(wrapper, "Save inactive rule").trigger("click");
        const payload = wrapper.emitted("save")[0][0];
        expect(payload.device_id).toBe(42);
        expect(payload.quiet_seconds).toBeNull();
        expect(payload.task_cron).toEqual(["0", "2", "*", "*", "*"]);
        expect(payload).not.toHaveProperty("task_command");
        expect(payload).not.toHaveProperty("enabled");
        expect(payload).not.toHaveProperty("task_email_notify");
        wrapper.unmount();
    });

    it("locks all footer actions during save and renders safe error text", () => {
        const wrapper = mountForm({ busy: true, error: "Rule creation is not allowed." });
        expect(wrapper.get("fieldset").attributes()).toHaveProperty("disabled");
        for (const label of ["Back to task types", "Cancel", "Saving..."]) {
            expect(buttonFor(wrapper, label).attributes()).toHaveProperty("disabled");
        }
        expect(wrapper.get('[role="alert"]').text()).toBe("Rule creation is not allowed.");
        wrapper.unmount();
    });
});
