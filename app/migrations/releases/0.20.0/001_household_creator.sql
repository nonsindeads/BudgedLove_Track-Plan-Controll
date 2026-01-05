alter table households
  add column if not exists created_by_user_id int null references users(id) on delete set null;

update households h
   set created_by_user_id = ranked.user_id
  from (
        select household_id,
               user_id,
               row_number() over (
                 partition by household_id
                 order by case when role = 'admin' then 0 else 1 end, created_at asc, user_id asc
               ) as rn
          from household_members
       ) ranked
 where ranked.household_id = h.id
   and ranked.rn = 1
   and h.created_by_user_id is null;

create index if not exists households_creator_idx on households (created_by_user_id);
