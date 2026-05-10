create table if not exists api_tokens (
    id bigserial primary key,
    user_id bigint not null references users(id) on delete cascade,
    token_hash char(64) not null unique,
    label text not null default '',
    last_used_at timestamptz null,
    created_at timestamptz not null default now()
);

create index if not exists api_tokens_user_idx on api_tokens (user_id, created_at desc);
