import { resolveThemePreference } from "./themePreference";
import { findThemePreset, resolveThemePreset } from "./themePresets";

function renderTheme(preset) {
	const root = document.documentElement;
	const isDark = preset.mode === "dark";
	root.setAttribute("data-palette", preset.id);
	root.setAttribute("data-theme", preset.mode);
	root.classList.toggle("dark", isDark);
	root.style.colorScheme = preset.mode;
	return preset.id;
}

export function applyStoredTheme() {
	const root = document.documentElement;
	if (root.getAttribute("data-force-dark") === "true") {
		return renderTheme(findThemePreset("dark"));
	}

	let storedTheme = null;
	let storedPreset = null;
	try {
		storedPreset = localStorage.getItem("rconfig-theme-preset");
		storedTheme = localStorage.getItem("theme") || localStorage.getItem("vueuse-color-scheme");
	} catch {
		// Storage may be disabled by the browser.
	}

	return renderTheme(resolveThemePreset(storedPreset, resolveThemePreference(storedTheme)));
}

export function applyThemePalette(id) {
	const root = document.documentElement;
	if (root.getAttribute("data-force-dark") === "true") {
		return renderTheme(findThemePreset("dark"));
	}

	const preset = findThemePreset(id);
	if (!preset) {
		return applyStoredTheme();
	}
	try {
		localStorage.setItem("rconfig-theme-preset", preset.id);
		localStorage.setItem("theme", preset.mode);
		localStorage.setItem("vueuse-color-scheme", preset.mode);
	} catch {
		// Apply for the current page even when persistence is blocked.
	}
	return renderTheme(preset);
}
