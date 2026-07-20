import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'node',
        // fake-indexeddb gives Dexie a real IndexedDB implementation in Node, so
        // the offline layer is tested against actual IndexedDB semantics
        // (transactions, key constraints) rather than a hand-written stub that
        // would agree with whatever the code happens to do.
        setupFiles: ['fake-indexeddb/auto'],
        include: ['resources/js/**/*.test.js'],
    },
});
