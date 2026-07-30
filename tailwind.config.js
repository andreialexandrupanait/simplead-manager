import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/**/*.blade.php',
        './app/View/**/*.php',
        './app/Livewire/**/*.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                // 'Inter Fallback' (see app.css) carries Inter's metrics, so the
                // swap from fallback to the real font moves nothing. Plain 'Inter'
                // was removed: it is not self-hosted, so it only resolved on
                // machines that happened to have Inter installed — making the
                // rendering depend on whose computer it was.
                sans: ['"Inter Variable"', '"Inter Fallback"', 'system-ui', 'sans-serif'],
            },
            colors: {
                // Override Tailwind's cool blue-gray scale with warm neutrals for
                // 700/800/900 — these are the surfaces used in dark mode (body bg,
                // cards, hovers). Default Tailwind gray-900 is #111827 (navy-tinted);
                // we want pure dark gray. Keeps lighter shades (50-600) untouched.
                gray: {
                    // Light-mode app background — WordPress gray (REFERINTA-VIZUALA --bg).
                    50: '#f0f1f3',
                    // Dark-mode surfaces (unchanged): body / cards / hovers.
                    700: '#3C3C3C',
                    800: '#232323',
                    900: '#1A1A1A',
                },
                sidebar: {
                    // Theme-aware via CSS variables defined in app.css:
                    //   light: #1A1A1A (original, looks great against light body)
                    //   dark:  #0F0F0F (deeper than #1A1A1A body so the rail
                    //          stands out as a distinct surface)
                    DEFAULT: 'var(--surface-sidebar)',
                    hover: 'var(--surface-sidebar-hover)',
                },
                accent: {
                    50: 'var(--accent-50, #e8f1f9)',
                    100: 'var(--accent-100, #d3e6f4)',
                    200: 'var(--accent-200, #a8cde9)',
                    300: 'var(--accent-300, #7cb3dd)',
                    400: 'var(--accent-400, #4e93c9)',
                    500: 'var(--accent-500, #2271b1)',
                    600: 'var(--accent-600, #1d6098)',
                    700: 'var(--accent-700, #184e7c)',
                    800: 'var(--accent-800, #143f64)',
                    900: 'var(--accent-900, #12324e)',
                    950: 'var(--accent-950, #0b2135)',
                    DEFAULT: 'var(--accent-500, #2271b1)',
                    hover: 'var(--accent-600, #1d6098)',
                    light: 'var(--accent-light, rgba(34, 113, 177, 0.2))',
                },
            },
        },
    },

    plugins: [forms, typography],
};
