import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        react(),
    ],
    build: {
        rollupOptions: {
            output: {
                /*
                 * Split the React runtime into its own chunk so it caches
                 * independently of app code: a shop on a slow connection then
                 * re-downloads only what changed on a deploy, not the whole
                 * ~45KB runtime every time. See docs/frontend-plan.md §5.
                 */
                manualChunks(id) {
                    // Function form, not the object form: main.jsx is loaded
                    // via dynamic import, and the object form leaves React
                    // bundled inside that dynamic chunk instead of splitting
                    // it out. Matching on the module path forces it into
                    // `vendor` regardless of who imported it.
                    if (/node_modules\/(react|react-dom|scheduler|dexie)\//.test(id)) {
                        return 'vendor';
                    }
                },
            },
        },
    },
});
