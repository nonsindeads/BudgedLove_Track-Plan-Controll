create table if not exists saving_goals (
    id bigserial primary key,
    household_id int not null references households(id) on delete cascade,
    name varchar(255) not null,
    description text null,
    target_amount_cents bigint not null,
    current_amount_cents bigint not null default 0,
    target_date date null,
    monthly_contribution_cents bigint null,
    source_account_id int null references accounts(id) on delete set null,
    category_id int null references categories(id) on delete set null,
    priority varchar(16) not null default 'normal',
    status varchar(16) not null default 'active',
    is_optional boolean not null default true,
    storage_type varchar(32) not null default 'virtual',
    storage_account_id int null references accounts(id) on delete set null,
    cash_location varchar(255) null,
    completed_at timestamptz null,
    row_version int not null default 1,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now(),
    constraint saving_goals_target_amount_positive check (target_amount_cents > 0),
    constraint saving_goals_current_amount_nonnegative check (current_amount_cents >= 0),
    constraint saving_goals_monthly_nonnegative check (monthly_contribution_cents is null or monthly_contribution_cents >= 0),
    constraint saving_goals_priority_check check (priority in ('low', 'normal', 'high')),
    constraint saving_goals_status_check check (status in ('active', 'paused', 'completed', 'archived')),
    constraint saving_goals_storage_type_check check (storage_type in ('virtual', 'cash', 'external_account'))
);

create index if not exists saving_goals_household_idx on saving_goals (household_id);
create index if not exists saving_goals_household_status_idx on saving_goals (household_id, status);
create index if not exists saving_goals_household_category_idx on saving_goals (household_id, category_id);
create index if not exists saving_goals_household_source_account_idx on saving_goals (household_id, source_account_id);
create index if not exists saving_goals_household_storage_account_idx on saving_goals (household_id, storage_account_id);

create table if not exists saving_goal_contributions (
    id bigserial primary key,
    household_id int not null references households(id) on delete cascade,
    saving_goal_id bigint not null references saving_goals(id) on delete cascade,
    transaction_id bigint null references transactions(id) on delete set null,
    planned_payment_id bigint null references planned_payments(id) on delete set null,
    amount_cents bigint not null,
    contribution_date date not null,
    note text null,
    row_version int not null default 1,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now()
);

create index if not exists saving_goal_contributions_household_goal_idx on saving_goal_contributions (household_id, saving_goal_id);
create index if not exists saving_goal_contributions_household_date_idx on saving_goal_contributions (household_id, contribution_date);

alter table planned_payments add column if not exists saving_goal_id bigint null references saving_goals(id) on delete set null;
create index if not exists planned_payments_saving_goal_idx on planned_payments (saving_goal_id);

create trigger saving_goals_bump_row_version before update on saving_goals for each row execute function hb_bump_row_version();
create trigger saving_goals_audit after insert or update or delete on saving_goals for each row execute function hb_audit_trigger();
create trigger saving_goal_contributions_bump_row_version before update on saving_goal_contributions for each row execute function hb_bump_row_version();
create trigger saving_goal_contributions_audit after insert or update or delete on saving_goal_contributions for each row execute function hb_audit_trigger();
