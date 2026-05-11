alter table households
    add column if not exists salary_anchor_account_id int null,
    add column if not exists salary_anchor_category_id int null,
    add column if not exists salary_anchor_payee_id int null;

do $$
begin
    if not exists (select 1 from pg_constraint where conname = 'households_salary_anchor_account_fk') then
        alter table households
            add constraint households_salary_anchor_account_fk
            foreign key (salary_anchor_account_id) references accounts(id) on delete set null;
    end if;
    if not exists (select 1 from pg_constraint where conname = 'households_salary_anchor_category_fk') then
        alter table households
            add constraint households_salary_anchor_category_fk
            foreign key (salary_anchor_category_id) references categories(id) on delete set null;
    end if;
    if not exists (select 1 from pg_constraint where conname = 'households_salary_anchor_payee_fk') then
        alter table households
            add constraint households_salary_anchor_payee_fk
            foreign key (salary_anchor_payee_id) references payees(id) on delete set null;
    end if;
end $$;

create index if not exists households_salary_anchor_account_idx on households (salary_anchor_account_id);
create index if not exists households_salary_anchor_category_idx on households (salary_anchor_category_id);
create index if not exists households_salary_anchor_payee_idx on households (salary_anchor_payee_id);
