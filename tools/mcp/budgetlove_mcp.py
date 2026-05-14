#!/usr/bin/env python3
"""Minimal MCP stdio server for BudgetLove.

This implementation intentionally uses only the Python standard library so it
can run on Linux/macOS clients without installing dependencies.
"""
from __future__ import annotations

import base64
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from typing import Any


SERVER_NAME = "budgetlove"
SERVER_VERSION = "0.1.0"
DEFAULT_API_BASE = "https://app.budgetlove.de"


def api_base() -> str:
    return os.environ.get("BUDGETLOVE_API_BASE", DEFAULT_API_BASE).rstrip("/")


def api_token() -> str:
    return os.environ.get("BUDGETLOVE_API_TOKEN", "").strip()


def api_timeout() -> float:
    raw = os.environ.get("BUDGETLOVE_API_TIMEOUT", "30")
    try:
        return max(1.0, float(raw))
    except ValueError:
        return 30.0


def write_message(message: dict[str, Any]) -> None:
    sys.stdout.write(json.dumps(message, separators=(",", ":")) + "\n")
    sys.stdout.flush()


def rpc_result(message_id: Any, result: Any) -> dict[str, Any]:
    return {"jsonrpc": "2.0", "id": message_id, "result": result}


def rpc_error(message_id: Any, code: int, message: str, data: Any = None) -> dict[str, Any]:
    error: dict[str, Any] = {"code": code, "message": message}
    if data is not None:
        error["data"] = data
    return {"jsonrpc": "2.0", "id": message_id, "error": error}


def tool_text(payload: Any, is_error: bool = False) -> dict[str, Any]:
    text = payload if isinstance(payload, str) else json.dumps(payload, ensure_ascii=False, indent=2)
    result: dict[str, Any] = {"content": [{"type": "text", "text": text}]}
    if is_error:
        result["isError"] = True
    return result


def request_api(
    method: str,
    path: str,
    *,
    json_body: dict[str, Any] | None = None,
    form_body: dict[str, str] | None = None,
) -> Any:
    token = api_token()
    if token == "":
        raise RuntimeError("BUDGETLOVE_API_TOKEN is not set")

    body: bytes | None = None
    headers = {
        "Authorization": f"Bearer {token}",
        "Accept": "application/json",
        "User-Agent": f"BudgetLove-MCP/{SERVER_VERSION}",
    }
    if json_body is not None:
        body = json.dumps(json_body).encode("utf-8")
        headers["Content-Type"] = "application/json"
    elif form_body is not None:
        body = urllib.parse.urlencode(form_body).encode("utf-8")
        headers["Content-Type"] = "application/x-www-form-urlencoded"

    request = urllib.request.Request(
        api_base() + path,
        data=body,
        headers=headers,
        method=method,
    )

    try:
        with urllib.request.urlopen(request, timeout=api_timeout()) as response:
            raw = response.read().decode("utf-8")
            return json.loads(raw) if raw else {}
    except urllib.error.HTTPError as exc:
        raw = exc.read().decode("utf-8", errors="replace")
        try:
            payload = json.loads(raw)
        except json.JSONDecodeError:
            payload = raw
        raise RuntimeError(f"BudgetLove API returned HTTP {exc.code}: {payload}") from exc
    except urllib.error.URLError as exc:
        raise RuntimeError(f"BudgetLove API request failed: {exc.reason}") from exc


def list_tools() -> list[dict[str, Any]]:
    return [
        {
            "name": "get_metadata",
            "description": "Get BudgetLove accounts, categories and current period context.",
            "inputSchema": {
                "type": "object",
                "properties": {},
                "additionalProperties": False,
            },
        },
        {
            "name": "create_transaction",
            "description": "Create a reviewed expense transaction in BudgetLove.",
            "inputSchema": {
                "type": "object",
                "properties": {
                    "amount": {"type": "number", "description": "Amount in EUR, positive number."},
                    "date": {"type": "string", "format": "date", "description": "Booking date as YYYY-MM-DD."},
                    "account_id": {"type": "integer", "description": "BudgetLove account id."},
                    "category_id": {"type": "integer", "description": "Optional BudgetLove category id."},
                    "payee": {"type": "string", "description": "Optional payee name. Existing payees are matched case-insensitively."},
                    "notes": {"type": "string", "description": "Optional transaction note."},
                },
                "required": ["amount", "date", "account_id"],
                "additionalProperties": False,
            },
        },
        {
            "name": "create_transaction_draft",
            "description": "Create an unreviewed receipt/transaction draft for later bank import matching.",
            "inputSchema": {
                "type": "object",
                "properties": {
                    "amount": {"type": "number", "description": "Amount in EUR, positive number."},
                    "date": {"type": "string", "format": "date", "description": "Receipt or expected booking date as YYYY-MM-DD."},
                    "type": {"type": "string", "enum": ["expense", "income"], "description": "Draft type. Default: expense."},
                    "account_id": {"type": "integer", "description": "Optional BudgetLove account id."},
                    "category_id": {"type": "integer", "description": "Optional BudgetLove category id."},
                    "payee": {"type": "string", "description": "Optional merchant/payee name."},
                    "notes": {"type": "string", "description": "Optional draft note or OCR summary."},
                    "tag_ids": {"type": "array", "items": {"type": "integer"}, "description": "Optional BudgetLove tag ids."},
                },
                "required": ["amount", "date"],
                "additionalProperties": False,
            },
        },
        {
            "name": "process_receipt",
            "description": "Upload a receipt image to BudgetLove and return extracted receipt fields.",
            "inputSchema": {
                "type": "object",
                "properties": {
                    "image_base64": {"type": "string", "description": "Base64 encoded image content."},
                    "file_path": {"type": "string", "description": "Local image path readable by this MCP server."},
                },
                "additionalProperties": False,
            },
        },
    ]


def call_tool(name: str, arguments: dict[str, Any]) -> dict[str, Any]:
    if name == "get_metadata":
        return tool_text(request_api("GET", "/api/meta.php"))

    if name == "create_transaction":
        payload = {
            "amount": arguments.get("amount"),
            "date": arguments.get("date"),
            "account_id": arguments.get("account_id"),
            "category_id": arguments.get("category_id"),
            "payee": arguments.get("payee", ""),
            "notes": arguments.get("notes", ""),
        }
        return tool_text(request_api("POST", "/api/transactions.php", json_body=payload))

    if name == "create_transaction_draft":
        payload = {
            "amount": arguments.get("amount"),
            "date": arguments.get("date"),
            "type": arguments.get("type", "expense"),
            "account_id": arguments.get("account_id"),
            "category_id": arguments.get("category_id"),
            "payee": arguments.get("payee", ""),
            "notes": arguments.get("notes", ""),
            "tag_ids": arguments.get("tag_ids", []),
        }
        return tool_text(request_api("POST", "/api/transaction_drafts.php", json_body=payload))

    if name == "process_receipt":
        image_base64 = str(arguments.get("image_base64") or "")
        file_path = str(arguments.get("file_path") or "")
        if image_base64 == "" and file_path != "":
            with open(file_path, "rb") as handle:
                image_base64 = base64.b64encode(handle.read()).decode("ascii")
        if image_base64 == "":
            return tool_text("Provide image_base64 or file_path.", is_error=True)
        return tool_text(request_api("POST", "/api/receipts.php", form_body={"image_base64": image_base64}))

    return tool_text(f"Unknown tool: {name}", is_error=True)


def handle_request(message: dict[str, Any]) -> dict[str, Any] | None:
    message_id = message.get("id")
    method = message.get("method")
    params = message.get("params") or {}

    if method == "notifications/initialized":
        return None
    if method == "initialize":
        return rpc_result(
            message_id,
            {
                "protocolVersion": "2024-11-05",
                "capabilities": {"tools": {}},
                "serverInfo": {"name": SERVER_NAME, "version": SERVER_VERSION},
            },
        )
    if method == "ping":
        return rpc_result(message_id, {})
    if method == "tools/list":
        return rpc_result(message_id, {"tools": list_tools()})
    if method == "tools/call":
        name = str(params.get("name", ""))
        arguments = params.get("arguments") or {}
        if not isinstance(arguments, dict):
            return rpc_error(message_id, -32602, "Tool arguments must be an object")
        try:
            return rpc_result(message_id, call_tool(name, arguments))
        except Exception as exc:  # Return tool failures as MCP tool errors.
            return rpc_result(message_id, tool_text(str(exc), is_error=True))

    return rpc_error(message_id, -32601, f"Method not found: {method}")


def run() -> int:
    for line in sys.stdin:
        line = line.strip()
        if line == "":
            continue
        try:
            message = json.loads(line)
        except json.JSONDecodeError as exc:
            write_message(rpc_error(None, -32700, "Parse error", str(exc)))
            continue
        response = handle_request(message)
        if response is not None:
            write_message(response)
    return 0


if __name__ == "__main__":
    raise SystemExit(run())
