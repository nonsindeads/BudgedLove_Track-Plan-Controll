# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

## 0.16.0
- Rework dashboard layout with KPI header, consistent grid, and empty-state CTAs.

## 0.15.0
- Convert the live panel to an offcanvas overlay and refine the topbar hierarchy.

## 0.14.0
- Group sidebar navigation, fix logo header background, and add desktop collapse toggle.

## 0.13.0
- Add security bootstrap with hardened sessions, CSRF validation, and security headers.
- Add CSRF auto-injection and HTMX header support in the layout.
- Add rate limiting for auth endpoints.

## 0.12.11
- Document Caddy-based production setup and landing domain routing.

## 0.12.10
- Add BudgetLove landing page for the root domain.

## 0.12.9
- Add Caddy reverse proxy and production compose overlay for HTTPS and WebSocket routing.

## 0.12.8
- Fix budgets/savings modal inputs to avoid type errors in PHP 8.

## 0.12.7
- Force budgets page to use full layout and simplify modal open flow.

## 0.12.6
- Open budgets/savings modals from server action state to avoid missing modal triggers.

## 0.12.5
- Fix budgets modal action detection to match categories routing.

## 0.12.4
- Align budget modal trigger with action handling (categories flow).

## 0.12.3
- Align budgets modals with categories modal flow to prevent unstyled reloads.

## 0.12.2
- Open budgets/savings creation in-page modal instead of a new page.

## 0.12.1
- Fix budgets modal rendering by aligning layout context.

## 0.12.0
- Added budgets (multi-category) with monthly tracking and UI.
- Added saving plans with optional recurring contributions, targets, and plan integration.
- Savings plans now generate planned payments for forecasts and monthly plan.

## 0.11.0
- Prepare 0.11.0 release baseline.

## 0.10.15
- Added dev compose override and gitignore rules to keep test data out of releases.
- Documented release branch deploy flow and local data separation.

## 0.10.14
- Removed personal/local data from scripts and docker-compose defaults.

## 0.10.13
- Localized transactions UI copy, tooltips, and errors.

## 0.10.12
- Localized open bookings workflow and recurring helper UI.

## 0.10.11
- Localized dashboard, admin, history, and import screens.

## 0.10.10
- Localized open cases UI and payment creation copy.

## 0.10.9
- Localized recurring payments screens and validation messages.

## 0.10.8
- Localized categories, payees, and payee mapping screens.
- Localized the profile color picker label.

## 0.10.7
- Localized monthly plan and month close screens.
- Localized live feed labels and WebSocket payloads for translations.

## 0.10.6
- Localized tags UI strings and added German translations.

## 0.10.5
- Fix release migration glob handling when GLOB_BRACE is unavailable.

## 0.10.4
- Added i18n core with file defaults and DB overrides.
- Added translations management page and language selector.
- Added release migration for translations and user language.

## 0.10.3
- Documented release migrations in the README.

## 0.10.2
- Added release migration runner (versioned SQL/PHP).
- Documented release migration policy in AGENTS.

## 0.10.1
- Added English AGENTS rules and UI component policy updates.
- Added VERSION file for release tracking.

## 0.10.0
- Added a persistent right-side Live panel with filters and chat history.
- Added per-user color support used in live feed and chat.
- Added payee mapping workflow and mapping page.
- Added tag/category/payee inline create flows and chip selectors in modals.
- Added transaction whitebox modals for create/edit with improved layouts.
- Added recurring payments end dates, matching, and plan linking improvements.
- Improved planned payment generation and recurring form UX.
- Added category hierarchy rendering in lists.
- Removed demo seed script and ignored local env/data files.
- Documented quickstart and production setup workflow.
