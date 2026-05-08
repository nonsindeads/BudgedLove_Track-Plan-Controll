alter table payee_mappings
    add column if not exists category_id int null references categories(id) on delete set null,
    add column if not exists tag_ids int[] not null default '{}';

create index if not exists payee_mappings_category_idx on payee_mappings (category_id);

create unique index if not exists transactions_import_hash_household_uidx
    on transactions (household_id, import_hash)
    where import_hash is not null;
