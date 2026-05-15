alter table rate_limits add column if not exists token_id bigint null;
create index if not exists rate_limits_token_idx on rate_limits (token_id, created_at);
