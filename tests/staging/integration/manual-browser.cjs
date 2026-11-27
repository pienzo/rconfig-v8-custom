const assert = require("node:assert/strict");
const fs = require("node:fs");
const { chromium } = require("playwright");

const origin = "http://127.0.0.1";
const base = "/api/tasks/local-backup-presence-rules";
const phase = process.env.RCONFIG_MANUAL_BROWSER_PHASE;
const name = "Synthetic HTTP manual diagnostic";
const deviceName = "F02-HTTP-SYNTHETIC";
const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
let browser;
let checkpoint = "guard";

async function main() {
    assert.equal(process.env.GITHUB_ACTIONS, "true");
    assert.equal(process.env.RUNNER_ENVIRONMENT, "github-hosted");
    assert.equal(process.env.RCONFIG_MANUAL_BROWSER, "synthetic-v1");
    assert(["create", "present", "restart", "delete"].includes(phase));
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
            if (!allowedWrite.validate(body)) { unexpected++; return route.abort(); }
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
    const emptyBody = data => data === null || (data && typeof data === "object" && Object.keys(data).length === 0);
    const mutate = async (method, path, status, validate, click) => {
        assert.equal(allowedWrite, null);
        const before = writes;
        allowedWrite = { method, path, validate };
        const pending = page.waitForResponse(r => r.request().method() === method && new URL(r.url()).pathname === path);
        await click();
        const result = await pending;
        assert.equal(result.status(), status);
        assert.equal(writes, before + 1);
        assert.equal(allowedWrite, null);
        const json = await result.json();
        assert(!JSON.stringify(json).includes("/mnt/f02-synthetic"));
        return json;
    };
    const exactPayload = (data, creating) => {
        const expected = {
            task_name: creating ? "Synthetic browser draft" : name,
            task_cron: ["0", "2", "*", "*", "*"], directory_alias: "synthetic", relative_directory: "device",
            pattern_mode: "glob", filename_pattern: "backup_*.cfg", minimum_bytes: creating ? 1 : 2,
            maximum_age_seconds: null, quiet_seconds: 30, deadline_seconds: 420,
            max_entries: 1000, max_matches: 100, max_scan_millis: 2000,
        };
        if (creating) {
            if (!Number.isSafeInteger(data?.device_id) || data.device_id < 1) return false;
            expected.device_id = data.device_id;
        }
        return data && Object.keys(data).length === Object.keys(expected).length
            && Object.entries(expected).every(([key, value]) => JSON.stringify(data[key]) === JSON.stringify(value));
    };
    const checkDiagnostic = (data, ruleId, status) => {
        assert(data && uuid.test(data.id) && data.rule_id === ruleId && data.status === status);
        assert.equal(data.evidence_type, "metadata_presence");
        assert.equal(data.verified_success, false);
        assert.equal(data.persistent_check_run, false);
        assert(!Object.hasOwn(data, "continuation") && !Object.hasOwn(data, "dispatch_not_before"));
        return data.id;
    };
    let ruleId;
    if (phase === "create") {
        checkpoint = "wizard";
        await view.getByRole("button", { name: /^New Task/ }).click();
        const dialog = page.getByRole("dialog");
        await dialog.getByRole("button", { name: /^Local backup presence/ }).click();
        const form = dialog.getByRole("region", { name: "Local backup presence rule" });
        await form.getByLabel("Rule name", { exact: true }).fill("Synthetic browser draft");
        await form.getByLabel("Approved root alias", { exact: true }).fill("synthetic");
        await form.getByLabel("Relative directory (optional)", { exact: true }).fill("device");
        await form.getByLabel("Observation deadline (seconds)", { exact: true }).fill("420");
        await form.getByText("Select devices", { exact: true }).click();
        const search = page.getByPlaceholder("Search...", { exact: true });
        await search.waitFor();
        await page.getByRole("dialog").filter({ has: search }).getByText(deviceName, { exact: true }).click();
        if (await search.isVisible()) await page.keyboard.press("Escape");
        await form.getByText(deviceName, { exact: true }).waitFor();
        checkpoint = "real-pattern-preview";
        await form.getByText("Test example filenames", { exact: true }).click();
        const tester = form.getByRole("region", { name: "External backup filename pattern tester" });
        await tester.locator("#f02-preview-filenames").fill("backup_a.cfg\nother.txt");
        const preview = await mutate("POST", "/api/tasks/local-backup-presence-pattern-preview", 200,
            body => body?.pattern_mode === "glob" && body.filename_pattern === "backup_*.cfg"
                && JSON.stringify(body.filenames) === JSON.stringify(["backup_a.cfg", "other.txt"]),
            () => tester.getByRole("button", { name: "Preview matches", exact: true }).click());
        assert.equal(preview.results.filter(item => item.matches).length, 1);
        await tester.getByText("MATCH", { exact: true }).waitFor();
        await tester.getByText("NO MATCH", { exact: true }).waitFor();
        checkpoint = "real-create";
        const created = await mutate("POST", base, 201, data => exactPayload(data, true),
            () => form.getByRole("button", { name: "Save inactive rule", exact: true }).click());
        ruleId = created.data.id;
        assert(Number.isSafeInteger(ruleId) && ruleId > 0 && created.data.enabled === false && created.data.activated_at_utc === null);
        await dialog.waitFor({ state: "hidden" });
        console.log("F02_BROWSER_REAL_WIZARD_CREATE_OK (SYNTHETIC_ONLY)");
    }

    checkpoint = "management";
    const listResponse = page.waitForResponse(r => r.request().method() === "GET" && new URL(r.url()).pathname === base);
    await view.getByRole("button", { name: "Local Presence rules", exact: true }).click();
    const list = await (await listResponse).json();
    assert.equal(list.manual_available, true);
    assert.equal(list.activation_available, false);
    assert.equal(list.data.length, 1);
    const record = list.data[0];
    assert.equal(record.task.task_name, phase === "create" ? "Synthetic browser draft" : name);
    assert.equal(record.device.device_name, deviceName);
    assert.equal(record.enabled, false);
    assert.equal(record.activated_at_utc, null);
    if (ruleId) assert.equal(record.id, ruleId);
    ruleId = record.id;
    assert(Number.isSafeInteger(ruleId) && ruleId > 0);
    const rulePath = base + "/" + ruleId;
    const manualPath = rulePath + "/verify-now";
    const panel = view.getByRole("region", { name: "Local presence rules" });
    const row = panel.getByRole("listitem").filter({ hasText: phase === "create" ? "Synthetic browser draft" : name });
    await row.waitFor();
    assert(await row.getByRole("button", { name: "Activate", exact: true }).isDisabled());
    if (phase === "create") {
        checkpoint = "real-edit";
        await row.getByRole("button", { name: "Edit", exact: true }).click();
        const form = row.getByRole("region", { name: "Local backup presence rule" });
        await form.getByText(deviceName + " (fixed)", { exact: true }).waitFor();
        await form.getByLabel("Rule name", { exact: true }).fill(name);
        await form.getByLabel("Minimum size (bytes)", { exact: true }).fill("2");
        const updated = await mutate("PATCH", rulePath, 200, data => exactPayload(data, false),
            () => form.getByRole("button", { name: "Update inactive rule", exact: true }).click());
        assert.equal(updated.data.id, ruleId);
        assert.equal(updated.data.device_id, record.device_id);
        assert.equal(updated.data.task_id, record.task_id);
        assert.equal(updated.data.minimum_bytes, 2);
        await panel.getByText("Inactive rule updated.", { exact: true }).waitFor();
        console.log("F02_BROWSER_REAL_RULE_EDIT_OK (SYNTHETIC_ONLY)");
    }
    const current = panel.getByRole("listitem").filter({ hasText: name });
    await current.waitFor();
    const verify = current.getByRole("button", { name: "Verify Now", exact: true });
    const diagnostic = current.getByRole("region", { name: "Manual presence diagnostic" });
    const close = async () => {
        await diagnostic.getByRole("button", { name: "Close diagnostic", exact: true }).click();
        await diagnostic.waitFor({ state: "hidden" });
    };
    if (phase === "delete") {
        checkpoint = "real-history-delete";
        await current.getByRole("button", { name: "History", exact: true }).click();
        await current.getByText("No scheduled observations recorded.", { exact: true }).waitFor();
        await current.getByRole("button", { name: "Close history", exact: true }).click();
        await current.getByRole("button", { name: "Delete", exact: true }).click();
        await mutate("DELETE", rulePath, 200, emptyBody,
            () => current.getByRole("button", { name: "Confirm deletion", exact: true }).click());
        await panel.getByText("No Local Presence rules configured.", { exact: true }).waitFor();
        console.log("F02_BROWSER_REAL_RULE_DELETE_OK (SYNTHETIC_ONLY)");
    } else {
        checkpoint = "real-admission";
        const initial = await mutate("POST", manualPath, 202, emptyBody, () => verify.click());
        const initialStatus = phase === "restart" ? "present" : initial.data.status;
        assert(phase === "present" ? ["queued", "pending"].includes(initialStatus)
            : initialStatus === (phase === "create" ? "queued" : "present"));
        const old = checkDiagnostic(initial.data, ruleId, initialStatus);
        if (phase === "create") {
            await diagnostic.getByText("QUEUED", { exact: true }).waitFor();
            await close();
            const resumed = await mutate("POST", manualPath, 202, emptyBody, () => verify.click());
            assert.equal(checkDiagnostic(resumed.data, ruleId, "queued"), old);
            await diagnostic.getByText("QUEUED", { exact: true }).waitFor();
            await close();
            console.log("F02_BROWSER_REAL_ADMISSION_REPLAY_OK (SYNTHETIC_ONLY)");
        } else if (phase === "present") {
            checkpoint = "real-pending-present";
            await diagnostic.getByText(/^PENDING — waiting for stability$/).waitFor({ timeout: 110000 });
            await diagnostic.getByText("FILE PRESENT", { exact: true }).waitFor({ timeout: 110000 });
            await diagnostic.getByText("File: backup_a.cfg", { exact: true }).waitFor();
            await diagnostic.getByText("Stable matching file observed.", { exact: true }).waitFor();
            await page.screenshot({ path: "/evidence/real-present.png", animations: "disabled" });
            await close();
            console.log("F02_BROWSER_REAL_STABLE_PRESENT_OK (SYNTHETIC_ONLY)");
        } else {
            checkpoint = "real-restart";
            await diagnostic.getByText("FILE PRESENT", { exact: true }).waitFor();
            const replacement = await mutate("POST", manualPath + "/" + old + "/restart", 202, emptyBody,
                () => diagnostic.getByRole("button", { name: "Restart diagnostic", exact: true }).click());
            assert.notEqual(checkDiagnostic(replacement.data, ruleId, "queued"), old);
            await diagnostic.getByText("FILE ABSENT", { exact: true }).waitFor({ timeout: 110000 });
            await diagnostic.getByText("No matching file found.", { exact: true }).waitFor();
            await page.screenshot({ path: "/evidence/real-absent.png", animations: "disabled" });
            await close();
            console.log("F02_BROWSER_REAL_RESTART_ABSENT_OK (SYNTHETIC_ONLY)");
        }
    }
    checkpoint = "final-gates";
    assert.equal(unexpected, 0);
    assert.equal(errors, 0);
    assert.equal(writes, { create: 5, present: 1, restart: 2, delete: 1 }[phase]);
    console.log("F02_BROWSER_REAL_PHASE_OK " + phase + " (SYNTHETIC_ONLY)");
    await context.close();
}

main().catch(error => {
    const kind = error?.name === "TimeoutError" ? "timeout" : error?.name === "AssertionError" ? "assertion" : "other";
    console.error("F02_BROWSER_REAL_FAILED phase=" + phase + " checkpoint=" + checkpoint + " kind=" + kind);
    process.exitCode = 1;
}).finally(async () => { if (browser) await browser.close(); });
