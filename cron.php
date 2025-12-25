<?php
declare(strict_types=1);

require_once __DIR__ . '/app/db.php';

$pdo = hb_get_pdo();

// Basic cron runner: process active recurring_rules due now.
$pdo->beginTransaction();
$stmt = $pdo->query(
    "select * from recurring_rules
      where is_active = true and next_run_at <= now()
      for update skip locked"
);
$rules = $stmt->fetchAll();

foreach ($rules as $rule) {
    try {
        hb_execute_rule($pdo, $rule);
        $nextRun = hb_next_run_at($rule);

        $upd = $pdo->prepare(
            'update recurring_rules
                set last_run_at = now(),
                    next_run_at = :next,
                    updated_at = now()
              where id = :id'
        );
        $upd->execute([
            'next' => $nextRun->format('Y-m-d H:i:sP'),
            'id' => $rule['id'],
        ]);

        $log = $pdo->prepare('insert into recurring_executions (recurring_rule_id, note) values (:id, :note)');
        $log->execute([
            'id' => $rule['id'],
            'note' => 'Executed at ' . date('c'),
        ]);
        $pdo->commit();
        $pdo->beginTransaction();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
$pdo->commit();

function hb_execute_rule(PDO $pdo, array $rule): void
{
    $payload = json_decode($rule['payload_json'] ?? '{}', true);
    if (!is_array($payload)) {
        return;
    }

    if ($rule['kind'] === 'task') {
        $stmt = $pdo->prepare(
            'insert into tasks (household_id, title, description, due_date, amount_cents, currency_code, status, source_recurring_rule_id)
             values (:hid, :title, :description, :due_date, :amount, :currency, :status, :source)'
        );
        $stmt->execute([
            'hid' => $rule['household_id'],
            'title' => $payload['title'] ?? $rule['name'],
            'description' => $payload['description'] ?? null,
            'due_date' => $payload['due_date'] ?? date('Y-m-d'),
            'amount' => $payload['amount_cents'] ?? null,
            'currency' => $payload['currency_code'] ?? $payload['currency'] ?? 'EUR',
            'status' => 'open',
            'source' => $rule['id'],
        ]);
        return;
    }

    // default: transaction
    $stmt = $pdo->prepare(
        'insert into transactions (household_id, type, booking_date, amount_cents, currency_code, account_id, category_id, payee_id, note, transfer_from_account_id, transfer_to_account_id)
         values (:hid, :type, :booking_date, :amount, :currency, :account_id, :category_id, :payee_id, :note, :tf, :tt)'
    );
    $stmt->execute([
        'hid' => $rule['household_id'],
        'type' => $payload['type'] ?? 'expense',
        'booking_date' => $payload['booking_date'] ?? date('Y-m-d'),
        'amount' => $payload['amount_cents'] ?? 0,
        'currency' => $payload['currency_code'] ?? 'EUR',
        'account_id' => $payload['account_id'] ?? null,
        'category_id' => $payload['category_id'] ?? null,
        'payee_id' => $payload['payee_id'] ?? null,
        'note' => $payload['note'] ?? $rule['name'],
        'tf' => $payload['transfer_from_account_id'] ?? null,
        'tt' => $payload['transfer_to_account_id'] ?? null,
    ]);
}

function hb_next_run_at(array $rule): DateTimeImmutable
{
    $current = new DateTimeImmutable($rule['next_run_at'] ?? 'now');
    $interval = (int)($rule['schedule_interval'] ?? 1);
    $unit = $rule['schedule_unit'] ?? 'month';

    if ($unit === 'day') {
        return $current->modify('+' . $interval . ' days');
    }
    if ($unit === 'week') {
        $weekdays = array_filter(array_map('trim', explode(',', (string)($rule['schedule_weekdays'] ?? ''))));
        if ($weekdays) {
            $lookup = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
            $wanted = array_map(fn($w) => $lookup[strtolower($w)] ?? null, $weekdays);
            $wanted = array_filter($wanted);
            $next = $current;
            for ($i = 0; $i < 14; $i++) {
                $next = $next->modify('+1 day');
                if (in_array((int)$next->format('N'), $wanted, true)) {
                    return $next;
                }
            }
        }
        return $current->modify('+' . $interval . ' weeks');
    }
    if ($unit === 'month') {
        $monthday = $rule['schedule_monthday'] ?? null;
        $next = $current->modify('+' . $interval . ' months');
        if ($monthday) {
            $next = $next->setDate((int)$next->format('Y'), (int)$next->format('m'), (int)$monthday);
        }
        return $next;
    }
    if ($unit === 'year') {
        return $current->modify('+' . $interval . ' years');
    }

    return $current->modify('+1 month');
}
