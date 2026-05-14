# BudgetLove Custom GPT Actions

This document explains how to connect a Custom GPT directly to BudgetLove.

## Goal

The Custom GPT uses HTTPS actions, not MCP. It calls the BudgetLove API through an OpenAPI schema.

Use MCP for local clients such as Claude Desktop. Use Custom GPT Actions for ChatGPT Custom GPTs.

## Files

- OpenAPI schema: `docs/api/customgpt-openapi.yaml`
- Existing API implementation:
  - `GET /api/meta.php`
  - `POST /api/transactions.php`
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
- Before creating a transaction, summarize the proposed booking with amount, date, account, category, payee and notes.
- Ask the user for explicit confirmation before calling createBudgetLoveTransaction.
- If amount, date or account are unclear, ask a follow-up question.
- If category is unclear, either ask a follow-up question or create the transaction without category_id.
- For receipt images, first call processBudgetLoveReceipt, then propose a transaction.
- Do not create duplicate transactions if the user asks the same thing twice; ask whether it was already booked.
- Add a short note for AI-created bookings, for example "Created via Custom GPT".
```

## Available Actions

### `getBudgetLoveMetadata`

Fetches:

- Current month and year.
- Categories.
- Accounts.

Use this before any booking.

### `createBudgetLoveTransaction`

Creates a reviewed expense transaction.

This action is marked as consequential in the OpenAPI schema:

```yaml
x-openai-isConsequential: true
```

The GPT should require user confirmation before calling it.

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
Welche Konten und Kategorien sind in BudgetLove verfügbar?
```

Expected: GPT calls `getBudgetLoveMetadata`.

```text
Buche 1,23 Euro heute auf SPK 80 in Sonstiges mit Händler Custom GPT Test.
```

Expected: GPT fetches metadata, proposes the booking and asks for confirmation before creating it.

```text
Ich habe einen Beleg über 12,99 Euro von Amazon. Welche Kategorie passt?
```

Expected: GPT fetches metadata and suggests a category, but does not book without confirmation.

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

The current receipt endpoint may return empty OCR fields if OCR is not available server-side. This is acceptable for the first Custom GPT version because the GPT can still use user-provided receipt details to create a booking proposal.
