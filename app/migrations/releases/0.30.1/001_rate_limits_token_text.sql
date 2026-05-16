alter table rate_limits
    alter column token_id type text using token_id::text;
