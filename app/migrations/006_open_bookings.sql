-- Open bookings review workflow + payee matching rules

create table if not exists payee_match_rules (
    id serial primary key,
    household_id int not null references households(id) on delete cascade,
    pattern varchar(255) not null,
    match_type varchar(16) not null default 'contains',
    payee_id int not null references payees(id) on delete restrict,
    priority smallint not null default 10,
    is_active boolean not null default true,
    row_version int not null default 1,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now()
);
create index if not exists payee_match_rules_household_idx on payee_match_rules (household_id, is_active, priority);

alter table transactions add column if not exists is_reviewed boolean not null default true;
alter table transactions add column if not exists counterparty_name text null;
alter table transactions add column if not exists suggested_payee_id int null references payees(id) on delete set null;
alter table transactions add column if not exists suggested_match_rule_id int null references payee_match_rules(id) on delete set null;

create trigger payee_match_rules_bump_row_version before update on payee_match_rules for each row execute function hb_bump_row_version();
create trigger payee_match_rules_audit after insert or update or delete on payee_match_rules for each row execute function hb_audit_trigger();
