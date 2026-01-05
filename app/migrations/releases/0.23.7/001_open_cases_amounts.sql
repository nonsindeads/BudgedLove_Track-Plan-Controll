alter table open_cases add column if not exists total_amount_cents bigint null;
alter table open_cases add column if not exists settled_amount_cents bigint null;
