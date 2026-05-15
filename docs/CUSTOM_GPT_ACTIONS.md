# BudgetLove Custom GPT Actions

This document explains how to connect a Custom GPT directly to BudgetLove.

## Goal

The Custom GPT uses HTTPS actions, not MCP. It calls the BudgetLove API through an OpenAPI schema.

Use MCP for local clients such as Claude Desktop. Use Custom GPT Actions for ChatGPT Custom GPTs.

## Files

- OpenAPI schema: `docs/api/customgpt-openapi.yaml`
- Existing API implementation:
  - `GET /api/meta.php`
  - `GET /api/transactions.php`
  - `POST /api/transactions.php`
  - `PATCH /api/transactions.php?id=...`
  - `DELETE /api/transactions.php?id=...`
  - `POST /api/transaction_drafts.php`
  - `POST /api/receipts.php`

## Authentication

Use a BudgetLove API token from:

```text
Household -> Settings -> API Tokens
```

In the GPT Builder action settings, configure authentication as API key or Bearer token.

The API expects:

```text
Authorization: Bearer YOUR_TOKEN_HERE
```

Do not paste real tokens into shared docs or chats. If a token is exposed, delete it and create a new one.

## Custom GPT Setup

1. Open the GPT Builder.
2. Create or edit the BudgetLove GPT.
3. Open `Actions`.
4. Create a new action.
5. Add authentication:
   - Type: API key / Bearer token.
   - Header: `Authorization`.
   - Value format: `Bearer YOUR_TOKEN_HERE`.
6. Paste the schema from `docs/api/customgpt-openapi.yaml`.
7. Save the action.
8. Run the built-in action tests.

If the builder has a dedicated Bearer token mode, enter only the token when requested. If it has generic API key auth, use the full `Bearer TOKEN` value in the `Authorization` header.

## GPT Instructions

Use these instructions in the Custom GPT:

```text
You are BudgetLove Assistant – a financial planning and transaction management AI.

You help users manage household budgets, track spending, plan finances, and ensure data accuracy in BudgetLove.

## Core Rules

### Data Integrity
- Always call getBudgetLoveMetadata at the start of a session to get current accounts, categories, payees, and tags.
- Use only IDs returned by getBudgetLoveMetadata. Never invent or assume IDs.
- If referenced data is missing, offer to create it (payee, tag) or ask the user to specify it.
- Always verify data exists before using it in transactions or updates.

### Transaction Management
- Before creating or updating a transaction, summarize the proposal: amount, date, account, category, payee, and notes.
- Ask for explicit confirmation before calling any consequential action (create, update, delete).
- For unclear amounts, dates, or accounts, ask clarifying questions.
- For unclear categories, either ask or create the transaction without category_id.
- Before suggesting a category, check existing transactions with listBudgetLoveTransactions to understand patterns.

### Duplicate Prevention
- Before creating a transaction, check if it already exists with listBudgetLoveTransactions.
- If the user asks to correct an existing booking, fetch it first, then updateBudgetLoveTransaction (never create a duplicate).
- Use getBudgetLoveAnalytics?endpoint=duplicate_candidates to detect and warn about potential duplicates.
- If the user asks the same thing twice, ask "Was this already booked?" before proceeding.

### Receipt & Draft Workflows
- For receipt images: first call processBudgetLoveReceipt, then propose a transaction draft with the extracted data.
- Prefer createBudgetLoveTransactionDraft for receipt-first workflows. Bank imports are the source of truth.
- Mark drafts with a note like "Receipt draft – pending bank import" for clarity.

### AI Transparency
- Add a short note to AI-created bookings: "Created via Custom GPT" or "Imported from receipt".
- Explain to users why you're checking data before creating: "Let me verify this hasn't been booked yet."

## Financial Insights

### Planning
- Use listBudgetLovePlannedPayments to show upcoming expenses and income.
- Help users understand open obligations by calling getBudgetLoveAnalytics?endpoint=open_planned.
- Warn about upcoming high-priority payments before they're due.

### Analytics
- Use getBudgetLoveAnalytics?endpoint=month_summary to discuss spending trends and category breakdown.
- Reference open cases with listBudgetLoveOpenCases when relevant to financial planning.
- Suggest category changes if spending patterns suggest a better fit.

### Tags & Organization
- Suggest tags for organizing transactions (e.g., "Travel", "Medical", "Home Improvement").
- Offer to create new tags if the user describes a category they track.
- Use tags to help filter and analyze related transactions.
```

## Available Actions

### `getBudgetLoveMetadata`

Fetches:

- Current month and year.
- Categories.
- Accounts.
- Payees.
- Tags.

Use this before any booking to get all reference data.

### `listBudgetLoveTransactions`

Reads transaction history and single bookings.

Use this to:

- inspect existing bookings before suggesting a category
- search by payee, note, date range or tag
- verify whether something was already booked
- locate a booking before updating or deleting it

### `listBudgetLovePayees`

Reads payees for the household.

Use this to:

- search for a payee by name or id
- verify payee names before creating or updating a transaction
- get a complete list of all payees

### `listBudgetLoveTags`

Reads tags for the household.

Use this to:

- search for a tag by name or id
- verify tag names before assigning to a transaction
- get a complete list of all tags

### `createBudgetLoveTransaction`

Creates a transaction.

This action is marked as consequential in the OpenAPI schema:

```yaml
x-openai-isConsequential: true
```

The GPT should require user confirmation before calling it.

### `updateBudgetLoveTransaction`

Updates an existing transaction by `id`.

Use this when an existing booking is wrong or incomplete. The GPT should first fetch the transaction history, present the proposed correction, and then ask for confirmation.

### `deleteBudgetLoveTransaction`

Deletes an existing transaction by `id`.

Use only after explicit user confirmation.

### `createBudgetLovePayee`

Creates a new payee.

Use this to add merchant/payee names not yet in the database. Marked as consequential.

### `updateBudgetLovePayee`

Updates an existing payee by `id`.

Use this to correct payee names. Marked as consequential.

### `deleteBudgetLovePayee`

Deletes an existing payee by `id`.

Use only for payees not linked to transactions, after explicit user confirmation.

### `createBudgetLoveTag`

Creates a new tag.

Use this to add organizational tags. Optionally include a color. Marked as consequential.

### `updateBudgetLoveTag`

Updates an existing tag by `id`.

Use this to rename tags or change colors. Marked as consequential.

### `deleteBudgetLoveTag`

Deactivates an existing tag by `id`.

Deactivated tags remain linked to historical transactions but are hidden from the UI. Use only after explicit user confirmation.

### `listBudgetLovePlannedPayments`

Reads planned payments (upcoming expenses or income).

Use this to:

- check upcoming planned expenses and income
- filter by status (open, resolved, cancelled), date range, account, category, or payee
- get an overview of open financial obligations or planned income

### `listBudgetLoveOpenCases`

Reads open cases (outstanding items, claims, follow-ups).

Use this to:

- check status of open cases
- search by title, reference, contact name or notes
- track unresolved matters and their details

### `getBudgetLoveAnalytics`

Fetches analytics and summary data.

Use this to retrieve:

- **month_summary**: Monthly transaction totals and category breakdown
- **open_planned**: Sum of open planned payments by direction
- **duplicate_candidates**: Potential duplicate transactions by amount and date

Set the `endpoint` parameter to select which data to retrieve.

### `listBudgetLoveRecurringRules`

Reads recurring rules (scheduled transactions or payments).

Use this to:

- check all recurring expenses and income
- see when the next execution is planned (next_run_at)
- filter by status (active/inactive) or type (transaction/payment)
- understand the household's recurring financial obligations

### `createBudgetLoveTransactionDraft`

Creates an open receipt/transaction draft in BudgetLove.

Use this for receipt-first workflows. The draft is visible under open bookings and remains unreviewed until the user finalizes it. Later bank statement imports try to match matching bank transactions to existing drafts by amount, date window and payee, avoiding duplicate bookings.

This action is also consequential and requires user confirmation.

### `processBudgetLoveReceipt`

Processes a base64 encoded receipt image and returns extracted fields.

The endpoint does not create a transaction.

## Manual API Tests

Unauthorized check:

```bash
curl -i https://app.budgetlove.de/api/meta.php
```

Expected:

```text
HTTP/2 401
```

Authorized metadata check:

```bash
curl -i \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  https://app.budgetlove.de/api/meta.php
```

Expected:

```text
HTTP/2 200
```

Create a small test transaction:

```bash
curl -i \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Content-Type: application/json" \
  -d '{"amount":1.23,"date":"2026-05-14","account_id":8,"category_id":66,"payee":"Custom GPT Test","notes":"Created via Custom GPT test - delete afterwards"}' \
  https://app.budgetlove.de/api/transactions.php
```

Expected:

```text
HTTP/2 201
```

Delete the test booking afterwards in the UI if needed.

## Custom GPT Test Prompts

Use these after the action is configured:

### Metadata & Discovery
```text
Zeig mir eine Übersicht: Konten, Kategorien, Zahlungsempfänger und Tags.
```
Expected: GPT calls `getBudgetLoveMetadata` and presents all reference data organized by type.

```text
Welche offenen Zahlungen stehen an?
```
Expected: GPT calls `listBudgetLovePlannedPayments` with status=open to show upcoming obligations.

```text
Welche offenen Fälle gibt es?
```
Expected: GPT calls `listBudgetLoveOpenCases` to list outstanding items.

### Transaction Management
```text
Buche 12,99 Euro heute auf mein Hauptkonto zu Amazon.
```
Expected: GPT fetches metadata, checks for duplicates, proposes the booking with payee lookup, asks for confirmation before creating.

```text
Die Buchung von Amazon gestern war falsch. Es sollten 24,99 Euro sein, nicht 12,99.
```
Expected: GPT fetches recent Amazon transactions, identifies the correct one, proposes the correction, asks for confirmation before updating.

```text
Hat sich die gleiche Summe in den letzten 7 Tagen wiederholt? Zeig mir Duplikat-Kandidaten.
```
Expected: GPT calls `getBudgetLoveAnalytics?endpoint=duplicate_candidates` to detect potential duplicates.

### Receipt & Draft Workflow
```text
Ich habe einen Beleg von Rewe über 45,67 Euro. Wie sollte ich das eintragen?
```
Expected: GPT suggests a transaction draft to avoid duplicates when the bank import arrives. Asks for confirmation before creating the draft.

```text
Scanne meinen Kassenbon und erkläre mir, was ich eintragen sollte.
```
Expected: GPT calls `processBudgetLoveReceipt` with the image, displays extracted amount/payee, proposes a draft or booking based on extracted data.

### Payee & Tag Management
```text
Erstelle einen neuen Zahlungsempfänger "Stadtwerke München".
```
Expected: GPT confirms the action and calls `createBudgetLovePayee` with the name.

```text
Erstelle ein Tag "Haushalt" mit Farbe orange.
```
Expected: GPT asks for confirmation, then calls `createBudgetLoveTag` with name and color.

```text
Welche Ausgaben hatte ich diese Woche für Groceries?
```
Expected: GPT calls `listBudgetLoveTransactions` to filter by tag or category, summarizes amounts and trends.

### Analytics & Planning
```text
Gib mir eine Zusammenfassung für diesen Monat: Einnahmen, Ausgaben, Top-Kategorien.
```
Expected: GPT calls `getBudgetLoveAnalytics?endpoint=month_summary` and presents a clear breakdown.

```text
Wie viel Geld muss ich noch für geplante Zahlungen ausgeben?
```
Expected: GPT calls `getBudgetLoveAnalytics?endpoint=open_planned` to show total open obligations by income/expense.

```text
Welche Kategorien haben die höchsten Ausgaben?
```
Expected: GPT calls `getBudgetLoveAnalytics?endpoint=month_summary`, sorts by category, and highlights spending trends.

### Recurring Rules
```text
Welche wiederkehrenden Zahlungen gibt es?
```
Expected: GPT calls `listBudgetLoveRecurringRules` to show all active recurring payments/transactions.

```text
Zeig mir meine geplanten und wiederkehrenden Zahlungen für nächsten Monat.
```
Expected: GPT calls `listBudgetLovePlannedPayments` for planned payments and `listBudgetLoveRecurringRules` for recurring rules, then summarizes upcoming obligations.

```text
Wann findet die nächste Mietzahlung statt?
```
Expected: GPT calls `listBudgetLoveRecurringRules` with search for "Miete", displays next_run_at and frequency.

## Troubleshooting

### Custom GPT cannot call the API

Check:

- `https://app.budgetlove.de` resolves publicly.
- HTTPS certificate is valid.
- `hb_proxy`, `hb_web`, `hb_app` and `hb_db` containers are running.
- Token authentication is configured as `Authorization: Bearer TOKEN`.

### GPT gets 401

- Token is missing or wrong.
- Token was rotated/deleted.
- Header format is wrong.

### GPT creates validation error

- It used an account or category ID from memory.
- Tell it to call `getBudgetLoveMetadata` again.
- Verify that the selected account/category belongs to the token household.

### GPT repeats a booking

- Check the transaction list in BudgetLove.
- Delete duplicates manually if required.
- Improve the GPT instruction to ask for duplicate confirmation.

## Notes

The current receipt endpoint may return empty OCR fields if OCR is not available server-side. This is acceptable because the GPT can still use user-provided receipt details to create a draft. For month-long receipt collection, use drafts first and let later bank imports match them.
