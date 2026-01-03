-- Budgets
create table budgets (
  id bigserial primary key,
  household_id bigint not null references households(id) on delete cascade,
  name varchar(255) not null,
  amount_cents integer not null default 0,
  period_unit varchar(10) not null default 'month' check (period_unit in ('day','week','month','year')),
  period_value integer not null default 1,
  start_date date not null default current_date,
  end_date date,
  is_active boolean not null default true,
  note text,
  created_at timestamp with time zone not null default now(),
  updated_at timestamp with time zone not null default now()
);
create index budgets_household_idx on budgets(household_id);

create table budget_categories (
  id bigserial primary key,
  budget_id bigint not null references budgets(id) on delete cascade,
  category_id bigint not null references categories(id) on delete cascade,
  unique (budget_id, category_id)
);

-- Savings plans
create table savings_plans (
  id bigserial primary key,
  household_id bigint not null references households(id) on delete cascade,
  name varchar(255) not null,
  amount_cents integer not null default 0,
  interval_unit varchar(10),
  interval_value integer not null default 1,
  start_date date not null default current_date,
  end_date date,
  target_amount_cents integer,
  target_date date,
  account_id bigint references accounts(id) on delete set null,
  note text,
  is_active boolean not null default true,
  priority integer not null default 3,
  is_optional boolean not null default false,
  created_at timestamp with time zone not null default now(),
  updated_at timestamp with time zone not null default now()
);
create index savings_plans_household_idx on savings_plans(household_id);

create table savings_plan_categories (
  id bigserial primary key,
  savings_plan_id bigint not null references savings_plans(id) on delete cascade,
  category_id bigint not null references categories(id) on delete cascade,
  unique (savings_plan_id, category_id)
);

-- Link planned payments to savings plans for monthly plan generation
alter table planned_payments
  add column savings_plan_id bigint references savings_plans(id) on delete cascade;
create index planned_payments_savings_plan_idx on planned_payments(savings_plan_id);
