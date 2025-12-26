-- Plan/recurring payment workflow tables

create table if not exists recurring_payments (
    id serial primary key,
    household_id int not null references households(id) on delete cascade,
    name varchar(255) not null,
    direction varchar(16) not null,
    amount_cents bigint not null,
    interval_unit varchar(16) not null,
    interval_value int not null default 1,
    start_date date not null,
    priority smallint not null default 3,
    is_optional boolean not null default false,
    account_id int null references accounts(id) on delete set null,
    category_id int null references categories(id) on delete set null,
    payee_id int null references payees(id) on delete set null,
    note text null,
    is_active boolean not null default true,
    row_version int not null default 1,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now()
);
create index if not exists recurring_payments_household_idx on recurring_payments (household_id, is_active);

create table if not exists planned_payments (
    id bigserial primary key,
    household_id int not null references households(id) on delete cascade,
    recurring_payment_id int null references recurring_payments(id) on delete set null,
    name varchar(255) not null,
    direction varchar(16) not null,
    amount_cents bigint not null,
    planned_date date not null,
    status varchar(16) not null default 'open',
    is_optional boolean not null default false,
    account_id int null references accounts(id) on delete set null,
    category_id int null references categories(id) on delete set null,
    payee_id int null references payees(id) on delete set null,
    note text null,
    resolved_at timestamptz null,
    resolved_transaction_id bigint null references transactions(id) on delete set null,
    row_version int not null default 1,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now()
);
create index if not exists planned_payments_household_date_idx on planned_payments (household_id, planned_date);
create index if not exists planned_payments_household_status_idx on planned_payments (household_id, status);

alter table transactions add column if not exists planned_payment_id bigint null references planned_payments(id) on delete set null;

create table if not exists open_cases (
    id serial primary key,
    household_id int not null references households(id) on delete cascade,
    title varchar(255) not null,
    status varchar(24) not null default 'open',
    reference varchar(255) null,
    contact_name varchar(255) null,
    contact_details text null,
    notes text null,
    planned_payment_id bigint null references planned_payments(id) on delete set null,
    recurring_payment_id int null references recurring_payments(id) on delete set null,
    row_version int not null default 1,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now()
);
create index if not exists open_cases_household_idx on open_cases (household_id, status);

create table if not exists month_closures (
    id serial primary key,
    household_id int not null references households(id) on delete cascade,
    period_start date not null,
    period_end date not null,
    closed_at timestamptz not null default now(),
    closed_by int null references users(id) on delete set null,
    note text null,
    row_version int not null default 1
);
create unique index if not exists month_closures_household_period_idx on month_closures (household_id, period_start);

create trigger recurring_payments_bump_row_version before update on recurring_payments for each row execute function hb_bump_row_version();
create trigger planned_payments_bump_row_version before update on planned_payments for each row execute function hb_bump_row_version();
create trigger open_cases_bump_row_version before update on open_cases for each row execute function hb_bump_row_version();
create trigger month_closures_bump_row_version before update on month_closures for each row execute function hb_bump_row_version();

create trigger recurring_payments_audit after insert or update or delete on recurring_payments for each row execute function hb_audit_trigger();
create trigger planned_payments_audit after insert or update or delete on planned_payments for each row execute function hb_audit_trigger();
create trigger open_cases_audit after insert or update or delete on open_cases for each row execute function hb_audit_trigger();
create trigger month_closures_audit after insert or update or delete on month_closures for each row execute function hb_audit_trigger();
