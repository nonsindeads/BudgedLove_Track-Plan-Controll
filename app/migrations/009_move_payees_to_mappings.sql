-- Move existing auto-created payees into mappings, then clear payees

insert into payee_mappings (household_id, counterparty_name)
select household_id, name
  from payees
on conflict (household_id, counterparty_name) do nothing;

delete from payees;
