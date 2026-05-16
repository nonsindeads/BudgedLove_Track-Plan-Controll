alter table households
    add column if not exists data_residency_mode varchar(32) not null default 'server',
    add column if not exists cloud_primary_provider varchar(32) null,
    add column if not exists cloud_sync_mode varchar(32) not null default 'disabled',
    add column if not exists cloud_user_identifier varchar(255) null,
    add column if not exists cloud_remote_path varchar(512) null,
    add column if not exists cloud_session_ttl_minutes int not null default 120,
    add column if not exists cloud_require_ephemeral boolean not null default false;

do $$
begin
    if not exists (select 1 from pg_constraint where conname = 'households_data_residency_mode_chk') then
        alter table households
            add constraint households_data_residency_mode_chk
            check (data_residency_mode in ('server', 'cloud'));
    end if;
    if not exists (select 1 from pg_constraint where conname = 'households_cloud_primary_provider_chk') then
        alter table households
            add constraint households_cloud_primary_provider_chk
            check (cloud_primary_provider is null or cloud_primary_provider in ('icloud', 'nextcloud', 'gmail'));
    end if;
    if not exists (select 1 from pg_constraint where conname = 'households_cloud_sync_mode_chk') then
        alter table households
            add constraint households_cloud_sync_mode_chk
            check (cloud_sync_mode in ('disabled', 'exports_only', 'receipts_and_exports', 'sqlite_snapshots'));
    end if;
    if not exists (select 1 from pg_constraint where conname = 'households_cloud_session_ttl_minutes_chk') then
        alter table households
            add constraint households_cloud_session_ttl_minutes_chk
            check (cloud_session_ttl_minutes between 5 and 1440);
    end if;
end$$;

create index if not exists households_data_residency_mode_idx on households (data_residency_mode);
create index if not exists households_cloud_primary_provider_idx on households (cloud_primary_provider);
