/* Hosted, isolated synthetic Chromium integration. No production/browser secrets. */
const assert = require("node:assert/strict");
const fs = require("node:fs");
const { chromium } = require("playwright");

const origin = "http://127.0.0.1";
const presets = [
    ["dark", "Classic Dark", "dark"],
    ["midnight", "Midnight Blue", "dark"],
    ["graphite", "Graphite", "dark"],
    ["light", "Classic Light", "light"],
    ["cloud", "Cloud Blue", "light"],
    ["sand", "Warm Sand", "light"],
];
const routes = [
    ["/dashboard", "dashboard"],
    ["/devices", "devices"],
    ["/tasks", "tasks"],
    ["/configs", "configs"],
    ["/config-compare", "compare"],
    ["/settings/notification-templates", "notifications"],
];

let stage = "identity";
let checkpoint = "start";
let browser;
async function main() {
    assert.equal(process.env.GITHUB_ACTIONS, "true");
    assert.equal(process.env.RUNNER_ENVIRONMENT, "github-hosted");
    assert.equal(process.env.RCONFIG_THEME_BROWSER, "synthetic-v1");
    assert.notEqual(process.getuid(), 0);
    assert.equal(require("playwright/package.json").version, "1.64.1");
    const secret = "/run/secrets/staging_admin_password";
    const stat = fs.lstatSync(secret);
    assert(stat.isFile() && stat.uid === 0 && stat.gid === 33 && (stat.mode & 0o777) === 0o640);
    const password = fs.readFileSync(secret, "utf8").trim();
    assert.match(password, /^[0-9a-f]{64}$/);

    stage = "launch";
    browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: { width: 1440, height: 1000 },
        deviceScaleFactor: 1,
        locale: "en-US",
        reducedMotion: "reduce",
        serviceWorkers: "block",
    });
    // Real authenticated application and live APIs are tested first. A
    // separate, explicitly labelled pass injects read-only fixture responses
    // only at the data boundary, to exercise populated rows and Monaco without
    // creating devices/tasks or invoking backups. Never intercept auth or writes.
    let fixtureMode = false;
    const fixtureHits = new Set();
    const device = {
        id: 990001, device_name: "THEME-FIXTURE-ROUTER", device_ip: "192.0.2.10",
        device_model: "Synthetic model", status: 1, device_added_by: 1,
        category: [], vendor: [], tag: [], config_summary: { total_count: 2, download_status_0_count: 0 },
    };
    const created = new Date().toISOString();
    const task = {
        id: 990002, task_name: "THEME-FIXTURE-PAUSED", task_desc: "Synthetic visual fixture",
        task_command: "rconfig:download-device", is_paused: 1, is_system: 0,
        device: [device], category: [], tag: [], task_email_notify: 0,
        download_report_notify: 0, verbose_download_report_notify: 0, cron_plain: "Daily",
        created_at: created,
    };
    const configs = [0, 1].map(i => ({
        id: 990003 + i, device_id: device.id, device_name: device.device_name,
        config_filename: i ? "theme-after.cfg" : "theme-before.cfg",
        command: "show running-config", config_version: i + 1, latest_version: i,
        download_status: 1, config_downloaded: 1, config_filesize: 80, created_at: created,
    }));
    const contents = ["hostname THEME-BEFORE\ninterface Loopback0\n description SYNTHETIC\n",
        "hostname THEME-AFTER\ninterface Loopback0\n description SYNTHETIC\n"];
    const paginated = data => ({ data, current_page: 1, last_page: 1, total: data.length, per_page: 10, from: 1, to: data.length });
    const fixtures = new Map([
        ["/api/devices", paginated([device])],
        ["/api/tasks", paginated([task])],
        ["/api/configs", paginated(configs)],
        ["/api/get-devices-filter-list", paginated([device])],
        ["/api/configs/distinct-commands/" + device.id, { data: [{ command: "show running-config" }] }],
        ["/api/configs/all-by-deviceid/" + device.id, paginated(configs)],
        ...configs.flatMap((cfg, i) => [
            ["/api/configs/" + cfg.id, cfg],
            ["/api/configs/view-config/" + cfg.id, { data: { content: contents[i] } }],
        ]),
    ]);
    await context.route("**/*", route => {
        const url = new URL(route.request().url());
        if (url.origin !== origin) return route.abort();
        if (fixtureMode && route.request().method() === "GET" && fixtures.has(url.pathname)) {
            fixtureHits.add(url.pathname);
            return route.fulfill({ json: fixtures.get(url.pathname) });
        }
        // No real state-changing request during the fixture phase.
        if (fixtureMode && !["GET", "HEAD"].includes(route.request().method())) return route.abort();
        return route.continue();
    });
    const page = await context.newPage();
    page.setDefaultTimeout(20000);
    let errors = 0;
    const pageErrors = [];
    // Preserve the zero-uncaught-exception gate; record only safe static
    // classifications and same-origin build asset frames, never raw messages.
    page.on("pageerror", error => {
        errors++;
        const message = String(error?.message ?? "");
        const kind = ["TypeError", "ReferenceError", "SyntaxError", "RangeError", "Error", "DOMException"].includes(error?.name)
            ? error.name : "other";
        const signature = /ResizeObserver loop/i.test(message) ? "resize-observer"
            : /MonacoEnvironment|getWorkerUrl|web worker/i.test(message) ? "editor-worker"
            : /Cannot read properties of (undefined|null).*status/i.test(message) ? "missing-response-status"
            : /Cannot read properties of (undefined|null)/i.test(message) ? "missing-property"
            : /is not a function/i.test(message) ? "not-a-function"
            : /AbortError|aborted/i.test(message) ? "aborted"
            : "unclassified";
        const frames = [...String(error?.stack ?? "").matchAll(/http:\/\/127\.0\.0\.1\/build\/assets\/([\w.-]{1,100}\.js):(\d{1,8}):(\d{1,8})/g)]
            .slice(0, 4).map(([, asset, line, column]) => ({ asset, line, column }));
        const entry = { stage, checkpoint, kind, signature, frames };
        if (pageErrors.length < 50) pageErrors.push(entry);
        if (errors <= 50) console.error("THEME_PAGE_ERROR " + JSON.stringify(entry));
    });
    stage = "login";
    let response;
    for (let attempt = 0; attempt < 30; attempt++) {
        try {
            response = await page.goto(origin + "/login", { timeout: 5000 });
            if (response?.ok()) break;
        } catch { /* bounded Apache readiness */ }
        await new Promise(resolve => setTimeout(resolve, 1000));
    }
    assert(response?.ok());
    await page.locator("#username").waitFor();
    await page.evaluate(() => localStorage.setItem("rconfig-theme-preset", "sand"));
    await page.reload();
    await page.locator("#username").waitFor();
    assert.equal(await page.locator("html").getAttribute("data-palette"), "dark");
    assert.equal(await page.evaluate(() => localStorage.getItem("rconfig-theme-preset")), "sand");
    await page.locator("#username").fill("staging-admin@example.invalid");
    await page.locator("#password").fill(password);
    await page.getByRole("button", { name: "Login", exact: true }).click();
    await page.waitForURL(url => url.pathname === "/dashboard", { timeout: 30000 });
    const trigger = page.getByRole("button", { name: "Choose appearance theme", exact: true });
    await trigger.waitFor();
    const view = page.locator("#main-content .flex-grow.overflow-y-auto");
    async function waitForRoute(name) {
        // The global loading screen occupies a full viewport even when
        // controls below it are technically visible to Playwright.
        await page.locator("#main-loading-container").waitFor({ state: "hidden" });
        await page.locator("#main-content").waitFor({ state: "visible" });
        const markers = {
            dashboard: view.getByText("Device Count", { exact: true }),
            devices: view.getByPlaceholder("Filter [id, name, ip]..."),
            tasks: view.getByPlaceholder("Filter tasks..."),
            configs: view.getByPlaceholder("Filter [id, device_name]"),
            compare: view.getByRole("heading", { name: "Config Compare", exact: true }),
            notifications: view.getByRole("region", { name: "Notification template editor" }),
        };
        const marker = markers[name];
        await marker.waitFor();
        await view.getByText("Loading", { exact: true }).waitFor({ state: "hidden" });
        await page.waitForFunction(() => {
            const nav = document.querySelector("#nav-panel-1");
            const rect = nav?.getBoundingClientRect();
            const main = document.querySelector("#main-content")?.getBoundingClientRect();
            return rect && main && rect.width > 0 && rect.top < innerHeight
                && rect.bottom > 0 && main.top >= -1 && main.top < 10;
        });
        await page.evaluate(() => document.fonts.ready);
        await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    }

    async function elementContrast(element) {
        await element.waitFor();
        return element.evaluate(el => {
            const ctx = document.createElement("canvas").getContext("2d", { willReadFrequently: true });
            const ancestors = [];
            for (let node = el; node; node = node.parentElement) ancestors.unshift(node);
            ctx.fillStyle = "#fff";
            ctx.fillRect(0, 0, 1, 1);
            for (const node of ancestors) {
                ctx.fillStyle = getComputedStyle(node).backgroundColor;
                ctx.fillRect(0, 0, 1, 1);
            }
            const luminance = () => [...ctx.getImageData(0, 0, 1, 1).data].slice(0, 3)
                .map(v => v / 255)
                .map(v => v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4)
                .reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
            const bg = luminance();
            ctx.fillStyle = getComputedStyle(el).color;
            ctx.fillRect(0, 0, 1, 1);
            const fg = luminance();
            return (Math.max(fg, bg) + 0.05) / (Math.min(fg, bg) + 0.05);
        });
    }

    async function requireContrast(id, name, element, measurements) {
        checkpoint = "contrast-" + name;
        const ratio = await elementContrast(element);
        measurements[name] = ratio;
        console.log("THEME_COMPONENT_CONTRAST " + id + " " + name + " ratio=" + ratio.toFixed(3));
        if (!(ratio >= 4.5)) {
            console.error("THEME_COMPONENT_CONTRAST_FAIL " + id + " " + name + " ratio=" + ratio.toFixed(3));
            throw new Error("component contrast below 4.5");
        }
    }

    async function inspectNav() {
        const nav = page.locator("#nav-panel-1");
        const labels = ["Dashboard", "Inventory", "Config Tools", "Tasks"];
        const ratios = {};
        for (const label of labels) {
            const element = nav.getByText(label, { exact: true }).first();
            await element.waitFor();
            ratios[label] = await elementContrast(element);
        }
        const logo = nav.getByAltText("rConfig", { exact: true });
        await logo.waitFor();
        await page.waitForFunction(() => document.querySelector("#nav-panel-1 img[alt='rConfig']")?.naturalWidth > 0);
        const logoFilter = await logo.evaluate(el => getComputedStyle(el).filter);
        return { ratios, logoFilter };
    }

    async function expectPalette(id, mode) {
        await page.waitForFunction(({ id, mode }) => {
            const root = document.documentElement;
            return root.dataset.palette === id && root.dataset.theme === mode
                && root.classList.contains("dark") === (mode === "dark")
                && root.style.colorScheme === mode;
        }, { id, mode });
        assert.equal(await page.evaluate(() => localStorage.getItem("rconfig-theme-preset")), id);
    }
    await expectPalette("sand", "light");
    await waitForRoute("dashboard");
    console.log("THEME_FORCED_LOGIN_PREFERENCE_OK (SYNTHETIC_ONLY)");
    const evidence = [];
    const shells = new Set();

    async function inspectTokens() {
        return page.evaluate(() => {
            const root = document.documentElement;
            const style = getComputedStyle(root);
            const canvas = document.createElement("canvas");
            const ctx = canvas.getContext("2d");
            function color(variable, hsl = true) {
                const value = style.getPropertyValue(variable).trim();
                if (!value) throw new Error("missing color token");
                ctx.fillStyle = "#010203";
                ctx.fillStyle = hsl ? "hsl(" + value + ")" : value;
                const hex = ctx.fillStyle;
                if (!/^#[0-9a-f]{6}$/i.test(hex) || hex === "#010203") throw new Error("invalid color token");
                const rgb = hex.slice(1).match(/../g).map(x => parseInt(x, 16) / 255);
                return rgb.map(v => v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4)
                    .reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
            }
            const pairs = [
                ["background", "--foreground", "--background", true],
                ["card", "--card-foreground", "--card", true],
                ["popover", "--popover-foreground", "--popover", true],
                ["muted", "--muted-foreground", "--muted", false],
                ["secondary", "--secondary-foreground", "--secondary", true],
                ["accent", "--accent-foreground", "--accent", true],
            ];
            const contrast = Object.fromEntries(pairs.map(([name, fg, bg, hsl]) => {
                const a = color(fg, hsl), b = color(bg);
                return [name, (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)];
            }));
            return { contrast, shell: getComputedStyle(document.querySelector("#main-content")).backgroundColor };
        });
    }

    for (const [id, label, mode] of presets) {
        stage = "select-" + id;
        await trigger.click();
        const menu = page.getByRole("menu");
        await menu.waitFor();
        assert.equal(await menu.getByRole("menuitem").count(), 6);
        await menu.getByText(label, { exact: true }).click();
        await expectPalette(id, mode);
        await page.reload();
        await trigger.waitFor();
        await expectPalette(id, mode);
        await waitForRoute("dashboard");
        await trigger.focus();
        await page.keyboard.press("Enter");
        await menu.waitFor();
        assert.equal(await menu.getByText(label, { exact: true }).locator("..").getByLabel("Selected").count(), 1);
        await page.screenshot({ path: "/evidence/" + id + "-menu.png", animations: "disabled" });
        await page.keyboard.press("Escape");
        await menu.waitFor({ state: "hidden" });
        assert(await trigger.evaluate(el => document.activeElement === el));
        stage = "contrast-" + id;
        const result = await inspectTokens();
        const nav = await inspectNav();
        const components = {};
        evidence.push({ id, mode, ...result, nav, components });
        for (const [label, ratio] of Object.entries(nav.ratios)) {
            if (!(ratio >= 4.5)) {
                console.error("THEME_NAV_CONTRAST_FAIL " + id + " " + label + " ratio=" + ratio.toFixed(3));
                throw new Error("navigation contrast below 4.5");
            }
        }
        if (mode === "light") assert.match(nav.logoFilter, /brightness\(0\)/);
        if (mode === "dark") assert.doesNotMatch(nav.logoFilter, /brightness\(0\)/);
        shells.add(result.shell);
        fs.writeFileSync("/evidence/contrast.json", JSON.stringify(evidence, null, 2));
        for (const [pair, ratio] of Object.entries(result.contrast)) {
            if (!(ratio >= 4.5)) {
                console.error("THEME_TOKEN_CONTRAST_FAIL " + id + " " + pair + " ratio=" + ratio.toFixed(3));
                throw new Error("contrast below 4.5");
            }
        }
        for (const [route, name] of routes) {
            stage = id + "-" + name;
            checkpoint = "route-load";
            const response = await page.goto(origin + route);
            assert(response?.ok());
            await trigger.waitFor();
            await expectPalette(id, mode);
            assert.equal(new URL(page.url()).pathname, route);
            await waitForRoute(name);
            await page.screenshot({ path: "/evidence/" + id + "-" + name + ".png", animations: "disabled" });
            if (name === "notifications") {
                const panel = view.getByRole("region", { name: "Notification template editor" });
                const warning = panel.locator("p").filter({ hasText: "I template Email e Telegram sono collegati al sistema automatico;" });
                assert.equal(await warning.count(), 1);
                await requireContrast(id, "notification-warning", warning, components);
            }
            if (name === "tasks") {
                stage = id + "-pattern-preview";
                await view.getByRole("button", { name: "Test backup file pattern", exact: true }).click();
                const tester = view.getByRole("region", { name: "External backup filename pattern tester" });
                await tester.waitFor();
                await tester.locator("#f02-preview-pattern").fill("backup_*.cfg");
                await tester.locator("#f02-preview-filenames").fill("backup_router.cfg\nother.txt");
                await tester.getByRole("button", { name: "Preview matches", exact: true }).click();
                const results = tester.getByRole("list", { name: "Filename match results" });
                await results.waitFor();
                assert.equal(await results.getByText("MATCH", { exact: true }).count(), 1);
                assert.equal(await results.getByText("NO MATCH", { exact: true }).count(), 1);
                await requireContrast(id, "match", results.getByText("MATCH", { exact: true }), components);
                await requireContrast(id, "no-match", results.getByText("NO MATCH", { exact: true }), components);
                await page.screenshot({ path: "/evidence/" + id + "-pattern-preview.png", animations: "disabled" });
                await view.getByRole("button", { name: "Hide file pattern tester", exact: true }).click();

                stage = id + "-task-dialog";
                await view.getByRole("button", { name: /^New Task/ }).click();
                const dialog = page.getByRole("dialog");
                await dialog.waitFor();
                await dialog.getByText("Add Task", { exact: true }).waitFor();
                const category = dialog.locator(".result-section").getByText("Config Downloads", { exact: true });
                await requireContrast(id, "wizard-category", category, components);
                await category.click();
                await dialog.getByText("Devices", { exact: true }).waitFor();
                await page.screenshot({ path: "/evidence/" + id + "-task-dialog.png", animations: "disabled" });
                await dialog.getByText("Devices", { exact: true }).click();
                await dialog.getByRole("button", { name: "Continue with Devices" }).click();
                await dialog.getByRole("heading", { name: "Enter Task Details" }).waitFor();
                await dialog.locator("#task_name").fill("THEME-UNSAVED");
                await requireContrast(id, "wizard-step", dialog.getByText("Task Info", { exact: true }), components);
                await page.screenshot({ path: "/evidence/" + id + "-task-details.png", animations: "disabled" });
                await page.keyboard.press("Escape");
                await dialog.waitFor({ state: "hidden" });
            }
        }
        // Fixture pass: actual Vue/Monaco rendering, not real API storage or
        // device operations. Keep the real empty-state screenshots above.
        fixtureMode = true;
        fixtureHits.clear();
        try {
            for (const [route, name, value] of [
                ["/devices", "devices", device.device_name],
                ["/tasks", "tasks", task.task_name],
                ["/configs", "configs", configs[0].config_filename],
            ]) {
                stage = id + "-fixture-" + name;
                assert((await page.goto(origin + route))?.ok());
                await waitForRoute(name);
                await expectPalette(id, mode);
                const row = view.getByRole("row").filter({ hasText: value });
                await row.waitFor();
                await requireContrast(id, "fixture-" + name, row.getByText(value, { exact: true }), components);
                await page.screenshot({ path: "/evidence/" + id + "-fixture-" + name + ".png", animations: "disabled" });
            }
            stage = id + "-fixture-diff-filter";
            checkpoint = "compare-route";
            await page.evaluate(() => {
                localStorage.setItem("rConfig.editordarkmode", "vs");
                localStorage.setItem("rConfig.editorHideUnchanged", "false");
            });
            assert((await page.goto(origin + "/config-compare"))?.ok());
            await waitForRoute("compare");
            for (let side = 0; side < 2; side++) {
                // Both cards start with the same placeholder; after the first
                // selection only the second unselected trigger remains.
                checkpoint = "select-device-" + side;
                await view.getByText("Select devices", { exact: true }).first().click();
                const search = page.getByPlaceholder("Search...", { exact: true });
                await search.waitFor();
                // One known fixture is already in the initial response; no
                // debounced search/re-fetch needed for this visual scenario.
                const popover = page.getByRole("dialog").filter({ has: search });
                await popover.getByText(device.device_name, { exact: true }).click();
                if (await search.isVisible()) await page.keyboard.press("Escape");
                await search.waitFor({ state: "hidden" });
                checkpoint = "filter-device-" + side;
                await view.getByRole("button", { name: "Filter", exact: true }).nth(side).click();
                await view.getByRole("row").filter({ hasText: configs[side].config_filename }).nth(side).waitFor();
                await view.getByRole("row").filter({ hasText: configs[side].config_filename }).nth(side).click();
            }
            stage = id + "-fixture-diff-editor";
            checkpoint = "open-compare";
            await view.getByRole("button", { name: "Compare", exact: true }).click();
            const editor = page.locator("#code-editor__code-pre .monaco-diff-editor");
            await editor.waitFor();
            const originalPane = editor.locator(".editor.original");
            const modifiedPane = editor.locator(".editor.modified");
            const before = originalPane.locator(".view-lines").filter({ hasText: "THEME-BEFORE" });
            const after = modifiedPane.locator(".view-lines").filter({ hasText: "THEME-AFTER" });
            await before.waitFor();
            await after.waitFor();
            async function expectVisibleDiffPanes() {
                const bounds = await editor.boundingBox();
                const left = await originalPane.boundingBox(), right = await modifiedPane.boundingBox();
                const viewport = page.viewportSize();
                assert(bounds && left && right && viewport);
                assert(left.width > 100 && right.width > 100);
                assert(bounds.x >= -1 && bounds.x + bounds.width <= viewport.width + 1);
                assert(left.x >= bounds.x - 1 && right.x >= left.x + left.width - 2
                    && right.x + right.width <= bounds.x + bounds.width + 2);
            }
            checkpoint = "verify-diff-panes";
            await expectVisibleDiffPanes();
            await page.getByText("Comparing configurations...", { exact: true }).waitFor({ state: "hidden" });
            for (const title of ["Copy compared configuration sources", "Export compared configuration sources", "Toggle editor dark mode", "Line Numbers", "Show only differences"]) {
                const control = page.getByTitle(title, { exact: true });
                await control.waitFor();
                await control.locator("svg").waitFor();
            }
            assert(fixtureHits.has("/api/configs/view-config/" + configs[0].id));
            assert(fixtureHits.has("/api/configs/view-config/" + configs[1].id));
            await editor.locator(".monaco-editor.vs").first().waitFor();
            await page.screenshot({ path: "/evidence/" + id + "-fixture-diff-light.png", animations: "disabled" });
            checkpoint = "toggle-editor-mode";
            await page.getByTitle("Toggle editor dark mode", { exact: true }).click();
            await editor.locator(".monaco-editor.vs-dark").first().waitFor();
            await expectPalette(id, mode);
            await expectVisibleDiffPanes();
            await page.screenshot({ path: "/evidence/" + id + "-fixture-diff-dark.png", animations: "disabled" });
            for (const key of ["/api/devices", "/api/tasks", "/api/configs", "/api/get-devices-filter-list",
                "/api/configs/all-by-deviceid/" + device.id]) assert(fixtureHits.has(key));
            console.log("THEME_BROWSER_POPULATED_FIXTURE_OK " + id + " (MOCKED_READ_API_ONLY)");
        } finally {
            fixtureMode = false;
        }
        fs.writeFileSync("/evidence/contrast.json", JSON.stringify(evidence, null, 2));
        stage = "return-dashboard-" + id;
        await page.goto(origin + "/dashboard");
        await trigger.waitFor();
        await expectPalette(id, mode);
        await waitForRoute("dashboard");
        console.log("THEME_BROWSER_PRESET_OK " + id + " (SYNTHETIC_ONLY)");
    }
    stage = "distinct-shells-and-js-errors";
    checkpoint = "distinct-shells";
    console.log("THEME_DISTINCT_SHELLS count=" + shells.size);
    assert.equal(shells.size, 6);
    checkpoint = "uncaught-page-errors";
    console.log("THEME_UNCAUGHT_PAGE_ERRORS count=" + errors);
    fs.writeFileSync("/evidence/page-errors.json", JSON.stringify({ count: errors, entries: pageErrors }, null, 2));
    assert.equal(errors, 0);
    console.log("THEME_BROWSER_SIX_PRESETS_OK (SYNTHETIC_ONLY)");
    await context.close();
}

main().catch(error => {
    // Only allowlisted error classifications and static probe labels.
    // No browser call logs, URLs, credentials, DOM contents or traces.
    const kind = error?.name === "TimeoutError" ? "timeout"
        : error?.name === "AssertionError" ? "assertion" : "other";
    console.error("THEME_BROWSER_FAILED stage=" + stage + " checkpoint=" + checkpoint + " kind=" + kind);
    process.exitCode = 1;
}).finally(async () => { if (browser) await browser.close(); });
