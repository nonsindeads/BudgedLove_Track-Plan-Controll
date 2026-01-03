create table if not exists rate_limits (
    id bigserial primary key,
    ip inet not null,
    action text not null,
    created_at timestamptz not null default now()
);

create index if not exists rate_limits_action_created_idx
    on rate_limits (action, created_at);

create index if not exists rate_limits_ip_action_created_idx
    on rate_limits (ip, action, created_at);
