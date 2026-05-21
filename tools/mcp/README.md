# BudgetLove MCP Server

This MCP server wraps the BudgetLove HTTP API for local AI clients such as Claude Desktop.

It uses stdio JSON-RPC and has no external Python dependencies.

## Tools

- `get_metadata`: returns accounts, categories and current date context.
- `create_transaction`: creates a reviewed expense transaction.
- `create_transaction_draft`: creates an open receipt/transaction draft for later bank import matching.
- `process_receipt`: uploads a receipt image as base64 or from a local file path.

## Environment

- `BUDGETLOVE_API_BASE`: optional, defaults to `https://app.budgetlove.de`
- `BUDGETLOVE_API_TOKEN`: required, generated in `Household -> Settings -> API Tokens`
- `BUDGETLOVE_API_TIMEOUT`: optional seconds, defaults to `30`

## Claude Desktop Config

Use an absolute path on the client machine:

```json
{
  "mcpServers": {
    "budgetlove": {
      "command": "python3",
      "args": ["/path/to/BudgedLove_Track-Plan-Controll/tools/mcp/budgetlove_mcp.py"],
      "env": {
        "BUDGETLOVE_API_BASE": "https://app.budgetlove.de",
        "BUDGETLOVE_API_TOKEN": "YOUR_TOKEN_HERE"
      }
    }
  }
}
```

Keep the token in the local MCP config, not in chat transcripts.

## Smoke Test

```bash
printf '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}\n{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}\n' \
  | python3 tools/mcp/budgetlove_mcp.py
```

With a token:

```bash
printf '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"get_metadata","arguments":{}}}\n' \
  | BUDGETLOVE_API_TOKEN='...' python3 tools/mcp/budgetlove_mcp.py
```

For a complete test checklist including API checks, transaction tests, receipt tests and troubleshooting, see `docs/api/mcp-testing.md`.

## Example Tool Call

```json
{
  "name": "create_transaction",
  "arguments": {
    "amount": 12.99,
    "date": "2026-05-12",
    "account_id": 8,
    "category_id": 42,
    "payee": "Example Shop",
    "notes": "Booked via MCP"
  }
}
```
