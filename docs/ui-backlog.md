# UI Backlog

Lightweight tracking for frontend work — bugs, features, and UI polish across the
Blade shell and the React islands under `/app/*`. This doc is the fast, in-repo
capture point; anything that needs assignment, discussion, or a milestone should
graduate to a **GitHub issue** on `Vijay-Chaudhary/VyaparBook` under the matching
label (`bug`, `feature`, `ui`).

## Conventions

- **ID** — `B-01` (bug), `F-01` (feature), `U-01` (ui polish). Never reuse an ID.
- **Status** — `todo` · `in-progress` · `blocked` · `done`. Keep `done` rows for one
  release, then prune.
- **Area** — where it lives, e.g. `khata`, `stock`, `onboarding`, `billing`,
  `platform-console`, `offline/sync`, `shell`.
- **Issue** — link the GitHub issue number once one is opened (`#12`), else `—`.
- Newest items go at the top of each table. One line per item; put detail
  (repro steps, screenshots, acceptance criteria) in the linked GitHub issue.

---

## Bugs

Label: `bug`

| ID | Status | Area | Summary | Issue |
|----|--------|------|---------|-------|
| B-01 | todo | shell/home | `Home.jsx:24` hardcodes the Hindi greeting `नमस्ते, ${userName}` instead of `t()` — shows Hindi even in English mode (the app's default locale). Every sibling string on the screen uses `t()`; this one string leaks. Fix: add a `greeting` dictionary key and render `` `${t('greeting')}, ${userName}` ``. | — |

---

## Features

Label: `feature`

| ID | Status | Area | Summary | Issue |
|----|--------|------|---------|-------|
| _none yet_ | | | | |

---

## UI polish

Label: `ui`

| ID | Status | Area | Summary | Issue |
|----|--------|------|---------|-------|
| U-01 | todo | khata/forms | New-customer form: the "Opening balance" hint duplicates its own label verbatim in English. Two keys exist — `opening_balance` (label) and `opening` (hint) — and Hindi differentiates them (`पुराना बकाया` vs `शुरुआती बकाया`), but both English strings (`i18n.js:139` & `:157`) are `'Opening balance'`. Fix: give the English `opening` hint distinct copy, e.g. "Amount owed before you started using VyaparBook". | — |
