with tx_rules as (
    select
        t.id,
        t.household_id,
        t.counterparty_name,
        t.payee_id,
        t.category_id,
        coalesce(
            array_agg(tt.tag_id order by tt.tag_id) filter (where tt.tag_id is not null),
            '{}'::int[]
        ) as tag_ids,
        t.booking_date
    from transactions t
    left join transaction_tags tt on tt.transaction_id = t.id
    where t.is_reviewed = true
      and t.type in ('income', 'expense')
      and t.counterparty_name is not null
      and t.counterparty_name <> ''
    group by t.id
),
latest_rules as (
    select distinct on (household_id, counterparty_name)
        household_id,
        counterparty_name,
        payee_id,
        category_id,
        tag_ids
    from tx_rules
    where payee_id is not null
       or category_id is not null
       or cardinality(tag_ids) > 0
    order by household_id, counterparty_name, booking_date desc, id desc
)
update payee_mappings pm
   set payee_id = coalesce(latest_rules.payee_id, pm.payee_id),
       category_id = coalesce(latest_rules.category_id, pm.category_id),
       tag_ids = case
           when cardinality(latest_rules.tag_ids) > 0 then latest_rules.tag_ids
           else pm.tag_ids
       end,
       updated_at = now()
  from latest_rules
 where pm.household_id = latest_rules.household_id
   and pm.counterparty_name = latest_rules.counterparty_name;
