# BudgetLove AI Integrations

BudgetLove can be controlled by AI clients through two integration paths:

- **Custom GPT Actions** for ChatGPT Custom GPTs.
- **MCP** for Claude Desktop and other local MCP-compatible clients.

Both integrations use the same BudgetLove HTTP API and require a BudgetLove API token.

## Integration Overview

| Use case | Recommended integration | Runs where | Transport |
| --- | --- | --- | --- |
| ChatGPT Custom GPT | Custom GPT Actions | OpenAI-hosted GPT | HTTPS + OpenAPI |
| Claude Desktop | MCP | Local machine | stdio MCP bridge + HTTPS API |
| Local automation | Direct API or MCP | Local/server | HTTPS or stdio |

## Security Model

API tokens are created inside BudgetLove:

```text
Household -> Settings -> API Tokens
```

Rules:

- Create a separate token for each integration.
- Do not commit tokens to Git.
- Do not paste tokens into public issues, docs or chats.
- Rotate/delete a token if it was exposed.
- The token user determines which household the API can access.

The API expects this header:

```text
Authorization: Bearer YOUR_TOKEN_HERE
```

## Available API Capabilities

The current AI-facing API exposes:

- `GET /api/meta.php`: returns available accounts, categories and current date context.
- `POST /api/transactions.php`: creates a reviewed expense transaction.
- `POST /api/transaction_drafts.php`: creates an unreviewed receipt/transaction draft for later bank import matching.
- `POST /api/receipts.php`: accepts a receipt image as base64 and returns extracted receipt fields.

Important behavior:

- AI clients must call metadata before creating a transaction.
- Receipt-first workflows should create drafts, not final transactions.
- Bank statement import tries to match open receipt drafts by amount, date window and payee before inserting a new open bank booking.
- Transaction creation validates that the selected account belongs to the token household.
- Transaction creation validates that the selected category belongs to the token household and is active.
- Receipt processing may return empty OCR fields if OCR is not available server-side.

## Planned Receipt Split Workflow

This is a planned follow-up for mixed receipts and is not fully implemented yet.

Target use case:

- One receipt contains groceries, drugstore items, medicine, baby items or similar mixed purchases.
- The AI should suggest a categorized breakdown before anything is saved.

Planned stages:

1. Short-term:
   Create multiple `transaction_drafts` from one receipt, one per grouped category suggestion.

2. Long-term:
   Introduce true split transactions so one booking can contain multiple categorized child amounts.

Expected AI behavior for mixed receipts:

- Detect likely item groups such as groceries, household, baby items, health, pet supplies or deposit.
- Suggest a split summary before saving, for example:
  - `18,40 EUR -> Lebensmittel`
  - `5,46 EUR -> Haushalt`
- Ask for confirmation before creating the drafts.
- Keep a shared receipt reference so later bank import matching can still avoid duplicates.

Design rule:

- For now, compatibility with BudgetLove draft matching is more important than perfect split elegance.
- Therefore, multiple drafts from one receipt are the preferred first rollout.

## Custom GPT Setup

Use this path when creating a ChatGPT Custom GPT.

### Files

- GPT Actions OpenAPI schema: `docs/api/customgpt-openapi.yaml`
- Custom GPT detailed setup guide: `docs/CUSTOM_GPT_ACTIONS.md`

### Step-by-step

1. Open ChatGPT.
2. Go to `GPTs`.
3. Create a new GPT or edit an existing GPT.
4. Open `Configure`.
5. Add the instructions from the section `Recommended GPT Instructions` below.
6. Open `Actions`.
7. Create a new action.
8. Configure authentication.
9. Paste the full OpenAPI schema from `docs/api/customgpt-openapi.yaml`.
10. Save the action.
11. Test `getBudgetLoveMetadata`.

### Authentication in GPT Builder

If the GPT Builder offers a Bearer token mode:

```text
Token: YOUR_TOKEN_HERE
```

If the GPT Builder uses generic API key authentication:

```text
Header name: Authorization
Header value: Bearer YOUR_TOKEN_HERE
```

### Schema

Paste the full content of:

```text
docs/api/customgpt-openapi.yaml
```

The schema includes:

- `getBudgetLoveMetadata`
- `processBudgetLoveReceipt`
- `createBudgetLoveTransactionDraft`
- `createBudgetLoveTransaction`

`createBudgetLoveTransaction` and `createBudgetLoveTransactionDraft` are marked as consequential:

```yaml
x-openai-isConsequential: true
```

This tells ChatGPT that the action changes user data and should require confirmation.

### Recommended GPT Instructions

Use these instructions in the GPT configuration:

```text
You are BudgetLove Assistant.

You help manage a private household budget in BudgetLove.

Rules:
- Always call getBudgetLoveMetadata before creating a transaction.
- Use only account_id and category_id values returned by getBudgetLoveMetadata.
- Never invent account or category IDs.
- Before creating a transaction, summarize the proposed booking with amount, date, account, category, payee and notes.
- Ask the user for explicit confirmation before calling createBudgetLoveTransaction or createBudgetLoveTransactionDraft.
- If amount, date or account are unclear, ask a follow-up question.
- If category is unclear, either ask a follow-up question or create the transaction without category_id.
- For receipt images, first call processBudgetLoveReceipt, then propose a transaction draft.
- Prefer createBudgetLoveTransactionDraft for receipts. Later bank statement imports can match the real bank transaction to the draft, avoiding duplicates.
- Do not create duplicate transactions if the user asks the same thing twice; ask whether it was already booked.
- Add a short note for AI-created bookings, for example "Created via Custom GPT".
```

### Optional GPT Knowledge File

You can upload a knowledge file to the GPT with user-facing behavior rules. Do not include tokens.

Suggested file content:

```markdown
# BudgetLove GPT Knowledge

BudgetLove is a private self-hosted household budget app.

The GPT must act as a careful finance assistant:

- Understand the user's request.
- Fetch metadata before booking.
- Propose the booking.
- Ask for confirmation.
- Create the booking only after confirmation.

Never invent account or category IDs.
Never create a transaction without explicit confirmation.
Never store or reveal API tokens.

Typical category guidance:

- Amazon, Netflix, Spotify, BookBeat, Audible -> Medien
- Steam, games, gaming subscriptions -> Gaming
- Hardware store, garden, tools, renovation -> Haus und Garten
- Streamlabs, YouTube, Twitch, creator tools -> Content Creation
- Groceries, supermarket, discounter -> Lebensmittel
- Fuel, car, public transport -> Mobilität
- Electricity, gas, heating, energy provider -> Energie
- Rent, home, shared household costs -> Wohnen or Haushalt
- Doctor, vet, pharmacy -> Gesundheit
- Insurance -> Versicherung
- Salary -> Gehalt
- Unclear expenses -> ask the user or use Sonstiges
```

### Custom GPT Test Prompts

Metadata test:

```text
Welche Konten und Kategorien sind in BudgetLove verfügbar?
```

Expected:

- The GPT calls `getBudgetLoveMetadata`.
- It shows available accounts and categories.

Booking confirmation test:

```text
Buche 1,23 Euro heute auf mein Hauptkonto in Sonstiges mit Händler Custom GPT Test.
```

Expected:

- The GPT calls `getBudgetLoveMetadata`.
- It proposes the transaction.
- It asks for confirmation.
- It only calls `createBudgetLoveTransaction` after confirmation.

Receipt test:

```text
Ich habe einen Beleg über 12,99 Euro von Amazon. Welche Kategorie passt?
```

Expected:

- The GPT fetches metadata.
- It suggests a category.
- It asks whether to create a receipt draft.
- It uses `createBudgetLoveTransactionDraft` after confirmation.

## Claude Desktop MCP Setup

Use this path when connecting Claude Desktop or another MCP-compatible local client.

### Files

- MCP server script: `tools/mcp/budgetlove_mcp.py`
- MCP README: `tools/mcp/README.md`
- MCP testing guide: `docs/MCP_TESTING.md`

### Requirements

- Python 3 on the client machine.
- Local checkout of this repository.
- Network access to `https://app.budgetlove.de`.
- BudgetLove API token.

The MCP server has no external Python package dependencies.

### Claude Desktop Configuration

Add this to the Claude Desktop MCP configuration, adjusting the path to your local checkout:

```json
{
  "mcpServers": {
    "budgetlove": {
      "command": "python3",
      "args": [
        "/absolute/path/to/BudgedLove_Track-Plan-Controll/tools/mcp/budgetlove_mcp.py"
      ],
      "env": {
        "BUDGETLOVE_API_BASE": "https://app.budgetlove.de",
        "BUDGETLOVE_API_TOKEN": "YOUR_TOKEN_HERE"
      }
    }
  }
}
```

Restart Claude Desktop after changing the config.

### MCP Tools

The MCP bridge exposes:

- `get_metadata`: returns BudgetLove accounts, categories and current date context.
- `create_transaction`: creates a reviewed expense transaction.
- `create_transaction_draft`: creates an open receipt/transaction draft for later bank import matching.
- `process_receipt`: uploads a receipt image and returns extracted fields.

### MCP Smoke Test

Run from the repo root:

```bash
printf '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}\n{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}\n' \
  | python3 tools/mcp/budgetlove_mcp.py
```

Expected:

- Initialize response.
- Tool list containing `get_metadata`, `create_transaction`, `create_transaction_draft`, `process_receipt`.

### MCP Metadata Test

```bash
printf '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"get_metadata","arguments":{}}}\n' \
  | BUDGETLOVE_API_BASE="https://app.budgetlove.de" \
    BUDGETLOVE_API_TOKEN="YOUR_TOKEN_HERE" \
    python3 tools/mcp/budgetlove_mcp.py
```

Expected:

- JSON-RPC result.
- Metadata payload with accounts and categories.

## Direct API Tests

Unauthorized metadata test:

```bash
curl -i https://app.budgetlove.de/api/meta.php
```

Expected:

```text
HTTP/2 401
```

Authorized metadata test:

```bash
curl -i \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  https://app.budgetlove.de/api/meta.php
```

Expected:

```text
HTTP/2 200
```

Receipt JSON test:

```bash
curl -i \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Content-Type: application/json" \
  -d '{"image_base64":"dGVzdA=="}' \
  https://app.budgetlove.de/api/receipts.php
```

Expected:

```text
HTTP/2 200
```

Transaction validation test without creating a booking:

```bash
curl -i \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Content-Type: application/json" \
  -d '{"amount":1.23,"date":"2026-05-14","account_id":8,"category_id":999999,"payee":"Validation Test","notes":"Should not be created"}' \
  https://app.budgetlove.de/api/transactions.php
```

Expected:

```text
HTTP/2 400
```

The error should be:

```json
{"error":"category_id not found"}
```

## Troubleshooting

### Custom GPT asks for approval

This is normal for a new external action or domain. Approve the action in the GPT Builder test window.

### Custom GPT gets `401 Unauthorized`

Check:

- Token is correct.
- Header is `Authorization`.
- Value is `Bearer YOUR_TOKEN_HERE` unless the builder uses a dedicated Bearer mode.
- Token was not deleted or rotated.

### Custom GPT cannot resolve or connect

Check:

- `https://app.budgetlove.de` is publicly reachable.
- DNS points to the correct server.
- HTTPS certificate is valid.
- Reverse proxy and BudgetLove containers are running.

On the server:

```bash
docker ps
curl -i https://app.budgetlove.de/api/meta.php
```

### MCP tool is not visible in Claude

Check:

- Claude Desktop was restarted.
- The script path is absolute and local to the client machine.
- `python3` exists on the client.
- The MCP JSON config is valid.

### Transaction uses wrong IDs

The AI client must fetch metadata again and use current IDs. IDs are household-specific.

### Duplicate transactions

The API currently creates a transaction when called. The AI client must ask for confirmation and should ask about possible duplicates before booking repeated requests.

## Public Repository Notes

Safe to commit:

- OpenAPI schemas.
- MCP client code.
- Integration instructions.
- Placeholder examples.

Never commit:

- Real API tokens.
- Private bank data.
- Personal production exports.
- Local Claude Desktop config containing secrets.
