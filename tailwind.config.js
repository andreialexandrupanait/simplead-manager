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
                sans: ['Inter var', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                sidebar: {
                    DEFAULT: '#1A1A2E',
                    hover: '#232340',
                },
                accent: {
                    50: 'var(--accent-50, #f5f0ff)',
                    100: 'var(--accent-100, #ede5ff)',
                    200: 'var(--accent-200, #ddd0fe)',
                    300: 'var(--accent-300, #c4adfd)',
                    400: 'var(--accent-400, #a87ffb)',
                    500: 'var(--accent-500, #8D5CF5)',
                    600: 'var(--accent-600, #7C3AED)',
                    700: 'var(--accent-700, #6d28d9)',
                    800: 'var(--accent-800, #5b21b6)',
                    900: 'var(--accent-900, #4c1d95)',
                    950: 'var(--accent-950, #2e1065)',
                    DEFAULT: 'var(--accent-500, #8D5CF5)',
                    hover: 'var(--accent-600, #7C3AED)',
                    light: 'var(--accent-light, rgba(141, 92, 245, 0.2))',
                },
            },
        },
    },

    plugins: [forms, typography],
};
