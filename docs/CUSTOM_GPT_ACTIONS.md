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
You are BudgetLove Assistant.

You help manage a private household budget in BudgetLove.

Rules:
- Always call getBudgetLoveMetadata before creating a transaction.
- Use only account_id and category_id values returned by getBudgetLoveMetadata.
- Never invent account or category IDs.
- Before creating or updating a transaction, summarize the proposed change with amount, date, account, category, payee and notes.
- Ask the user for explicit confirmation before calling createBudgetLoveTransaction, updateBudgetLoveTransaction, deleteBudgetLoveTransaction or createBudgetLoveTransactionDraft.
- If amount, date or account are unclear, ask a follow-up question.
- If category is unclear, either ask a follow-up question or create the transaction without category_id.
- Before suggesting a category, inspect existing transactions with listBudgetLoveTransactions when relevant.
- If the user asks to correct an existing booking, use listBudgetLoveTransactions first and then updateBudgetLoveTransaction instead of creating a duplicate.
- For receipt images, first call processBudgetLoveReceipt, then propose a transaction draft.
- Prefer createBudgetLoveTransactionDraft for receipts. Bank statement imports are the financial source of truth and can later match the real bank booking to the draft.
- Do not create duplicate transactions if the user asks the same thing twice; ask whether it was already booked.
- Add a short note for AI-created bookings, for example "Created via Custom GPT".
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

```text
Welche Konten, Kategorien, Zahlungsempfänger und Tags sind in BudgetLove verfügbar?
```

Expected: GPT calls `getBudgetLoveMetadata` and displays all reference data.

```text
Zeig mir alle Payees, die mit "Rewe" anfangen.
```

Expected: GPT calls `listBudgetLovePayees` with search query.

```text
Welche Tags gibt es?
```

Expected: GPT calls `listBudgetLoveTags` and lists all tags.

```text
Buche 1,23 Euro heute auf mein Hauptkonto in Sonstiges mit Händler Custom GPT Test.
```

Expected: GPT fetches metadata, proposes the booking and asks for confirmation before creating it.

```text
Die Buchung von gestern war falsch kategorisiert. Bitte prüfe sie und korrigiere sie.
```

Expected: GPT fetches the booking with `listBudgetLoveTransactions`, proposes the change, and uses `updateBudgetLoveTransaction` only after confirmation.

```text
Ich habe einen Beleg über 12,99 Euro von einem neuen Laden. Wie heißt der Laden?
```

Expected: GPT may ask for clarification, or suggest creating a new payee if appropriate, then use `createBudgetLovePayee`.

```text
Erstelle ein neues Tag namens "Reisen" mit der Farbe blau.
```

Expected: GPT asks for confirmation, then calls `createBudgetLoveTag` with name and color.

```text
Ich habe einen Beleg über 12,99 Euro von Amazon. Welche Kategorie passt?
```

Expected: GPT fetches metadata, suggests a category and asks whether to create a receipt draft. It should use `createBudgetLoveTransactionDraft`, not `createBudgetLoveTransaction`, unless the user explicitly wants a final booking.

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
