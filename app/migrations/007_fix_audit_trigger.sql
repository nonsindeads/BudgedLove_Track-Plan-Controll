-- Fix audit trigger to handle tables without an id column (e.g., transaction_tags)

create or replace function hb_audit_trigger()
returns trigger as $$
declare
    v_user_id int;
    v_username text;
    v_household_id int;
    v_action text := lower(TG_OP);
    v_entity_id text;
    v_data_old jsonb;
    v_data_new jsonb;
    v_payload json;
begin
    v_user_id := nullif(current_setting('hb.user_id', true), '')::int;
    v_username := nullif(current_setting('hb.username', true), '');
    v_household_id := nullif(current_setting('hb.household_id', true), '')::int;

    if (TG_OP = 'INSERT') then
        v_data_new := to_jsonb(NEW);
        v_entity_id := coalesce(v_data_new->>'id', null);
    elsif (TG_OP = 'UPDATE') then
        v_data_old := to_jsonb(OLD);
        v_data_new := to_jsonb(NEW);
        v_entity_id := coalesce(v_data_new->>'id', v_data_old->>'id', null);
    elsif (TG_OP = 'DELETE') then
        v_data_old := to_jsonb(OLD);
        v_entity_id := coalesce(v_data_old->>'id', null);
    end if;

    if (TG_TABLE_NAME = 'household_members') then
        v_entity_id := coalesce((v_data_new->>'household_id'), (v_data_old->>'household_id'), '')
            || ':' || coalesce((v_data_new->>'user_id'), (v_data_old->>'user_id'), '');
    elsif (TG_TABLE_NAME = 'transaction_tags') then
        v_entity_id := coalesce((v_data_new->>'transaction_id'), (v_data_old->>'transaction_id'), '')
            || ':' || coalesce((v_data_new->>'tag_id'), (v_data_old->>'tag_id'), '');
    end if;

    v_household_id := coalesce(
        v_household_id,
        nullif((v_data_new->>'household_id'), '')::int,
        nullif((v_data_old->>'household_id'), '')::int
    );

    insert into audit_events (event_at, household_id, user_id, username, action, table_name, entity_id, data_old, data_new)
    values (now(), v_household_id, v_user_id, v_username, v_action, TG_TABLE_NAME, v_entity_id, v_data_old, v_data_new);

    v_payload := json_build_object(
        'type', 'audit',
        'table', TG_TABLE_NAME,
        'action', v_action,
        'entity_id', v_entity_id,
        'household_id', v_household_id,
        'user_id', v_user_id,
        'username', v_username,
        'timestamp', now()
    );
    perform pg_notify('hb_audit', v_payload::text);

    if (TG_OP = 'DELETE') then
        return OLD;
    end if;
    return NEW;
end;
$$ language plpgsql;
