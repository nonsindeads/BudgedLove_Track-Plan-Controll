-- Add optional user color for live feed/chat
alter table users add column if not exists color_hex varchar(16) null;
