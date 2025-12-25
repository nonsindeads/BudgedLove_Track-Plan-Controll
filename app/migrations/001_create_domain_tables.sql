-- Core domain tables for Haushaltsbuch MVP
create table if not exists households (
    id serial primary key,
    name varchar(255) not null,
    currency_code char(3) not null default 'EUR',
    month_close_mode varchar(32) not null default 'first_of_month',
    salary_day smallint null,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now()
);

create table if not exists household_members (
    household_id int not null references households(id) on delete cascade,
    user_id int not null references users(id) on delete cascade,
    role varchar(16) not null default 'editor',
    is_active boolean not null default true,
    created_at timestamptz not null default now(),
    primary key (household_id, user_id)
);

create table if not exists accounts (
    id serial primary key,
    household_id int not null references households(id) on delete cascade,
    name varchar(255) not null,
    type varchar(32) not null,
    currency_code char(3) not null default 'EUR',
    opening_balance_cents bigint not null default 0,
    is_archived boolean not null default false,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now()
);
create index if not exists accounts_household_archived_idx on accounts (household_id, is_archived);

create table if not exists categories (
    id serial primary key,
    household_id int null references households(id) on delete cascade,
    name varchar(255) not null,
    type varchar(16) not null,
    parent_id int null references categories(id) on delete set null,
    sort_order int not null default 0,
    is_active boolean not null default true,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now()
);
create index if not exists categories_household_type_idx on categories (household_id, type);
create index if not exists categories_parent_idx on categories (parent_id);

create table if not exists tags (
    id serial primary key,
    household_id int null references households(id) on delete cascade,
    name varchar(255) not null,
    color varchar(32) null,
    is_active boolean not null default true,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now(),
    unique (household_id, name)
);

create table if not exists payees (
    id serial primary key,
    household_id int not null references households(id) on delete cascade,
    name varchar(255) not null,
    address_text text null,
    iban varchar(64) null,
    bic varchar(32) null,
    notes text null,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now(),
    unique (household_id, name)
);

create table if not exists transactions (
    id bigserial primary key,
    household_id int not null references households(id) on delete cascade,
    type varchar(16) not null,
    booking_date date not null,
    amount_cents bigint not null,
    currency_code char(3) not null default 'EUR',
    account_id int null references accounts(id) on delete set null,
    category_id int null references categories(id) on delete set null,
    payee_id int null references payees(id) on delete set null,
    note text null,
    transfer_from_account_id int null references accounts(id) on delete set null,
    transfer_to_account_id int null references accounts(id) on delete set null,
    external_id varchar(255) null,
    import_hash varchar(255) null,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now(),
    constraint transactions_amount_positive check (amount_cents >= 0)
);
create index if not exists transactions_household_booking_idx on transactions (household_id, booking_date);
create index if not exists transactions_account_booking_idx on transactions (account_id, booking_date);

create table if not exists transaction_splits (
    id serial primary key,
    transaction_id bigint not null references transactions(id) on delete cascade,
    category_id int not null references categories(id) on delete restrict,
    amount_cents bigint not null,
    note text null,
    created_at timestamptz not null default now(),
    constraint transaction_splits_amount_positive check (amount_cents >= 0)
);

create table if not exists transaction_tags (
    transaction_id bigint not null references transactions(id) on delete cascade,
    tag_id int not null references tags(id) on delete cascade,
    primary key (transaction_id, tag_id)
);

create table if not exists recurring_rules (
    id serial primary key,
    household_id int not null references households(id) on delete cascade,
    is_active boolean not null default true,
    name varchar(255) not null,
    kind varchar(32) not null default 'transaction',
    schedule_unit varchar(16) not null,
    schedule_interval int not null default 1,
    schedule_weekdays varchar(64) null,
    schedule_monthday smallint null,
    schedule_start_date date not null,
    next_run_at timestamptz not null,
    last_run_at timestamptz null,
    payload_json text not null,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now()
);
create index if not exists recurring_rules_active_idx on recurring_rules (is_active, next_run_at);

create table if not exists tasks (
    id serial primary key,
    household_id int not null references households(id) on delete cascade,
    title varchar(255) not null,
    description text null,
    due_date date null,
    amount_cents bigint null,
    currency_code char(3) null,
    status varchar(16) not null default 'open',
    source_recurring_rule_id int null references recurring_rules(id) on delete set null,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now()
);
create index if not exists tasks_household_status_due_idx on tasks (household_id, status, due_date);

create table if not exists attachments (
    id bigserial primary key,
    household_id int not null references households(id) on delete cascade,
    transaction_id bigint null references transactions(id) on delete set null,
    original_filename varchar(255) not null,
    stored_filename varchar(255) not null,
    mime_type varchar(255) not null,
    size_bytes bigint not null,
    storage_path varchar(255) not null,
    paperless_document_id int null,
    created_at timestamptz not null default now()
);
create index if not exists attachments_transaction_idx on attachments (transaction_id);

create table if not exists recurring_executions (
    id serial primary key,
    recurring_rule_id int not null references recurring_rules(id) on delete cascade,
    executed_at timestamptz not null default now(),
    note text null
);
