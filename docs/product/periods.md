# Period Calculation

BudgetLove calculates budgets, forecasts, reports, plans and closes by household period. The setting belongs to the household, not to a single user or account. All members of the same household therefore see the same period boundaries.

## Modes

- `Start of month`: calendar months from the first to the last day.
- `Salary day`: fixed salary day, for example 27th to 26th.
- `Actual salary payment`: the period starts on the real salary transaction and ends the day before the next detected salary transaction.

## Recommended Setup

1. Create the household and primary account.
2. Import or create the salary transaction.
3. Assign the salary transaction to an income category, for example `Salary`.
4. Create or select the salary payee, for example the employer.
5. Open `Household > Settings`.
6. Set `Period calculation` to `Actual salary payment`.
7. Select the salary account, salary category and salary payee.

The mode can be changed later by a household admin. Existing transactions are not rewritten; BudgetLove recalculates the active period dynamically when pages are loaded.

## Fallback Behavior

If `Actual salary payment` is selected but no matching salary transaction exists yet, BudgetLove falls back to the configured salary day. If no salary day is configured, it falls back to the calendar month. This keeps new households usable before the first import.

## Current Coverage

The period logic is used by:

- Dashboard and forecast
- Budgets and savings
- Period plan
- Reports
- Period close
- Planned payment lock checks

Legacy URL parameters such as `month=YYYY-MM`, `current_month`, `previous_month` and `last_3_months` are still accepted for compatibility, but new navigation uses period presets.
