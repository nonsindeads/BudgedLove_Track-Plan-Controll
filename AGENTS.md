# AGENTS

Project: BudgetLove

## Working Practices (Project Rules)
- Work in `/srv/haushaltsbuch/repo`.
- Commit after each task.
- Keep existing functionality; do not remove features unless explicitly asked.
- Use English for documentation and code comments going forward.
- Keep context small; avoid unnecessary changes.

## Tech Stack Constraints
- Backend: PHP with server-side rendering.
- Frontend: Bootstrap 5 + HTMX.
- No React, no Vue, no bundlers/build tools.
- No external UI frameworks besides Bootstrap.
- Minimal custom JS; no heavy client logic.

## UI/UX Direction
- CoreUI-like admin layout (left sidebar, top header, central content).
- Layout, content, and partials are clearly separated.
- Mobile-first fixes (minimum iPhone SE support).
- Offcanvas panels behave consistently: overlay + backdrop, close always reachable, no background scroll.
- Use reusable "whitebox" modal overlays for create/edit flows (not inline forms).

## Form/Input Consistency
- Use Bootstrap 5 form classes everywhere.
- Unified Tag-Selector component:
  - Multi-select with chips, filter input, dropdown, and create modal.
  - No external select libraries.

## History / Live Feed
- Live feed and chat are persistent and readable.
- Filters and grouping for important events.
- Each user has an assigned color (set in profile).

## Versioning Policy
- Starting version: `0.10.0`.
- Version format: `major.minor.patch`.
  - `major`: only when user explicitly sets a release number.
  - `minor`: increment for each new feature or extension.
  - `patch`: increment for every small change/fix (including each commit).
