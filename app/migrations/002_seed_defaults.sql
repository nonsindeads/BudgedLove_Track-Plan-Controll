-- Seed global default categories and tags (household_id NULL)
insert into categories (household_id, name, type, sort_order)
values
    (null, 'Gehalt', 'income', 10),
    (null, 'Wohnen', 'expense', 20),
    (null, 'Energie', 'expense', 30),
    (null, 'Telekommunikation', 'expense', 40),
    (null, 'Mobilität', 'expense', 50),
    (null, 'Gesundheit', 'expense', 60),
    (null, 'Steuern', 'expense', 70),
    (null, 'Versicherung', 'expense', 80),
    (null, 'Arbeit', 'expense', 90),
    (null, 'Freizeit', 'expense', 100),
    (null, 'Haushalt', 'expense', 110),
    (null, 'Recht', 'expense', 120)
on conflict do nothing;

-- Tags optional; keep empty for now to allow later additions.
