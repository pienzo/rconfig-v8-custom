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
        evidence.push({ id, mode, ...result });
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
            await page.waitForFunction(() => {
                const view = document.querySelector("#main-content .flex-grow.overflow-y-auto");
                return view && view.innerText.trim().length > 0;
            });
            await page.screenshot({ path: "/evidence/" + id + "-" + name + ".png", animations: "disabled" });
        }
        stage = "return-dashboard-" + id;
        await page.goto(origin + "/dashboard");
        await trigger.waitFor();
        await expectPalette(id, mode);
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
