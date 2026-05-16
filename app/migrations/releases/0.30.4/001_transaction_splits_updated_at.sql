alter table transaction_splits add column if not exists updated_at timestamptz not null default now();
