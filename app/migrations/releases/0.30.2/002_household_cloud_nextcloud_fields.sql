alter table households
    add column if not exists cloud_endpoint_url varchar(1024) null,
    add column if not exists cloud_access_secret text null;
