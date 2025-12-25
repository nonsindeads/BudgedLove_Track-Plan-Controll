-- Add row_version columns for optimistic locking
alter table users add column if not exists row_version int not null default 1;
alter table households add column if not exists row_version int not null default 1;
alter table household_members add column if not exists row_version int not null default 1;
alter table accounts add column if not exists row_version int not null default 1;
alter table categories add column if not exists row_version int not null default 1;
alter table tags add column if not exists row_version int not null default 1;
alter table payees add column if not exists row_version int not null default 1;
alter table transactions add column if not exists row_version int not null default 1;
alter table transaction_splits add column if not exists row_version int not null default 1;
alter table transaction_tags add column if not exists row_version int not null default 1;
alter table recurring_rules add column if not exists row_version int not null default 1;
alter table tasks add column if not exists row_version int not null default 1;
alter table attachments add column if not exists row_version int not null default 1;
alter table recurring_executions add column if not exists row_version int not null default 1;

-- Audit log table
create table if not exists audit_events (
    id bigserial primary key,
    event_at timestamptz not null default now(),
    household_id int null references households(id) on delete set null,
    user_id int null references users(id) on delete set null,
    username text null,
    action varchar(16) not null,
    table_name varchar(64) not null,
    entity_id text null,
    data_old jsonb null,
    data_new jsonb null
);
create index if not exists audit_events_household_idx on audit_events (household_id, event_at desc);
create index if not exists audit_events_table_idx on audit_events (table_name);
create index if not exists audit_events_user_idx on audit_events (user_id);

create table if not exists chat_messages (
    id bigserial primary key,
    household_id int not null references households(id) on delete cascade,
    user_id int not null references users(id) on delete cascade,
    message text not null,
    created_at timestamptz not null default now()
);
create index if not exists chat_messages_household_idx on chat_messages (household_id, created_at desc);

create or replace function hb_bump_row_version()
returns trigger as $$
begin
    if (TG_OP = 'UPDATE') then
        NEW.row_version = OLD.row_version + 1;
    end if;
    return NEW;
end;
$$ language plpgsql;

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
        v_entity_id := coalesce(NEW.id::text, null);
    elsif (TG_OP = 'UPDATE') then
        v_data_old := to_jsonb(OLD);
        v_data_new := to_jsonb(NEW);
        v_entity_id := coalesce(NEW.id::text, OLD.id::text);
    elsif (TG_OP = 'DELETE') then
        v_data_old := to_jsonb(OLD);
        v_entity_id := coalesce(OLD.id::text, null);
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

-- Row version triggers
create trigger users_bump_row_version before update on users for each row execute function hb_bump_row_version();
create trigger households_bump_row_version before update on households for each row execute function hb_bump_row_version();
create trigger household_members_bump_row_version before update on household_members for each row execute function hb_bump_row_version();
create trigger accounts_bump_row_version before update on accounts for each row execute function hb_bump_row_version();
create trigger categories_bump_row_version before update on categories for each row execute function hb_bump_row_version();
create trigger tags_bump_row_version before update on tags for each row execute function hb_bump_row_version();
create trigger payees_bump_row_version before update on payees for each row execute function hb_bump_row_version();
create trigger transactions_bump_row_version before update on transactions for each row execute function hb_bump_row_version();
create trigger transaction_splits_bump_row_version before update on transaction_splits for each row execute function hb_bump_row_version();
create trigger transaction_tags_bump_row_version before update on transaction_tags for each row execute function hb_bump_row_version();
create trigger recurring_rules_bump_row_version before update on recurring_rules for each row execute function hb_bump_row_version();
create trigger tasks_bump_row_version before update on tasks for each row execute function hb_bump_row_version();
create trigger attachments_bump_row_version before update on attachments for each row execute function hb_bump_row_version();
create trigger recurring_executions_bump_row_version before update on recurring_executions for each row execute function hb_bump_row_version();
create trigger chat_messages_bump_row_version before update on chat_messages for each row execute function hb_bump_row_version();

-- Audit triggers
create trigger users_audit after insert or update or delete on users for each row execute function hb_audit_trigger();
create trigger households_audit after insert or update or delete on households for each row execute function hb_audit_trigger();
create trigger household_members_audit after insert or update or delete on household_members for each row execute function hb_audit_trigger();
create trigger accounts_audit after insert or update or delete on accounts for each row execute function hb_audit_trigger();
create trigger categories_audit after insert or update or delete on categories for each row execute function hb_audit_trigger();
create trigger tags_audit after insert or update or delete on tags for each row execute function hb_audit_trigger();
create trigger payees_audit after insert or update or delete on payees for each row execute function hb_audit_trigger();
create trigger transactions_audit after insert or update or delete on transactions for each row execute function hb_audit_trigger();
create trigger transaction_splits_audit after insert or update or delete on transaction_splits for each row execute function hb_audit_trigger();
create trigger transaction_tags_audit after insert or update or delete on transaction_tags for each row execute function hb_audit_trigger();
create trigger recurring_rules_audit after insert or update or delete on recurring_rules for each row execute function hb_audit_trigger();
create trigger tasks_audit after insert or update or delete on tasks for each row execute function hb_audit_trigger();
create trigger attachments_audit after insert or update or delete on attachments for each row execute function hb_audit_trigger();
create trigger recurring_executions_audit after insert or update or delete on recurring_executions for each row execute function hb_audit_trigger();
create trigger chat_messages_audit after insert or update or delete on chat_messages for each row execute function hb_audit_trigger();
