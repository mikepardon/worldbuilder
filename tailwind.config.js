import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                // Body defaults to Spectral (serif); display = Marcellus; mono = JetBrains Mono.
                sans: ['Spectral', 'Georgia', ...defaultTheme.fontFamily.serif],
                serif: ['Spectral', 'Georgia', ...defaultTheme.fontFamily.serif],
                display: ['Marcellus', 'serif'],
                mono: ['"JetBrains Mono"', ...defaultTheme.fontFamily.mono],
            },
            colors: {
                night: '#0e1014',
                surface: '#12141a',
                card: '#181b21',
                raised: '#1c2027',
                edge: '#1e222a',
                edge2: '#262a33',
                edge3: '#2e323c',
                ink: '#e8e3d9',
                bright: '#f5f1e8',
                muted: '#9aa0ab',
                faint: '#6f7580',
                amber: '#e0a33e',
                teal: '#6fbfc4',
                blood: '#a03b32',
            },
        },
    },

    plugins: [forms, typography],
};
