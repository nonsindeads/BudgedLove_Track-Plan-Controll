create table if not exists oauth_access_tokens (
    id char(64) primary key,
    user_id bigint not null references users(id) on delete cascade,
    client_id varchar(64) not null references oauth_clients(id) on delete cascade,
    household_id int not null references households(id) on delete cascade,
    scopes text not null,
    expires_at timestamptz not null,
    revoked boolean not null default false,
    created_at timestamptz not null default now()
);

create index if not exists oauth_access_tokens_user_idx on oauth_access_tokens (user_id, created_at desc);
create index if not exists oauth_access_tokens_household_idx on oauth_access_tokens (household_id, revoked, expires_at);

create table if not exists oauth_refresh_tokens (
    id char(64) primary key,
    access_token_id char(64) not null references oauth_access_tokens(id) on delete cascade,
    user_id bigint not null references users(id) on delete cascade,
    client_id varchar(64) not null references oauth_clients(id) on delete cascade,
    household_id int not null references households(id) on delete cascade,
    expires_at timestamptz not null,
    revoked boolean not null default false,
    reuse_detected boolean not null default false,
    created_at timestamptz not null default now()
);

create index if not exists oauth_refresh_tokens_user_idx on oauth_refresh_tokens (user_id, client_id, created_at desc);
