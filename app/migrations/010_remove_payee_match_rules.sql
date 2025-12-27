-- Remove payee match rules and related transaction column
alter table transactions drop column if exists suggested_match_rule_id;

drop table if exists payee_match_rules cascade;
