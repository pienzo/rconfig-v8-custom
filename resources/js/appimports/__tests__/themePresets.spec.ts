import { afterEach, beforeEach, describe, expect, it } from "vitest";
// @ts-ignore — JS module
import { applyStoredTheme, applyThemePalette } from "../themeHandler";
// @ts-ignore — JS module
import { THEME_PRESETS, findThemePreset } from "../themePresets";

function reset() {
	localStorage.clear();
	const root = document.documentElement;
	root.removeAttribute("data-force-dark");
	root.removeAttribute("data-palette");
	root.removeAttribute("data-theme");
	root.classList.remove("dark");
	root.style.colorScheme = "";
}

describe("appearance presets", () => {
	beforeEach(reset);
	afterEach(reset);

	it("offers exactly three dark and three light themes", () => {
		expect(THEME_PRESETS.filter((p: {mode: string}) => p.mode === "dark")).toHaveLength(3);
		expect(THEME_PRESETS.filter((p: {mode: string}) => p.mode === "light")).toHaveLength(3);
	});

	for (const preset of THEME_PRESETS) {
		it("applies and restores " + preset.id, () => {
			expect(applyThemePalette(preset.id)).toBe(preset.id);
			expect(document.documentElement.dataset.palette).toBe(preset.id);
			expect(document.documentElement.dataset.theme).toBe(preset.mode);
			expect(document.documentElement.classList.contains("dark")).toBe(preset.mode === "dark");
			expect(document.documentElement.style.colorScheme).toBe(preset.mode);
			document.documentElement.removeAttribute("data-palette");
			expect(applyStoredTheme()).toBe(preset.id);
			expect(localStorage.getItem("theme")).toBe(preset.mode);
		});
	}

	it("falls back to legacy mode for unknown saved presets", () => {
		localStorage.setItem("rconfig-theme-preset", "__proto__");
		localStorage.setItem("theme", "light");
		expect(findThemePreset("__proto__")).toBeNull();
		expect(applyStoredTheme()).toBe("light");
	});

	it("preserves the authenticated preference on forced-dark login", () => {
		applyThemePalette("cloud");
		document.documentElement.dataset.forceDark = "true";
		expect(applyStoredTheme()).toBe("dark");
		expect(applyThemePalette("sand")).toBe("dark");
		expect(localStorage.getItem("rconfig-theme-preset")).toBe("cloud");
		document.documentElement.removeAttribute("data-force-dark");
		expect(applyStoredTheme()).toBe("cloud");
	});
});
