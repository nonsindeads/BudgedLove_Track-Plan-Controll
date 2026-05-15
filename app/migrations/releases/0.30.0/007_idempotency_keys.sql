create table if not exists api_idempotency_keys (
    id bigserial primary key,
    household_id int not null references households(id) on delete cascade,
    token_id text not null,
    idempotency_key varchar(255) not null,
    request_hash char(64) not null,
    response_body text not null,
    status_code smallint not null,
    created_at timestamptz not null default now(),
    unique (household_id, token_id, idempotency_key)
);

create index if not exists api_idempotency_household_idx on api_idempotency_keys (household_id, created_at);
