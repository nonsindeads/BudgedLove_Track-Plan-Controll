alter table api_tokens add column if not exists expires_at timestamptz null;
alter table api_tokens add column if not exists revoked_at timestamptz null;
