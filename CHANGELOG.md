# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

## 0.23.10
- Fix CAMT import date range tracking for summary output.

## 0.23.9
- Add an opening balance date for accounts and apply it to balance calculations.

## 0.23.8
- Clamp monthly/yearly recurring dates to the last day of shorter months so plans continue to appear.

## 0.19.0
- Expand the landing page into a bilingual, SEO-friendly one-pager with features and quickstart.

## 0.19.1
- Remove the registration CTA from the app landing and refresh guest copy.

## 0.19.2
- Add a request access mail link to the app landing page.

## 0.19.3
- Restyle app landing and login to match the main landing page.

## 0.19.4
- Split the landing page into dedicated German and English versions.

## 0.20.0
- Add password change flow on the profile page.
- Track household creator and restrict member invites to the creator only.

## 0.20.1
- Add a logout goodbye page with delayed redirect to login.

## 0.21.0
- Add admin user creation with optional household assignment.

## 0.22.0
- Add admin demo-data creation when creating a user.

## 0.22.1
- Fix password change update for databases without users.updated_at.

## 0.22.2
- Make demo user creation resilient when creator column is missing.

## 0.22.3
- Fix admin user creation when users.address is required.

## 0.22.4
- Ensure login errors render feedback for HTMX requests.

## 0.22.5
- Fix admin user creation boolean consent binding.

## 0.22.6
- Hardcode consent_contact to false in admin user creation.

## 0.22.7
- Add admin error detail output for user creation failures.

## 0.22.8
- Avoid nested transactions during demo-user creation.

## 0.22.9
- Skip demo match rules when the table is not available.

## 0.23.0
- Add admin action to seed demo data into an existing household.

## 0.23.1
- Make demo transaction seeding resilient to missing columns.

## 0.23.2
- Fix demo seed parameter bindings for optional transaction columns.

## 0.23.3
- Filter demo transaction params to match available columns.

## 0.23.4
- Handle category arrays returned as strings in budgets/savings views.

## 0.23.5
- Persist categories for transfers and show transfer accounts in lists/details.

## 0.23.6
- Save linked payments when creating open cases in agreed status.

## 0.23.7
- Add total/settled amounts for open cases and show remaining balance.

## 0.18.1
- Move exposed ports into a dedicated compose overlay for proxy compatibility.

## 0.18.0
- Add Caddy compose file to the repo for centralized proxy deployment.

## 0.17.3
- Keep internal networking when attaching services to the proxy network.

## 0.17.2
- Build the app image once to avoid duplicate build conflicts for hb_ws.

## 0.17.1
- Add central proxy documentation for Dockge and multi-stack deployments.

## 0.17.0
- Add proxy overlay compose file for centralized HTTPS reverse proxy.

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
