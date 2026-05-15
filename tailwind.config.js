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
                sans: ['"Inter Variable"', '"Inter"', 'system-ui', 'sans-serif'],
            },
            colors: {
                // Override Tailwind's cool blue-gray scale with warm neutrals for
                // 700/800/900 — these are the surfaces used in dark mode (body bg,
                // cards, hovers). Default Tailwind gray-900 is #111827 (navy-tinted);
                // we want pure dark gray. Keeps lighter shades (50-600) untouched.
                gray: {
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
                    50: 'var(--accent-50, #F2F0FF)',
                    100: 'var(--accent-100, #E6E2FF)',
                    200: 'var(--accent-200, #CDC5FF)',
                    300: 'var(--accent-300, #B3A6FF)',
                    400: 'var(--accent-400, #9787F7)',
                    500: 'var(--accent-500, #7B68EE)',
                    600: 'var(--accent-600, #6151D4)',
                    700: 'var(--accent-700, #4A3FB0)',
                    800: 'var(--accent-800, #38318A)',
                    900: 'var(--accent-900, #292568)',
                    950: 'var(--accent-950, #1A1746)',
                    DEFAULT: 'var(--accent-500, #7B68EE)',
                    hover: 'var(--accent-600, #6151D4)',
                    light: 'var(--accent-light, rgba(123, 104, 238, 0.2))',
                },
            },
        },
    },

    plugins: [forms, typography],
};
