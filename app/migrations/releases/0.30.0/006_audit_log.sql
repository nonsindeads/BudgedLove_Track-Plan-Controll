create table if not exists api_audit_log (
    id bigserial primary key,
    request_id char(16) not null,
    user_id bigint null references users(id) on delete set null,
    household_id int null references households(id) on delete set null,
    token_id text null,
    client_id varchar(64) null,
    endpoint text not null,
    method varchar(10) not null,
    ip inet null,
    user_agent text null,
    status_code smallint not null,
    created_at timestamptz not null default now()
);

create index if not exists api_audit_log_household_idx on api_audit_log (household_id, created_at desc);
create index if not exists api_audit_log_request_idx on api_audit_log (request_id);
create index if not exists api_audit_log_user_idx on api_audit_log (user_id, created_at desc);
