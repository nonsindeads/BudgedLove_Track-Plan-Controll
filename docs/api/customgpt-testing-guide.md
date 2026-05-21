# BudgetLove CustomGPT – Comprehensive Testing Guide

Use this prompt to thoroughly test all API capabilities in the Custom GPT.

---

## Master Test Prompt

```text
You are now in **testing mode** for the BudgetLove CustomGPT API. Your goal is to validate all 17+ available operations across all feature areas. For each action below, explain what you're about to do, call the API operation, and validate the result.

## Phase 1: Setup & Metadata (Establish baseline)

1. **Call getBudgetLoveMetadata** and display:
   - Current month and year
   - All accounts (count them)
   - All categories (list top 5)
   - All payees (first 5)
   - All tags (first 5)

2. **Validate reference data completeness:**
   - Do we have at least 1 account? Required for transactions.
   - Do we have at least 5 categories? For good categorization.
   - Any payees? List their names.
   - Any tags? Describe their colors.

## Phase 2: Transaction Workflow (Core functionality)

3. **Search for recent transactions** using listBudgetLoveTransactions:
   - Fetch the 5 most recent transactions
   - Show amount, date, category, payee for each
   - Identify the most common category

4. **Analyze spending by date range:**
   - Fetch transactions from the last 7 days
   - Calculate total income and total expense
   - Identify the largest single transaction

5. **Duplicate detection:**
   - Call getBudgetLoveAnalytics?endpoint=duplicate_candidates
   - Report any suspicious duplicates found
   - Recommend which should be investigated

6. **Monthly summary:**
   - Call getBudgetLoveAnalytics?endpoint=month_summary
   - Show income, expense, and net for this month
   - List top 3 spending categories

## Phase 3: Payee & Tag Management (Data organization)

7. **Search for a payee:**
   - Call listBudgetLovePayees with search for "Amazon" or "Rewe"
   - If found, report the payee ID and name
   - If not found, create a test payee "TestPayee_API_Validation"

8. **Manage tags:**
   - List all active tags with listBudgetLoveTags
   - If fewer than 5 tags exist, create test tag "API_Test" with color "#FF5733"
   - Confirm the tag was created

## Phase 4: Planning & Forecasting (Financial outlook)

9. **Show upcoming obligations:**
   - Call listBudgetLovePlannedPayments with status=open
   - Display count of open payments and their total amount
   - Show the next 3 planned payments with dates

10. **Check open cases:**
    - Call listBudgetLoveOpenCases
    - Report count and summaries of any open cases
    - Example: outstanding claims, follow-ups

11. **Review recurring obligations:**
    - Call listBudgetLoveRecurringRules with active=true
    - List each recurring rule: name, frequency, next_run_at
    - Calculate estimated monthly recurring expense

12. **Financial forecast:**
    - Combine open_planned + recurring rules + month_summary
    - Create a simple forecast: "Based on recurring rules and planned payments, your next month looks like..."

## Phase 5: Draft & Receipt Workflow (Alternative entry paths)

13. **Show how receipt drafts work:**
    - Explain the workflow: receipt image → extract data → create draft → bank import matches it
    - Acknowledge that receipt processing requires an image
    - Explain why drafts prevent duplicates

14. **Review transaction drafts (if any):**
    - Call listBudgetLoveTransactions with reviewed=false
    - Identify any unreviewed (draft) transactions
    - Show their status and suggest review

## Phase 6: Validation & Error Handling (Robustness check)

15. **Test error handling:**
    - Try calling an invalid endpoint and report the error message
    - Try fetching a non-existent transaction (id=999999) and report the 404 response
    - Try using invalid filter values (e.g., type=invalid) and confirm validation works

16. **Verify data consistency:**
    - Fetch a transaction by ID
    - Fetch the same transaction via listBudgetLoveTransactions
    - Confirm both return the same amount, date, and category

## Final Report

After completing all 16 test points, provide a **Testing Summary**:

- ✅ Operations tested: count of successful API calls
- ✅ Data integrity: any inconsistencies found?
- ✅ Error handling: did error responses make sense?
- ✅ Coverage: which operations were tested and which not?
- ⚠️ Observations: anything unexpected or missing?
- 💡 Recommendations: what could be improved?

---

## Expected Behavior

- All GET operations should return data or a 404 if not found
- All write operations should ask for confirmation first
- Filtering and search should work as expected
- API errors should be descriptive
- Pagination (limit/offset) should work correctly
```

---

## Running This Test

1. **Copy the prompt** above
2. **Paste it** into the BudgetLove CustomGPT conversation
3. **Let the GPT execute** all 16 test phases
4. **Review the final report** for any issues

---

## CLI Readiness Check (Recommended Before Public Changes)

Use the automated script for reproducible baseline checks:

```bash
export BASE_URL="https://app.budgetlove.de"
export TOKEN="<budgetlove_api_token>"
# optional if your IDs differ
export TEST_ACCOUNT_ID="8"
export TEST_CATEGORY_ID="66"

tools/api/public-readiness-check.sh
```

The script verifies:
- structured JSON errors + `request_id`
- authenticated metadata access
- idempotency retry behavior
- idempotency conflict (`409`) on key/hash mismatch
- planned payments compatibility (`status=resolved`)
- delete-not-found semantics (`404`)
- open cases write path

---

## Success Criteria

✅ **All 17 operations are called and return valid responses**
✅ **No authentication errors (401)**
✅ **No validation errors (400) on valid inputs**
✅ **Data is consistent across multiple queries**
✅ **Error responses are clear and actionable**
✅ **Filters and search work as documented**

If any test fails, debug using the [Troubleshooting](#troubleshooting) section in `custom-gpt-actions.md`.
