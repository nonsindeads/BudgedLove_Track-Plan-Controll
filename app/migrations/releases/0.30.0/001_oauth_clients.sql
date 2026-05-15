create table if not exists oauth_clients (
    id varchar(64) primary key,
    secret_hash varchar(128) null,
    name varchar(255) not null,
    redirect_uris jsonb not null default '[]',
    allowed_scopes text not null,
    is_confidential boolean not null default false,
    created_at timestamptz not null default now()
);

insert into oauth_clients (id, name, redirect_uris, allowed_scopes, is_confidential)
values ('chatgpt-budgetlove', 'ChatGPT BudgetLove GPT',
    '["https://chat.openai.com/aip/g-REPLACE_ME/oauth/callback","https://chatgpt.com/aip/g-REPLACE_ME/oauth/callback"]',
    'transactions:read transactions:write transactions:delete analytics:read receipts:write planned:read planned:write cases:read cases:write recurring:read recurring:write categories:read categories:write payees:read payees:write tags:read tags:write',
    false)
on conflict do nothing;
