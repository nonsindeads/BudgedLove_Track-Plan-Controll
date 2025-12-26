-- Prevent duplicate planned payments for same recurring/date
create unique index if not exists planned_payments_recurring_date_uidx
    on planned_payments (recurring_payment_id, planned_date)
    where recurring_payment_id is not null;
