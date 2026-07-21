#!/usr/bin/env node
// scripts/check-bundle-size.mjs
//
// Fails the build when a bundle exceeds its gzipped budget. Bundle creep is
// invisible on a developer machine — the download only hurts the shopkeeper on
// a slow rural connection — so it is caught in CI, not in review (frontend-plan
// §5, a Phase 8 non-negotiable).
//
// The budgets below are set at the MEASURED gzipped size plus a little headroom.
// Their job is to catch *regression* (a barrel import, a stray component
// library, a lodash creeping in), NOT to relitigate the deliberate full-React
// decision (§5): React + react-dom + Dexie are ~93KB gzipped and that is the
// accepted floor. Tighten a budget only when a real reduction lands (e.g. the
// preact/idb swap §5 discusses), never loosen it to make a red build pass —
// that defeats the entire point of the gate.
//
// Runs on the Vite output in public/build/assets. Exit 0 = within budget,
// exit 1 = over (with a per-file breakdown so the offender is obvious).

import { readdirSync, readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { basename, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ASSETS_DIR = join(fileURLToPath(new URL('.', import.meta.url)), '..', 'public', 'build', 'assets');

// KB, gzipped. See the header note before changing any of these.
const BUDGETS = {
    vendor: 100,    // React + react-dom + Dexie (measured ~93)
    appShell: 22,   // main.jsx + app.js entry (measured ~16)
    css: 11,        // Tailwind, purged (measured ~7)
    route: 30,      // any single lazy route chunk (§5); none exist yet
};

const KB = 1024;
const gzippedKb = (buf) => gzipSync(buf, { level: 9 }).length / KB;

/** Which budget a built asset counts against, from its Vite chunk name. */
function classify(name) {
    if (name.endsWith('.css')) return 'css';
    if (!name.endsWith('.js')) return null; // .map, fonts, etc. — not budgeted

    const chunk = basename(name).split('-')[0];
    if (chunk === 'vendor') return 'vendor';
    if (chunk === 'main' || chunk === 'app') return 'appShell';
    return 'route'; // a code-split route chunk, budgeted individually
}

let files;
try {
    files = readdirSync(ASSETS_DIR);
} catch {
    console.error(`✗ No build output at ${ASSETS_DIR}. Run \`npm run build\` first.`);
    process.exit(1);
}

// Aggregate categories are summed (many CSS files still share one budget); route
// chunks are checked one-by-one, so each is its own line.
const totals = { vendor: 0, appShell: 0, css: 0 };
const routeChunks = [];
const rows = [];

for (const name of files) {
    const category = classify(name);
    if (category === null) continue;

    const size = gzippedKb(readFileSync(join(ASSETS_DIR, name)));

    if (category === 'route') {
        routeChunks.push({ name, size });
    } else {
        totals[category] += size;
    }

    rows.push({ name, category, size });
}

const checks = [
    { label: 'vendor (React + Dexie)', actual: totals.vendor, budget: BUDGETS.vendor },
    { label: 'app shell (main + app)', actual: totals.appShell, budget: BUDGETS.appShell },
    { label: 'css', actual: totals.css, budget: BUDGETS.css },
    ...routeChunks.map((c) => ({ label: `route: ${c.name}`, actual: c.size, budget: BUDGETS.route })),
];

const fmt = (kb) => `${kb.toFixed(1)}KB`;
let failed = false;

console.log('\nBundle budget (gzipped)\n' + '─'.repeat(52));
for (const { label, actual, budget } of checks) {
    const over = actual > budget;
    failed ||= over;
    const mark = over ? '✗' : '✓';
    const pct = ((actual / budget) * 100).toFixed(0);
    console.log(`${mark} ${label.padEnd(26)} ${fmt(actual).padStart(8)} / ${fmt(budget).padStart(7)}  (${pct}%)`);
}
console.log('─'.repeat(52));

if (failed) {
    console.error(
        '\n✗ Over budget. Something grew the bundle — check for a barrel import,\n' +
        '  a new dependency, or a component library. See docs/frontend-plan.md §5.\n'
    );
    process.exit(1);
}

console.log('✓ Within budget.\n');
