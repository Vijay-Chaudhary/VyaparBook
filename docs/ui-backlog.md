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
| B-01 | done | shell/home | `Home.jsx:24` hardcoded the Hindi greeting `नमस्ते, ${userName}` instead of `t()` — showed Hindi even in English mode. Fixed: added a `greeting` key to both locales (`en: 'Namaste'`, `hi: 'नमस्ते'`) and render `` `${t('greeting')}, ${userName}` ``. | — |

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
| U-01 | done | khata/forms | New-customer form: the "Opening balance" hint duplicated its own label verbatim in English (Hindi differentiated `पुराना बकाया` vs `शुरुआती बकाया`, but both English `opening_balance` and `opening` were `'Opening balance'`). Fixed: English `opening` hint is now `'Amount owed before you started using VyaparBook'` (`i18n.js:157`). | — |
