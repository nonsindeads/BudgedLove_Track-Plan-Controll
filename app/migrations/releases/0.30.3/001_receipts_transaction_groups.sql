create table if not exists receipts (
    id bigserial primary key,
    household_id int not null references households(id) on delete cascade,
    merchant text null,
    receipt_date date null,
    total_amount_cents bigint null,
    currency_code char(3) not null default 'EUR',
    file_path text null,
    storage_key text null,
    file_hash varchar(128) null,
    mime_type varchar(255) null,
    ocr_json jsonb null,
    status varchar(16) not null default 'draft',
    row_version int not null default 1,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now(),
    constraint receipts_status_check check (status in ('draft', 'matched', 'archived')),
    constraint receipts_total_amount_nonnegative check (total_amount_cents is null or total_amount_cents >= 0)
);
create index if not exists receipts_household_status_idx on receipts (household_id, status);
create index if not exists receipts_household_date_idx on receipts (household_id, receipt_date);
create index if not exists receipts_household_file_hash_idx on receipts (household_id, file_hash);

create table if not exists transaction_groups (
    id bigserial primary key,
    household_id int not null references households(id) on delete cascade,
    receipt_id bigint null references receipts(id) on delete set null,
    account_id int null references accounts(id) on delete set null,
    payee_id int null references payees(id) on delete set null,
    payee text null,
    booking_date date not null,
    total_amount_cents bigint not null,
    currency_code char(3) not null default 'EUR',
    type varchar(16) not null default 'expense',
    notes text null,
    status varchar(16) not null default 'draft',
    external_id varchar(255) null,
    import_hash varchar(255) null,
    matched_transaction_id bigint null references transactions(id) on delete set null,
    row_version int not null default 1,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now(),
    constraint transaction_groups_type_check check (type in ('expense', 'income')),
    constraint transaction_groups_status_check check (status in ('draft', 'booked', 'archived')),
    constraint transaction_groups_total_amount_positive check (total_amount_cents > 0)
);
create index if not exists transaction_groups_household_status_idx on transaction_groups (household_id, status);
create index if not exists transaction_groups_household_date_idx on transaction_groups (household_id, booking_date);
create index if not exists transaction_groups_household_receipt_idx on transaction_groups (household_id, receipt_id);
create unique index if not exists transaction_groups_household_import_hash_idx
    on transaction_groups (household_id, import_hash)
    where import_hash is not null;

alter table transaction_splits add column if not exists household_id int null references households(id) on delete cascade;
alter table transaction_splits add column if not exists transaction_group_id bigint null references transaction_groups(id) on delete cascade;
alter table transaction_splits add column if not exists sort_order int not null default 0;
alter table transaction_splits alter column transaction_id drop not null;
alter table transaction_splits alter column category_id drop not null;
update transaction_splits ts
   set household_id = t.household_id
  from transactions t
 where ts.transaction_id = t.id
   and ts.household_id is null;
create index if not exists transaction_splits_household_group_idx on transaction_splits (household_id, transaction_group_id);

alter table transactions add column if not exists receipt_id bigint null references receipts(id) on delete set null;
alter table transactions add column if not exists split_group_id bigint null references transaction_groups(id) on delete set null;
alter table transactions add column if not exists split_parent_id bigint null references transactions(id) on delete set null;
alter table transactions add column if not exists split_note text null;
create index if not exists transactions_household_receipt_idx on transactions (household_id, receipt_id);
create index if not exists transactions_household_split_group_idx on transactions (household_id, split_group_id);

alter table attachments add column if not exists receipt_id bigint null references receipts(id) on delete set null;
create index if not exists attachments_receipt_idx on attachments (receipt_id);

create trigger receipts_bump_row_version before update on receipts for each row execute function hb_bump_row_version();
create trigger transaction_groups_bump_row_version before update on transaction_groups for each row execute function hb_bump_row_version();
create trigger receipts_audit after insert or update or delete on receipts for each row execute function hb_audit_trigger();
create trigger transaction_groups_audit after insert or update or delete on transaction_groups for each row execute function hb_audit_trigger();
