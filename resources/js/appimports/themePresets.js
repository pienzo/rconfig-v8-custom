export const THEME_PRESETS = Object.freeze([
	Object.freeze({ id: "dark", label: "Classic Dark", mode: "dark" }),
	Object.freeze({ id: "midnight", label: "Midnight Blue", mode: "dark" }),
	Object.freeze({ id: "graphite", label: "Graphite", mode: "dark" }),
	Object.freeze({ id: "light", label: "Classic Light", mode: "light" }),
	Object.freeze({ id: "cloud", label: "Cloud Blue", mode: "light" }),
	Object.freeze({ id: "sand", label: "Warm Sand", mode: "light" }),
]);

export function findThemePreset(id) {
	return THEME_PRESETS.find((preset) => preset.id === id) ?? null;
}

export function resolveThemePreset(preset, legacyTheme) {
	return findThemePreset(preset) ?? findThemePreset(legacyTheme === "light" ? "light" : "dark");
}
