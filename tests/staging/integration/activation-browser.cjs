const assert = require("node:assert/strict");
const fs = require("node:fs");
const { chromium } = require("playwright");

const origin = "http://127.0.0.1";
const base = "/api/tasks/local-backup-presence-rules";
const phase = process.env.RCONFIG_MANUAL_BROWSER_PHASE;
const name = "Synthetic browser scheduled presence";
const deviceName = "F02-HTTP-SYNTHETIC";
let browser;
let checkpoint = "guard";

async function main() {
    assert.equal(process.env.GITHUB_ACTIONS, "true");
    assert.equal(process.env.RUNNER_ENVIRONMENT, "github-hosted");
    assert.equal(process.env.RCONFIG_MANUAL_BROWSER, "synthetic-v1");
    assert(["create", "enable", "suspend"].includes(phase));
    assert(process.getuid() !== 0);
    assert.equal(require("playwright/package.json").version, "1.64.1");
    assert(!fs.existsSync("/mnt/f02-synthetic"));
    const secret = "/run/secrets/staging_admin_password";
    const stat = fs.lstatSync(secret);
    assert(stat.isFile() && stat.uid === 0 && stat.gid === 33 && (stat.mode & 0o777) === 0o640);
    const password = fs.readFileSync(secret, "utf8").trim();
    assert.match(password, /^[0-9a-f]{64}$/);

    browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, locale: "en-US",
        reducedMotion: "reduce", serviceWorkers: "block" });
    let allowedWrite = null;
    let writes = 0;
    let unexpected = 0;
    let errors = 0;
    // No route.fulfill, API mocks, APIRequestContext, or browser-evaluated
    // fetch. The real application is the only source of F02 requests.
    await context.route("**/*", route => {
        const request = route.request();
        const url = new URL(request.url());
        if (url.origin !== origin) return route.abort();
        const method = request.method();
        if (["GET", "HEAD"].includes(method)) return route.continue();
        if (method === "POST" && url.pathname === "/login" && checkpoint === "login") return route.continue();
        if (allowedWrite && method === allowedWrite.method && url.pathname === allowedWrite.path && !url.search) {
            let body;
            try { body = request.postData() ? request.postDataJSON() : null; } catch { unexpected++; return route.abort(); }
            if (!allowedWrite.validate(body)) {
                console.error("F02_BROWSER_ACTIVATION_PAYLOAD_REFUSED checkpoint=" + checkpoint);
                unexpected++;
                return route.abort();
            }
            allowedWrite = null;
            writes++;
            return route.continue();
        }
        unexpected++;
        return route.abort();
    });
    const page = await context.newPage();
    page.setDefaultTimeout(20000);
    page.on("pageerror", () => { errors++; });
    checkpoint = "login";
    let response;
    for (let attempt = 0; attempt < 30; attempt++) {
        try {
            response = await page.goto(origin + "/login", { timeout: 5000 });
            if (response?.ok()) break;
        } catch { /* bounded startup */ }
        await new Promise(resolve => setTimeout(resolve, 1000));
    }
    assert(response?.ok());
    await page.locator("#username").fill("staging-admin@example.invalid");
    await page.locator("#password").fill(password);
    await page.getByRole("button", { name: "Login", exact: true }).click();
    await page.waitForURL(url => url.pathname === "/dashboard", { timeout: 30000 });

    checkpoint = "tasks";
    assert((await page.goto(origin + "/tasks"))?.ok());
    await page.locator("#main-loading-container").waitFor({ state: "hidden" });
    const view = page.locator("#main-content .flex-grow.overflow-y-auto");
    await view.getByPlaceholder("Filter tasks...").waitFor();
    await view.getByText("Loading", { exact: true }).waitFor({ state: "hidden" });
    const mutate = async (method, path, status, validate, click) => {
        assert.equal(allowedWrite, null);
        const before = writes;
        allowedWrite = { method, path, validate };
        const pending = page.waitForResponse(r => r.request().method() === method && new URL(r.url()).pathname === path);
        await click();
        const result = await pending;
        // Only numeric status and fixed checkpoint; no response body, headers,
        // URL, UUID, credentials or arbitrary exception messages in logs.
        console.log("F02_BROWSER_ACTIVATION_HTTP checkpoint=" + checkpoint + " method=" + method
            + " expected=" + status + " actual=" + result.status());
        assert.equal(result.status(), status);
        assert.equal(writes, before + 1);
        assert.equal(allowedWrite, null);
        const json = await result.json();
        assert(!JSON.stringify(json).includes("/mnt/f02-synthetic"));
        return json;
    };
    let ruleId;
    if (phase === "create") {
        checkpoint = "wizard";
        await view.getByRole("button", { name: /^New Task/ }).click();
        const dialog = page.getByRole("dialog");
        await dialog.getByRole("button", { name: /^Local backup presence/ }).click();
        const form = dialog.getByRole("region", { name: "Local backup presence rule" });
        await form.getByLabel("Rule name", { exact: true }).fill(name);
        await form.getByLabel("Approved root alias", { exact: true }).fill("synthetic");
        await form.getByLabel("Relative directory (optional)", { exact: true }).fill("device");
        await form.getByLabel("Schedule (five cron fields, server timezone)", { exact: true }).fill("*/5 * * * *");
        await form.getByLabel("Observation deadline (seconds)", { exact: true }).fill("240");
        await form.getByText("Select devices", { exact: true }).click();
        const search = page.getByPlaceholder("Search...", { exact: true });
        await search.waitFor();
        await page.getByRole("dialog").filter({ has: search }).getByText(deviceName, { exact: true }).click();
        if (await search.isVisible()) await page.keyboard.press("Escape");
        await form.getByText(deviceName, { exact: true }).waitFor();
        const created = await mutate("POST", base, 201, data => {
            if (!Number.isSafeInteger(data?.device_id) || data.device_id < 1) return false;
            const expected = {
                device_id: data.device_id, task_name: name, task_cron: ["*/5", "*", "*", "*", "*"],
                directory_alias: "synthetic", relative_directory: "device", pattern_mode: "glob",
                filename_pattern: "backup_*.cfg", minimum_bytes: 1, maximum_age_seconds: null,
                quiet_seconds: 30, deadline_seconds: 240, max_entries: 1000, max_matches: 100, max_scan_millis: 2000,
            };
            return Object.keys(data).length === Object.keys(expected).length
                && Object.entries(expected).every(([key, value]) => JSON.stringify(data[key]) === JSON.stringify(value));
        }, () => form.getByRole("button", { name: "Save inactive rule", exact: true }).click());
        ruleId = created.data.id;
        assert(Number.isSafeInteger(ruleId) && ruleId > 0 && created.data.enabled === false
            && created.data.activated_at_utc === null && Number(created.data.task.is_paused) === 1);
        await dialog.waitFor({ state: "hidden" });
    }
    checkpoint = "management";
    const listResponse = page.waitForResponse(r => {
        const url = new URL(r.url());
        return r.request().method() === "GET" && url.pathname === base && url.searchParams.get("per_page") === "20";
    });
    await view.getByRole("button", { name: "Local Presence rules", exact: true }).click();
    const responseList = await listResponse;
    assert.equal(responseList.status(), 200);
    const list = await responseList.json();
    assert.equal(list.activation_available, true);
    assert.equal(list.manual_available, false);
    assert.equal(list.data.length, 1);
    const record = list.data[0];
    assert.equal(record.task.task_name, name);
    assert.equal(record.device.device_name, deviceName);
    assert.equal(record.enabled, phase === "suspend");
    assert.equal(Number(record.task.is_paused), phase === "suspend" ? 0 : 1);
    if (phase === "suspend") assert.equal(typeof record.activated_at_utc, "string");
    else assert.equal(record.activated_at_utc, null);
    if (ruleId) assert.equal(record.id, ruleId);
    ruleId = record.id;
    assert(Number.isSafeInteger(ruleId) && ruleId > 0);
    const rulePath = base + "/" + ruleId;
    const panel = view.getByRole("region", { name: "Local presence rules" });
    const row = panel.getByRole("listitem").filter({ hasText: name });
    await row.waitFor();
    assert(await row.getByRole("button", { name: "Verify Now", exact: true }).isDisabled());
    const control = row.getByRole("button", { name: phase === "suspend" ? "Suspend" : "Activate", exact: true });
    const confirm = row.getByRole("group", { name: "Confirm rule change" });
    const immutable = async () => {
        assert(await row.getByRole("button", { name: "Edit", exact: true }).isDisabled());
        assert(await row.getByRole("button", { name: "Delete", exact: true }).isDisabled());
    };
    const history = async () => {
        const pending = page.waitForResponse(r => r.request().method() === "GET"
            && new URL(r.url()).pathname === rulePath + "/runs");
        await row.getByRole("button", { name: "History", exact: true }).click();
        const result = await pending;
        assert.equal(result.status(), 200);
        const data = await result.json();
        assert.equal(data.evidence_type, "metadata_presence");
        assert.equal(data.data.length, 1);
        const run = data.data[0];
        assert.equal(run.rule_id, ruleId);
        assert.equal(run.device_id, record.device_id);
        assert.equal(run.status, "present");
        assert.equal(run.reason, "stable_matching_file_observed");
        assert.equal(run.matched_filename, "backup_a.cfg");
        assert(!Object.hasOwn(run, "criteria_snapshot") && !Object.hasOwn(run, "directory_identity"));
        await row.getByText("PRESENT", { exact: true }).waitFor();
        await row.getByText("File: backup_a.cfg", { exact: true }).waitFor();
        await row.getByText("UTC timestamps · metadata evidence only.", { exact: true }).waitFor();
        await row.getByRole("button", { name: "Close history", exact: true }).click();
        return run;
    };
    checkpoint = "confirmation";
    await row.getByText(phase === "suspend" ? "ACTIVE" : "INACTIVE DRAFT", { exact: true }).waitFor();
    await control.click();
    await confirm.waitFor();
    await confirm.getByRole("button", { name: "Cancel", exact: true }).click();
    await confirm.waitFor({ state: "hidden" });
    assert.equal(writes, phase === "create" ? 1 : 0);
    if (phase === "create") {
        console.log("F02_BROWSER_ACTIVATION_DRAFT_OK (SYNTHETIC_ONLY)");
    } else {
        let before;
        if (phase === "suspend") {
            checkpoint = "real-scheduled-history";
            await immutable();
            before = await history();
            console.log("F02_BROWSER_ACTIVATION_HISTORY_OK (SYNTHETIC_ONLY)");
        }
        checkpoint = phase === "enable" ? "real-activation" : "real-suspension";
        await control.click();
        const enabled = phase === "enable";
        const updated = await mutate("PATCH", rulePath + "/activation", 200,
            data => data && Object.keys(data).length === 1 && data.enabled === enabled,
            () => confirm.getByRole("button", { name: enabled ? "Confirm activation" : "Confirm suspension", exact: true }).click());
        assert.equal(updated.data.id, ruleId);
        assert.equal(updated.data.task_id, record.task_id);
        assert.equal(updated.data.device_id, record.device_id);
        assert.equal(updated.data.enabled, enabled);
        assert.equal(Number(updated.data.task.is_paused), enabled ? 0 : 1);
        assert.equal(typeof updated.data.activated_at_utc, "string");
        if (!enabled) assert.equal(updated.data.activated_at_utc, record.activated_at_utc);
        await panel.getByText(enabled ? "Rule activated for future scheduled checks." : "Rule suspended.", { exact: true }).waitFor();
        await row.getByText(enabled ? "ACTIVE" : "SUSPENDED", { exact: true }).waitFor();
        await row.getByRole("button", { name: enabled ? "Suspend" : "Activate", exact: true }).waitFor();
        await immutable();
        if (!enabled) {
            checkpoint = "preserved-history";
            assert.deepEqual(await history(), before);
            console.log("F02_BROWSER_ACTIVATION_SUSPEND_OK (SYNTHETIC_ONLY)");
        } else {
            console.log("F02_BROWSER_ACTIVATION_ENABLE_OK (SYNTHETIC_ONLY)");
        }
        await page.screenshot({ path: "/evidence/scheduled-" + phase + ".png", animations: "disabled" });
    }
    checkpoint = "final-gates";
    assert.equal(unexpected, 0);
    assert.equal(errors, 0);
    assert.equal(writes, 1);
    console.log("F02_BROWSER_ACTIVATION_PHASE_OK " + phase + " (SYNTHETIC_ONLY)");
    await context.close();
}
main().catch(error => {
    const kind = error?.name === "TimeoutError" ? "timeout" : error?.name === "AssertionError" ? "assertion" : "other";
    console.error("F02_BROWSER_ACTIVATION_FAILED phase=" + phase + " checkpoint=" + checkpoint + " kind=" + kind);
    process.exitCode = 1;
}).finally(async () => { if (browser) await browser.close(); });
