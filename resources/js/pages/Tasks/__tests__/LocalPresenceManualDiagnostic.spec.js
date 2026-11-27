import { mount, flushPromises } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import axios from "axios";
import LocalPresenceManualDiagnostic from "../LocalPresenceManualDiagnostic.vue";

vi.mock("axios", () => ({ default: { get: vi.fn(), post: vi.fn() } }));
const base = "/api/tasks/local-backup-presence-rules/11/verify-now";
const oldId = "11111111-1111-4111-8111-111111111111";
const newId = "22222222-2222-4222-8222-222222222222";
const response = (status = "queued", id = oldId, changes = {}) => ({
    data: { data: {
        id, rule_id: 11, status, requested_at_utc: "2026-11-27T01:00:00Z",
        expires_at_utc: "2026-11-27T01:03:00Z",
        next_observation_at_utc: ["queued", "pending"].includes(status) ? "2026-11-27T01:00:30Z" : null,
        result: status === "queued" ? null : {
            reason: status === "pending" ? "stability_wait" : status === "present" ? "stable_matching_file_observed" : "no_matching_file",
            filename: status === "present" ? "backup.cfg" : null,
            observed_at_utc: "2026-11-27T01:00:30Z",
        },
        evidence_type: "metadata_presence", persistent_check_run: false, verified_success: false, ...changes,
    } },
    headers: { date: "Fri, 27 Nov 2026 01:00:00 GMT" },
});
let wrapper;
async function open(available = true) {
    wrapper = mount(LocalPresenceManualDiagnostic, { props: { ruleId: 11, available } });
    await flushPromises();
    return wrapper;
}
const button = label => wrapper.findAll("button").find(item => item.text() === label);
async function tick(ms = 5000) {
    await vi.advanceTimersByTimeAsync(ms);
    await flushPromises();
}
beforeEach(() => {
    vi.resetAllMocks();
    vi.useFakeTimers();
    axios.post.mockResolvedValue(response());
});
afterEach(() => {
    if (wrapper) wrapper.unmount();
    wrapper = undefined;
    vi.useRealTimers();
});

describe("asynchronous manual presence diagnostic", () => {
    it("does not admit when operator approval is closed", async () => {
        await open(false);
        expect(axios.post).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain("not approved");
    });

    it("admits once, polls only the exact UUID, renders pending and terminal evidence, and stops GET polling", async () => {
        axios.get.mockResolvedValueOnce(response("pending")).mockResolvedValueOnce(response("present"));
        await open();
        expect(axios.post).toHaveBeenCalledExactlyOnceWith(base, {}, { timeout: 10000 });
        expect(wrapper.text()).toContain("QUEUED");
        await tick();
        expect(axios.get).toHaveBeenCalledExactlyOnceWith(base + "/" + oldId, { timeout: 10000 });
        expect(wrapper.text()).toContain("PENDING");
        await tick();
        expect(wrapper.text()).toContain("FILE PRESENT");
        expect(wrapper.text()).toContain("backup.cfg");
        expect(wrapper.text()).toContain("not a verified backup");
        await tick(30000);
        expect(axios.get).toHaveBeenCalledTimes(2);
        expect(axios.post).toHaveBeenCalledTimes(1);
    });

    it("uses explicit terminal restart CAS and then polls the new UUID", async () => {
        axios.post.mockResolvedValueOnce(response("present")).mockResolvedValueOnce(response("queued", newId));
        axios.get.mockResolvedValue(response("absent", newId));
        await open();
        await button("Restart diagnostic").trigger("click");
        await flushPromises();
        expect(axios.post).toHaveBeenLastCalledWith(base + "/" + oldId + "/restart", {}, { timeout: 10000 });
        await tick();
        expect(axios.get).toHaveBeenCalledExactlyOnceWith(base + "/" + newId, { timeout: 10000 });
        expect(wrapper.text()).toContain("FILE ABSENT");
        expect(wrapper.text()).not.toContain("backup.cfg");
    });

    it("handles authoritative 404 without automatically admitting another session", async () => {
        axios.get.mockRejectedValue({ response: { status: 404 } });
        await open();
        await tick();
        expect(wrapper.text()).toContain("SESSION NO LONGER AVAILABLE");
        await tick(30000);
        expect(axios.get).toHaveBeenCalledTimes(1);
        expect(axios.post).toHaveBeenCalledTimes(1);
        axios.post.mockResolvedValueOnce(response("queued", newId));
        await button("Start or resume").trigger("click");
        await flushPromises();
        expect(axios.post).toHaveBeenLastCalledWith(base, {}, { timeout: 10000 });
    });

    it("uses server Date for advisory terminal expiry without creating another session", async () => {
        axios.post.mockResolvedValue(response("present"));
        await open();
        await tick(180000);
        expect(wrapper.text()).toContain("SESSION NO LONGER AVAILABLE");
        expect(button("Restart diagnostic")).toBeUndefined();
        expect(axios.post).toHaveBeenCalledTimes(1);
        expect(axios.get).not.toHaveBeenCalled();
    });

    it("never overlaps a slow poll and ignores its result on unmount", async () => {
        let resolve;
        axios.get.mockImplementation(() => new Promise(done => { resolve = done; }));
        await open();
        await tick();
        await tick(20000);
        expect(axios.get).toHaveBeenCalledTimes(1);
        wrapper.unmount();
        wrapper = undefined;
        resolve(response("pending"));
        await flushPromises();
        await tick(20000);
        expect(axios.get).toHaveBeenCalledTimes(1);
    });

    it("stops and clears state on approval withdrawal, including late responses", async () => {
        let resolve;
        axios.get.mockImplementation(() => new Promise(done => { resolve = done; }));
        await open();
        await tick();
        await wrapper.setProps({ available: false });
        resolve(response("present"));
        await flushPromises();
        await tick(20000);
        expect(wrapper.text()).not.toContain("FILE PRESENT");
        expect(axios.get).toHaveBeenCalledTimes(1);
    });

    it("requires explicit status resume after network failure and never leaks error details", async () => {
        axios.get.mockRejectedValueOnce(new Error("/synthetic/private-root"))
            .mockResolvedValueOnce(response("present"));
        await open();
        await tick();
        expect(wrapper.text()).toContain("No automatic retry");
        expect(wrapper.text()).not.toContain("/synthetic/private-root");
        await tick(20000);
        expect(axios.get).toHaveBeenCalledTimes(1);
        await button("Resume status").trigger("click");
        await flushPromises();
        expect(axios.get).toHaveBeenCalledTimes(2);
        expect(wrapper.text()).toContain("FILE PRESENT");
    });

    it("does not replay an uncertain restart; recovers only through explicit idempotent admission", async () => {
        axios.post.mockResolvedValueOnce(response("present"))
            .mockRejectedValueOnce({ response: { status: 409, data: { message: "/private/path" } } })
            .mockResolvedValueOnce(response("queued", newId));
        await open();
        await button("Restart diagnostic").trigger("click");
        await flushPromises();
        expect(wrapper.text()).toContain("Request refused");
        expect(wrapper.text()).not.toContain("/private/path");
        expect(button("Restart diagnostic")).toBeUndefined();
        await tick(20000);
        expect(axios.post).toHaveBeenCalledTimes(2);
        await button("Start or resume").trigger("click");
        await flushPromises();
        expect(axios.post).toHaveBeenLastCalledWith(base, {}, { timeout: 10000 });
    });

    it.each([{ rule_id: 12 }, { id: newId }, { verified_success: true }, { persistent_check_run: true }])(
        "refuses inconsistent polling response %j", async changes => {
            axios.get.mockResolvedValue(response("present", oldId, changes));
            await open();
            await tick();
            expect(wrapper.text()).not.toContain("FILE PRESENT");
            expect(wrapper.text()).toContain("No automatic retry");
            await tick(20000);
            expect(axios.get).toHaveBeenCalledTimes(1);
        }
    );

    it("stops at a rate limit without automatic retry", async () => {
        axios.get.mockRejectedValue({ response: { status: 429 } });
        await open();
        await tick();
        expect(wrapper.text()).toContain("Rate limit reached");
        await tick(20000);
        expect(axios.get).toHaveBeenCalledTimes(1);
    });
});
