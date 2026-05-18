# QA API - Splits & Saving Goals

Statusdatum: 2026-05-18

## A) Existing Transaction -> Splits (No Recreate)

Endpoint: `PATCH /api/transactions.php?id=<ID>`

### Happy path

1. Pick existing expense transaction (for example `id=1251`).
2. Send `splits` with valid `category_id`s.
3. Ensure sum(`splits.amount`) equals transaction amount.
4. Verify response is `200` and transaction remains same ID.
5. Verify `transaction_splits` rows replaced for that ID.
6. Verify no new duplicate transaction row was created.

### Validation path

1. Send split sum mismatch.
2. Expect `400` with code `split_total_mismatch`.

## B) Payee Mapping Rules (Exact + Wildcard)

UI page: `payee_mapping.php`

### Exact rule

1. Create rule for exact counterparty text.
2. Import matching statement line.
3. Verify import detail reason: `Rule: exact payee mapping`.

### Wildcard rule

1. Create rule with `*` or `%` (for example `*LANDAU*`).
2. Import matching statement line.
3. Verify import detail reason: `Rule: wildcard payee mapping`.

## C) Saving Goals API MVP

Endpoint: `public/api/saving-goals.php`

### Create goal

1. `POST` with `name`, `target_amount`, `storage_type`.
2. For `virtual`, require `source_account_id`.
3. For `external_account`, require `storage_account_id`.
4. Verify `201` and returned goal object.

### Add contribution

1. `POST /api/saving-goals.php?resource=contributions`
2. Send `saving_goal_id`, `amount`, `contribution_date`.
3. Verify contribution created and `current_amount_cents` updated atomically.

### Archive goal

1. `DELETE /api/saving-goals.php?id=<ID>`
2. Verify `status=archived` (no hard delete).

