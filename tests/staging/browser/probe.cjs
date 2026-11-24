const { chromium } = require("playwright");
const assert = require("node:assert/strict");

const origin = "http://127.0.0.1:8080";
const password = process.env.RCONFIG_SYNTHETIC_BROWSER_PASSWORD;
let stage = "identity";

async function main() {
    assert.equal(process.env.RCONFIG_BROWSER_CI, "disposable-v1");
    assert.match(password || "", /^[a-f0-9]{64}$/);

    const browser = await chromium.launch({ headless: true });
    try {
        const context = await browser.newContext({
            viewport: { width: 1600, height: 1000 },
            serviceWorkers: "block",
        });
        // Network namespace is shared only with the disposable app.
        // Block external HTTP and HTTPS even if UI assets request them.
        await context.route("**/*", (route) => {
            const url = new URL(route.request().url());
            return url.origin === origin ? route.continue() : route.abort();
        });
        const page = await context.newPage();
        page.setDefaultTimeout(15000);

        stage = "wait for application";
        let ready = false;
        for (let i = 0; i < 60; i++) {
            try {
                const response = await context.request.get(origin + "/login", { timeout: 2000 });
                if (response.ok()) {
                    ready = true;
                    break;
                }
            } catch {
                // Bootstrap has not opened its loopback server yet.
            }
            await new Promise((resolve) => setTimeout(resolve, 1000));
        }
        assert.ok(ready);

        stage = "real Vue login";
        await page.goto(origin + "/login");
        await page.locator("#username").fill("staging-admin@example.invalid");
        await page.locator("#password").fill(password);
        await page.getByRole("button", { name: "Login", exact: true }).click();
        await page.waitForURL(origin + "/dashboard");

        stage = "settings navigation";
        await page.goto(origin + "/settings");
        await page.getByRole("button", { name: "Notification Templates", exact: true }).click();
        await page.waitForURL(origin + "/settings/notification-templates");
        const editor = page.getByRole("region", { name: "Notification template editor" });
        await editor.getByLabel("Canale ed evento").selectOption("email:backup.success");

        stage = "email preview and save";
        await editor.getByLabel("Oggetto / titolo").fill("Browser synthetic email");
        await editor.getByLabel("Corpo messaggio").fill("Browser {{backup.status}}");
        await editor.getByRole("button", { name: "Anteprima", exact: true }).first().click();
        await editor.getByText("Browser SUCCESS", { exact: true }).waitFor();
        await editor.getByRole("button", { name: "Salva", exact: true }).click();
        await editor.getByText("Template salvato.", { exact: true }).waitFor();

        stage = "settings route reload and persistence";
        await page.reload();
        await editor.getByLabel("Canale ed evento").selectOption("email:backup.success");
        assert.equal(await editor.getByLabel("Oggetto / titolo").inputValue(), "Browser synthetic email");

        stage = "WhatsApp select event";
        const draft = page.getByRole("region", { name: "Bozze template WhatsApp" });
        // The implicit label also contains option text; scope the unique
        // native combobox to the draft region instead of exact label text.
        await draft.getByRole("combobox").selectOption("backup.failed");
        stage = "WhatsApp fill draft";
        await draft.getByLabel("Intestazione statica").fill("Browser synthetic WhatsApp");
        await draft.getByLabel("Corpo della bozza").fill("Browser {{backup.status}}");
        stage = "WhatsApp preview API";
        const [previewResponse] = await Promise.all([
            page.waitForResponse((response) => response.url() === origin + "/api/settings/whatsapp-template-drafts/preview"
                && response.request().method() === "POST"),
            draft.getByRole("button", { name: "Anteprima", exact: true }).click(),
        ]);
        assert.equal(previewResponse.status(), 200);
        stage = "WhatsApp rendered preview";
        await draft.getByText("Browser {{1}}", { exact: true }).waitFor();
        await draft.getByText("Browser FAILED", { exact: true }).waitFor();
        stage = "WhatsApp save API";
        const [saveResponse] = await Promise.all([
            page.waitForResponse((response) => response.url() === origin + "/api/settings/whatsapp-template-drafts"
                && response.request().method() === "PUT"),
            draft.getByRole("button", { name: "Salva bozza", exact: true }).click(),
        ]);
        assert.equal(saveResponse.status(), 200);
        stage = "WhatsApp saved confirmation";
        await draft.getByText("Bozza salvata, non approvata né attivata.", { exact: true }).waitFor();
        stage = "WhatsApp reload persistence";
        await page.reload();
        await draft.getByRole("combobox").selectOption("backup.failed");
        assert.equal(await draft.getByLabel("Intestazione statica").inputValue(), "Browser synthetic WhatsApp");

        stage = "F02 filename pattern preview";
        await page.goto(origin + "/tasks");
        await page.getByRole("button", { name: "Test backup file pattern", exact: true }).click();
        const pattern = page.getByRole("region", { name: "External backup filename pattern tester" });
        await pattern.getByLabel("Filename pattern").fill("synthetic_*.cfg");
        await pattern.getByLabel("Example filenames (one per line, max 50)").fill("synthetic_01.cfg\nunmatched.txt");
        await pattern.getByRole("button", { name: "Preview matches" }).click();
        const matches = pattern.getByRole("list", { name: "Filename match results" });
        await matches.getByText("synthetic_01.cfg", { exact: true }).waitFor();
        assert.match(await matches.getByRole("listitem").nth(0).innerText(), /^MATCH\s+synthetic_01\.cfg$/);
        assert.match(await matches.getByRole("listitem").nth(1).innerText(), /^NO MATCH\s+unmatched\.txt$/);

        stage = "inactive rules panel";
        await page.getByRole("button", { name: "External backup rules", exact: true }).click();
        await page.getByRole("region", { name: "External backup rules" })
            .getByText("No external backup rules configured.", { exact: true }).waitFor();

        console.log("CUSTOM_DOCKER_CHROMIUM_UI_OK (SYNTHETIC_ONLY)");
    } finally {
        await browser.close();
    }
}

main().catch(() => {
    // Do not emit browser traces, page contents, request bodies or credentials.
    console.error("Browser integration failed at stage: " + stage);
    process.exitCode = 1;
});
