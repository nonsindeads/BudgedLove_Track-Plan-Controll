create table if not exists translations (
    id serial primary key,
    translation_key varchar(255) not null,
    lang varchar(8) not null,
    value text not null,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now(),
    updated_by int null references users(id) on delete set null
);
create unique index if not exists translations_key_lang_idx on translations (translation_key, lang);

alter table users add column if not exists language varchar(8);
update users set language = coalesce(nullif(language, ''), 'de');
alter table users alter column language set not null;
