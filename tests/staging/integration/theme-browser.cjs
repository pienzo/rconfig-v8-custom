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
    // Browser also shares only the internal app network namespace.
    await context.route("**/*", route => {
        const url = new URL(route.request().url());
        return url.origin === origin ? route.continue() : route.abort();
    });
    const page = await context.newPage();
    page.setDefaultTimeout(20000);
    let errors = 0;
    page.on("pageerror", () => { errors++; });
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

    async function inspectNav() {
        const nav = page.locator("#nav-panel-1");
        const labels = ["Dashboard", "Inventory", "Config Tools", "Tasks"];
        const ratios = {};
        for (const label of labels) {
            const element = nav.getByText(label, { exact: true }).first();
            await element.waitFor();
            ratios[label] = await element.evaluate(el => {
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
        evidence.push({ id, mode, ...result, nav });
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
            const response = await page.goto(origin + route);
            assert(response?.ok());
            await trigger.waitFor();
            await expectPalette(id, mode);
            assert.equal(new URL(page.url()).pathname, route);
            await waitForRoute(name);
            await page.screenshot({ path: "/evidence/" + id + "-" + name + ".png", animations: "disabled" });
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
                await page.screenshot({ path: "/evidence/" + id + "-pattern-preview.png", animations: "disabled" });
                await view.getByRole("button", { name: "Hide file pattern tester", exact: true }).click();

                stage = id + "-task-dialog";
                await view.getByRole("button", { name: /^New Task/ }).click();
                const dialog = page.getByRole("dialog");
                await dialog.waitFor();
                await dialog.getByText("Add Task", { exact: true }).waitFor();
                await page.screenshot({ path: "/evidence/" + id + "-task-dialog.png", animations: "disabled" });
                await page.keyboard.press("Escape");
                await dialog.waitFor({ state: "hidden" });
            }
        }
        stage = "return-dashboard-" + id;
        await page.goto(origin + "/dashboard");
        await trigger.waitFor();
        await expectPalette(id, mode);
        await waitForRoute("dashboard");
        console.log("THEME_BROWSER_PRESET_OK " + id + " (SYNTHETIC_ONLY)");
    }
    stage = "distinct-shells-and-js-errors";
    assert.equal(shells.size, 6);
    assert.equal(errors, 0);
    console.log("THEME_BROWSER_SIX_PRESETS_OK (SYNTHETIC_ONLY)");
    await context.close();
}

main().catch(() => {
    // No browser call logs, URLs, credentials, DOM contents or traces.
    console.error("THEME_BROWSER_FAILED stage=" + stage);
    process.exitCode = 1;
}).finally(async () => { if (browser) await browser.close(); });
