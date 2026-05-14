# BudgetLove MCP Testing

This guide describes how to test the BudgetLove MCP server locally and with Claude Desktop.

## Scope

The MCP server is a local bridge between an AI client and the BudgetLove HTTP API.

It currently exposes:

- `get_metadata`: read accounts, categories and date context.
- `create_transaction`: create a reviewed transaction.
- `create_transaction_draft`: create an open receipt/transaction draft for later bank import matching.
- `process_receipt`: upload a receipt image for OCR/API processing.

The MCP server does not store secrets. The API token must be provided by environment variable or by the local AI client config.

## Requirements

- Python 3.
- Network access to `https://app.budgetlove.de`.
- A BudgetLove API token from `Household -> Settings -> API Tokens`.
- Local checkout of this repository.

Example repo path:

```bash
/path/to/BudgedLove_Track-Plan-Controll
```

## Important Security Rule

Do not paste API tokens into tickets, chats or shared docs.

If a token was exposed, create a new token in BudgetLove and delete the old one from `Household -> Settings -> API Tokens`.

## Basic API Check

Test that the BudgetLove API is reachable:

```bash
curl -i https://app.budgetlove.de/api/meta.php
```

Expected without token:

```text
HTTP/2 401
```

Test with token:

```bash
curl -i \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  https://app.budgetlove.de/api/meta.php
```

Expected:

```text
HTTP/2 200
```

The response should contain `categories` and `accounts`.

## MCP Smoke Test

Run from the repo root:

```bash
cd /path/to/BudgedLove_Track-Plan-Controll
```

List available MCP tools:

```bash
printf '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}\n{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}\n' \
  | python3 tools/mcp/budgetlove_mcp.py
```

Expected:

- JSON-RPC initialize response.
- Tool list containing `get_metadata`, `create_transaction`, `create_transaction_draft`, `process_receipt`.

## MCP Metadata Test

```bash
printf '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"get_metadata","arguments":{}}}\n' \
  | BUDGETLOVE_API_BASE="https://app.budgetlove.de" \
    BUDGETLOVE_API_TOKEN="YOUR_TOKEN_HERE" \
    python3 tools/mcp/budgetlove_mcp.py
```

Expected:

- JSON-RPC result.
- Metadata payload with accounts and categories.
- No `Unauthorized` error.

## MCP Transaction Test

Use a small test transaction and delete it afterwards in the UI if needed.

First get valid `account_id` and `category_id` from `get_metadata`.

Example:

```bash
printf '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"create_transaction","arguments":{"amount":1.23,"date":"2026-05-14","account_id":8,"category_id":66,"payee":"MCP Test","notes":"MCP smoke test - delete after test"}}}\n' \
  | BUDGETLOVE_API_BASE="https://app.budgetlove.de" \
    BUDGETLOVE_API_TOKEN="YOUR_TOKEN_HERE" \
    python3 tools/mcp/budgetlove_mcp.py
```

Expected:

- JSON-RPC result.
- HTTP/API status for created transaction.
- The new transaction is visible in BudgetLove.

## MCP Receipt Test

Use a local image path readable by the machine running the MCP server:

```bash
printf '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"process_receipt","arguments":{"image_path":"/absolute/path/to/receipt.jpg"}}}\n' \
  | BUDGETLOVE_API_BASE="https://app.budgetlove.de" \
    BUDGETLOVE_API_TOKEN="YOUR_TOKEN_HERE" \
    python3 tools/mcp/budgetlove_mcp.py
```

Expected:

- JSON-RPC result.
- Structured fields such as `amount`, `date`, `payee`, `suggested_category`, `raw_text`.
- If OCR is not available server-side, empty fields are acceptable and the AI client can fill them.

## Claude Desktop Config

Use an absolute path on the client machine.

Example if the repo was copied to the Mac:

```json
{
  "mcpServers": {
    "budgetlove": {
      "command": "python3",
      "args": [
        "/Users/YOUR_USER/projects/BudgedLove_Track-Plan-Controll/tools/mcp/budgetlove_mcp.py"
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

## Troubleshooting

### `Unauthorized`

- Token is missing, wrong or expired.
- Header must be `Authorization: Bearer TOKEN`.
- Generate a new token in BudgetLove settings and retry.

### `Could not resolve host`

- DNS problem on the client machine.
- Test with `dig app.budgetlove.de`.
- Check VPN/DNS settings if the domain is only reachable through the intended resolver.

### `Failed to connect`

- Reverse proxy or Docker service may be down.
- On the VPS, check:

```bash
docker ps
curl -i https://app.budgetlove.de/api/meta.php
```

### MCP tool not visible in Claude

- Config path is wrong.
- Claude Desktop was not restarted.
- Python is not available as `python3` on the client.
- The MCP server script path must be local to the client machine.

### Transaction test creates wrong data

- Always call `get_metadata` first.
- Use IDs from the current household.
- Delete test transactions afterwards in the UI.

## Current Known Good Test

Last verified on the VPS:

- `GET /api/meta.php` with token returned `HTTP/2 200`.
- Response included categories and accounts for the token household.
- MCP script starts without external Python dependencies.
