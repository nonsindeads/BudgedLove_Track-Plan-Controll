# Household Book Domain Model (MVP)

## Households
- `households`: name, currency, month-close mode (`first_of_month` or `salary_day` + optional `salary_day`), timestamps.
- `household_members`: user ↔ household mapping with roles `admin|editor|viewer`, `is_active`. Primary key (household_id, user_id).
- Default: after login a user must choose or create a household. The creator receives the household admin role.
- Default categories/tags are copied from global records (`household_id` NULL) into the new household.

## Accounts
- `accounts`: household, name, type (`cash|checking|savings|credit_card|loan|asset|liability|other`), currency, opening balance (in cents), archive flag.
- Each account belongs to exactly one household.

## Categories & Tags
- `categories`: household or global (NULL = default), name, type (`income|expense`), optional parent ID (same household only), sort order, active flag.
- `tags`: household or global (NULL), name, optional color, active flag.
- Admins can maintain global defaults; they are copied during household setup.

## Payees
- `payees`: household, name (unique per household), optional address/IBAN/BIC/notes.

## Transactions
- `transactions`: household, type (`income|expense|transfer`), booking date, amount in cents (always positive, type drives direction), currency, account, category, payee, note, transfer source/target accounts, optional import IDs, optional link to `planned_payments`.
- `transaction_splits`: split allocation by category (sum equals `transactions.amount_cents`).
- `transaction_tags`: mapping transaction ↔ tag.
- Display: amount is interpreted as inflow/outflow based on type; stored as positive cents.

### Transfers
- Type `transfer` uses `transfer_from_account_id` and `transfer_to_account_id` (both required). `account_id`/`category_id` are NULL.

### Splits
- For income/expense, category can be empty if splits exist. The sum of splits must equal the transaction amount.

## Recurring Rules & Tasks
- `recurring_rules`: household, active flag, name, kind (`transaction|task`), schedule (unit `day|week|month|year`, interval, optional weekdays `mon,tue`, optional `schedule_monthday`, start/next/last), payload JSON.
- `tasks`: household, title/description, due date, optional amount in cents, status (`open|done|cancelled`), source rule.
- `recurring_executions`: execution log.
- `cron.php` creates transactions/tasks from due rules and advances `next_run_at`.

## Plan-Based Payments
- `recurring_payments`: household, name, direction (`income|expense`), amount, interval (`day|week|month|year` + value), start date, priority, optional/mandatory, account/category/payee/note, active.
- `planned_payments`: period plan entries (from recurring or manual), date, status (`open|done|skipped|overdue|suggested`), priority, optional/mandatory, account/category/payee mapping, optional link to a transaction.
- Status rules: overdue = planned date < today with status `open`; `done`/`skipped` close the entry.

## Open Cases & Month Close
- `open_cases`: cases with status (`open|clarifying|agreed|done`), reference/case number, contact, notes, optional link to a one-time or recurring payment.
- `month_closures`: closure per household period (start/end, closed by user, note).
- Changes to transactions/plans/recurring start dates are blocked for closed periods.

## Audit & Live
- `audit_events`: history of all changes (old/new JSON, user/household, table, action).
- `chat_messages`: live chat per household (WS feed).
- `row_version`: optimistic locking on core tables.

## Attachments
- `attachments`: household, optional transaction ID, original/stored filename, MIME, size, storage path, optional Paperless ID.
- Files live under `HB_UPLOAD_DIR` (default `/srv/haushaltsbuch/uploads/<household_id>/`) outside the webroot and are served via `attachments.php`.

## Amounts
- All money values are stored as integer cents. Inputs parse decimal strings; internal values are positive, type controls inflow/outflow interpretation.
