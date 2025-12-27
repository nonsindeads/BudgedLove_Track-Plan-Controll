-- Add optional end date for recurring payments
alter table recurring_payments add column if not exists end_date date null;
