<script>
	(function () {
		var root = document.documentElement;
		var forceDark = root.getAttribute("data-force-dark") === "true";
		var storedTheme = null;
		var storedPreset = null;
		var presets = { dark: "dark", midnight: "dark", graphite: "dark", light: "light", cloud: "light", sand: "light" };

		try {
			storedPreset = localStorage.getItem("rconfig-theme-preset");
			storedTheme = localStorage.getItem("theme") || localStorage.getItem("vueuse-color-scheme");
		} catch (e) {
			storedTheme = null;
		}

		if (storedTheme !== "light" && storedTheme !== "dark") {
			storedTheme = null;
		}

		var palette = forceDark ? "dark" : (Object.prototype.hasOwnProperty.call(presets, storedPreset) ? storedPreset : (storedTheme || "dark"));
		var resolvedTheme = presets[palette];
		root.setAttribute("data-palette", palette);
		var isDark = resolvedTheme === "dark";

		root.setAttribute("data-theme", resolvedTheme);
		root.classList.toggle("dark", isDark);
		root.style.colorScheme = isDark ? "dark" : "light";
	})();
</script>
