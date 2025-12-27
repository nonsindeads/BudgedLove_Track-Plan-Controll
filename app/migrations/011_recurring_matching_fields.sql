-- Add matching fields for recurring payments and planned payment suggestions
alter table recurring_payments add column if not exists amount_mode varchar(16) not null default 'fixed';
alter table recurring_payments add column if not exists tolerance_cents bigint null;
alter table recurring_payments add column if not exists tolerance_pct numeric(5,2) null;
alter table recurring_payments add column if not exists min_amount_cents bigint null;
alter table recurring_payments add column if not exists max_amount_cents bigint null;

alter table transactions add column if not exists suggested_planned_payment_id bigint null references planned_payments(id) on delete set null;
