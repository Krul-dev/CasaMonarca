import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans:    ['Figtree', ...defaultTheme.fontFamily.sans],
                display: ['Archivo', 'Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                cream: {
                    50:  'oklch(97.5% 0.012 75)',
                    100: 'oklch(95.5% 0.018 72)',
                    200: 'oklch(91% 0.025 70)',
                    300: 'oklch(85% 0.03 68)',
                },
                ink: {
                    300: 'oklch(78% 0.008 60)',
                    400: 'oklch(67% 0.01 55)',
                    500: 'oklch(55% 0.012 50)',
                    700: 'oklch(40% 0.015 50)',
                    800: 'oklch(30% 0.018 50)',
                    900: 'oklch(22% 0.018 50)',
                },
                brand: {
                    red:         'oklch(60% 0.20 25)',
                    'red-deep':  'oklch(50% 0.20 25)',
                    'red-soft':  'oklch(94% 0.04 25)',
                    orange:      'oklch(72% 0.17 52)',
                    'orange-deep': 'oklch(63% 0.18 50)',
                    'orange-soft': 'oklch(95% 0.035 60)',
                    'orange-line': 'oklch(85% 0.10 55)',
                },
            },
        },
    },

    plugins: [forms],
};
