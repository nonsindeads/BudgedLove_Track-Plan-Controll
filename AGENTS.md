# AGENTS

Project: BudgetLove

## Working Practices (Project Rules)
- Work in `/srv/haushaltsbuch/repo`.
- Commit after each task.
- Keep existing functionality; do not remove features unless explicitly asked.
- Use English for documentation and code comments going forward.
- Keep changes focused; avoid unrelated modifications.

## Tech Stack Constraints
- Backend: PHP with server-side rendering.
- Frontend: Bootstrap 5 + HTMX.
- No React, no Vue, no bundlers/build tools.
- No external UI frameworks besides Bootstrap.
- Minimal custom JS; no heavy client logic.

## UI Core Components (Mandatory)

### Whitebox-Modal (Overlay, Create/Edit Standard)
- Required for all create/edit flows (no inline forms under lists).
- Use for transactions, tags, categories, payees, and similar dialogs.

**Technique**
- Bootstrap Modal only.
- No nested modals.
- Minimal JS for open/close, focus, and simple UI state.

**Structure (fixed)**
- Header: title left, close right.
- Body: form content.
- Footer: primary action (save) + secondary action (cancel).

**Behavior**
- Backdrop blocks background.
- Focus trap inside the modal.
- ESC closes the modal.
- Close button always visible.
- No background scroll.
- Focus returns to the trigger on close (Bootstrap default).
- State resets on close (prefer server re-render; optional form reset on hide).

**Exception (Nested Modal Avoidance)**
- If the user is already inside a modal, inline create panels inside the same modal are allowed to avoid nested modals.

---

### Whitebox-Card (Static, No Overlay)
- Purely visual static container.
- No backdrop, no focus trap.
- Use for structured page content only.
- Not allowed for create/edit flows.

---

### Combined Input Field / Tag-Selector (Project Standard)
The Tag-Selector is the reference component for combined input/dropdown fields.

**Variants**
- Multi-select: Tags.
- Single-select: Category, Payee.
- Consistent UI across variants.

**Structure**
- Base: `input-group`.
- Left: flex container with chips + text input for filtering.
- Right: add button ("+") + dropdown toggle.

**Behavior**
- Focus/click opens the dropdown below the field.
- Typing filters existing entries by name.
- Select by clicking a dropdown item.
- Multi-select: multiple chips, remove via "x", backspace removes last chip when input is empty.
- Single-select: exactly one item; new selection replaces previous.
- ESC closes dropdown; click outside closes dropdown.
- Correct tab order and keyboard navigation required.

**Dropdown**
- Bootstrap `dropdown` / `dropdown-menu`.
- Each entry is a `dropdown-item`.
- Entry includes color indicator + text.
- Hover + selected states are visible.
- Selected items are clearly marked.
- Max height with internal scroll.

**Add ("+")**
- Opens a Whitebox-Modal to create a new item.
- No inline creation inside the dropdown.

**Tag modal fields (required)**
- Name (required)
- Color (hex + `form-control-color`)

**Category/Payee modal fields**
- Use their existing field sets (no color).

**After save**
- New item appears in the dropdown immediately and can be selected.

**Validation & States**
- Bootstrap validation (`is-valid`, `is-invalid`, `invalid-feedback`).
- Disabled/readonly must apply to input, chips, buttons, and dropdown.
- Optional loading state for async data.

**Component Hooks (required)**
- Root: `data-chip-selector`
- Required elements/classes:
  - `.hb-tag-field`
  - `.hb-tag-input`
  - `.hb-tag-dropdown`
  - `.hb-tag-option`
  - `.hb-tag-values`
- Use `data-selector-name` for hidden input name.
- Use `data-selector-multi="false"` for single-select.

## Project-wide Form Consistency (Enforced)
- Use only Bootstrap 5 form primitives:
  - `form-control`, `form-select`, `form-check`, `input-group`.
- Labels above fields.
- Help/error text below fields.
- Consistent sizing, spacing, focus, disabled states.
- Same actions use the same button variants.

## Component Consolidation Rules
- Reusable base components are mandatory:
  - Text input, select, checkbox, button.
  - Whitebox-Modal, Whitebox-Card.
  - Form-field wrapper (label + field + help/error).
  - Tag-Selector.
- Replace inconsistent legacy implementations over time.
- No new custom patterns without explicit approval.

## JS & CSS Constraints (UI)
- No external JS.
- No external CSS frameworks.
- Minimal custom JS for UI state and events only.
- Custom CSS only for layout fine-tuning within Bootstrap.

## Versioning Policy
- Starting version: `0.10.0`.
- Format: `major.minor.patch`.
  - `major`: only when explicitly set by the user.
  - `minor`: increment for each new feature/extension.
  - `patch`: increment for every small change/fix (including each commit).
- Source of truth: `VERSION` file.
