import defaultTheme from 'tailwindcss/defaultTheme';

/**
 * Design tokens for VyaparBook — see docs/frontend-plan.md §4.
 *
 * The colours are chosen for a shopkeeper holding a cheap Android phone
 * OUTDOORS. Every foreground/background pair below clears WCAG AA (4.5:1);
 * the fashionable low-contrast greys (slate-400 on white is 3.0:1) fail and
 * are deliberately absent from the semantic scale.
 */
/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.jsx',
    ],
    theme: {
        extend: {
            colors: {
                // Semantic tokens — components reference these, never raw hex.
                brand: {
                    DEFAULT: '#1D4ED8', // blue-700, 8.6:1 on white
                    dark: '#1E40AF',
                },
                success: '#15803D', // green-700, 5.1:1
                danger: '#B91C1C', // red-700,   7.0:1
                warning: '#B45309', // amber-700, 5.2:1
                surface: '#FFFFFF',
                canvas: '#F8FAFC', // slate-50
                ink: {
                    DEFAULT: '#0F172A', // slate-900, 16.1:1 — body text
                    muted: '#475569', // slate-600,  7.4:1 — NOT slate-400
                },
                hairline: '#CBD5E1', // slate-300 — visible in both themes
            },
            fontFamily: {
                // Devanagari first: Hindi is the default language, so the
                // Hindi face must win the fallback race, not be a patch.
                sans: ['"Noto Sans Devanagari"', 'Inter', ...defaultTheme.fontFamily.sans],
            },
            fontSize: {
                // Base is 16px: anything smaller makes iOS auto-zoom on input
                // focus, which yanks the layout mid-entry.
                xs: ['12px', { lineHeight: '16px' }], // labels only, never body
                sm: ['14px', { lineHeight: '20px' }],
                base: ['16px', { lineHeight: '1.6' }],
                lg: ['18px', { lineHeight: '1.6' }],
                xl: ['24px', { lineHeight: '32px' }],
                '2xl': ['32px', { lineHeight: '40px' }],
            },
            spacing: {
                // 4/8pt rhythm. `tap` is the minimum touch target (44px, Apple
                // HIG) — use it for every interactive element's min-height.
                tap: '44px',
            },
            minHeight: {
                tap: '44px',
            },
            minWidth: {
                tap: '44px',
            },
        },
    },
    plugins: [],
};
