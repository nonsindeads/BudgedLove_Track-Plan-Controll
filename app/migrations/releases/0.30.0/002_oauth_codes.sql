create table if not exists oauth_authorization_codes (
    code_hash char(64) primary key,
    user_id bigint not null references users(id) on delete cascade,
    client_id varchar(64) not null references oauth_clients(id) on delete cascade,
    household_id int not null references households(id) on delete cascade,
    redirect_uri text not null,
    scopes text not null,
    code_challenge text not null,
    code_challenge_method varchar(8) not null default 'S256',
    expires_at timestamptz not null,
    used boolean not null default false,
    created_at timestamptz not null default now()
);

create index if not exists oauth_codes_client_idx on oauth_authorization_codes (client_id, created_at desc);
