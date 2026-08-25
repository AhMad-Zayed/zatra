import preset from '../../../../vendor/filament/filament/tailwind.config.preset'

export default {
    presets: [preset],
    content: [
        './app/Filament/**/*.php',
        './resources/views/filament/**/*.blade.php',
        './vendor/filament/**/*.blade.php',
    ],
    theme: {
        extend: {
            colors: {
                // 'accent' is a 7th color registered via ->colors() in AdminPanelProvider
                // (Sunset Orange), on top of Filament's built-in six. Filament's preset only
                // pre-wires Tailwind utility classes (bg-accent-500, text-accent-600, ...) for
                // its own six roles, so it's re-declared here following the exact same
                // rgba(var(--accent-{shade}), <alpha-value>) pattern the preset uses for the
                // others — this is what makes `--accent-*` (auto-generated at runtime by
                // Filament's color registration) resolve as real Tailwind classes.
                accent: {
                    50: 'rgba(var(--accent-50), <alpha-value>)',
                    100: 'rgba(var(--accent-100), <alpha-value>)',
                    200: 'rgba(var(--accent-200), <alpha-value>)',
                    300: 'rgba(var(--accent-300), <alpha-value>)',
                    400: 'rgba(var(--accent-400), <alpha-value>)',
                    500: 'rgba(var(--accent-500), <alpha-value>)',
                    600: 'rgba(var(--accent-600), <alpha-value>)',
                    700: 'rgba(var(--accent-700), <alpha-value>)',
                    800: 'rgba(var(--accent-800), <alpha-value>)',
                    900: 'rgba(var(--accent-900), <alpha-value>)',
                    950: 'rgba(var(--accent-950), <alpha-value>)',
                },
            },
        },
    },
}
