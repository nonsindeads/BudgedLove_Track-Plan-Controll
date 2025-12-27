-- Payee mapping table for imported counterparty names

create table if not exists payee_mappings (
    id serial primary key,
    household_id int not null references households(id) on delete cascade,
    counterparty_name varchar(255) not null,
    payee_id int null references payees(id) on delete set null,
    row_version int not null default 1,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now()
);
create unique index if not exists payee_mappings_household_counterparty_idx on payee_mappings (household_id, counterparty_name);

create trigger payee_mappings_bump_row_version before update on payee_mappings for each row execute function hb_bump_row_version();
create trigger payee_mappings_audit after insert or update or delete on payee_mappings for each row execute function hb_audit_trigger();
