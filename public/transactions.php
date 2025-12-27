<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../app/domain.php';

hb_require_login();
$pdo = hb_get_pdo();
$household = hb_require_household($pdo);
$currentHousehold = $household;
$currentUser = hb_current_user($pdo);
$pageTitle = 'Transaktionen';
$activeNav = 'transactions';
$breadcrumbs = [
    ['label' => 'Transaktionen', 'href' => '/transactions.php'],
];
$userId = hb_current_user_id();

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$msg = $_GET['msg'] ?? null;
$error = null;
$info = null;
$conflict = null;

// Load options
$accountsStmt = $pdo->prepare('select * from accounts where household_id = :hid and is_archived = false order by name asc');
$accountsStmt->execute(['hid' => $household['id']]);
$accounts = $accountsStmt->fetchAll();

$catStmt = $pdo->prepare('select * from categories where household_id = :hid and is_active = true order by type asc, sort_order asc, name asc');
$catStmt->execute(['hid' => $household['id']]);
$categories = $catStmt->fetchAll();

$tagStmt = $pdo->prepare('select * from tags where household_id = :hid and is_active = true order by name asc');
$tagStmt->execute(['hid' => $household['id']]);
$tags = $tagStmt->fetchAll();

$payeeStmt = $pdo->prepare('select * from payees where household_id = :hid order by name asc');
$payeeStmt->execute(['hid' => $household['id']]);
$payees = $payeeStmt->fetchAll();

function hb_find_by_id(array $items, int $id): ?array
{
    foreach ($items as $item) {
        if ((int)$item['id'] === $id) {
            return $item;
        }
    }
    return null;
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $txId = (int)($_POST['id'] ?? 0);
    $own = $pdo->prepare('select id from transactions where id = :id and household_id = :hid');
    $own->execute(['id' => $txId, 'hid' => $household['id']]);
    if (!$own->fetch()) {
        $error = 'Transaktion nicht gefunden.';
    } else {
        $del = $pdo->prepare('delete from transactions where id = :id and household_id = :hid');
        $del->execute(['id' => $txId, 'hid' => $household['id']]);
        header('Location: /transactions.php?msg=deleted');
        exit;
    }
}

if ($action === 'create_recurring' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $txId = (int)($_POST['transaction_id'] ?? 0);
    $name = trim((string)($_POST['recurring_name'] ?? ''));
    $intervalUnit = (string)($_POST['recurring_interval_unit'] ?? 'month');
    $intervalValue = (int)($_POST['recurring_interval_value'] ?? 1);
    $startDate = (string)($_POST['recurring_start_date'] ?? '');
    $endDate = (string)($_POST['recurring_end_date'] ?? '');
    $priority = (int)($_POST['recurring_priority'] ?? 3);
    $isOptional = isset($_POST['recurring_is_optional']);
    $direction = (string)($_POST['recurring_direction'] ?? '');
    $amountOverride = hb_parse_cents((string)($_POST['recurring_amount'] ?? ''));
    $amountMode = (string)($_POST['recurring_amount_mode'] ?? 'fixed');
    $toleranceAmount = hb_parse_cents((string)($_POST['recurring_tolerance_amount'] ?? ''));
    $tolerancePctRaw = trim((string)($_POST['recurring_tolerance_pct'] ?? ''));
    $tolerancePct = $tolerancePctRaw !== '' ? (float)str_replace(',', '.', $tolerancePctRaw) : null;
    $minAmount = hb_parse_cents((string)($_POST['recurring_min_amount'] ?? ''));
    $maxAmount = hb_parse_cents((string)($_POST['recurring_max_amount'] ?? ''));
    $accountOverride = $_POST['recurring_account_id'] !== '' ? (int)($_POST['recurring_account_id'] ?? 0) : null;
    $categoryOverride = $_POST['recurring_category_id'] !== '' ? (int)($_POST['recurring_category_id'] ?? 0) : null;
    $payeeOverride = $_POST['recurring_payee_id'] !== '' ? (int)($_POST['recurring_payee_id'] ?? 0) : null;
    $noteOverride = trim((string)($_POST['recurring_note'] ?? ''));

    if ($name === '') {
        $error = 'Name der wiederkehrenden Zahlung fehlt.';
    } elseif (!in_array($intervalUnit, ['day', 'week', 'month', 'year'], true)) {
        $error = 'Ungültiges Intervall.';
    } elseif ($intervalValue < 1) {
        $error = 'Intervallwert muss positiv sein.';
    } elseif ($startDate === '') {
        $error = 'Startdatum ist erforderlich.';
    } elseif (!in_array($amountMode, ['fixed', 'tolerance', 'range'], true)) {
        $error = 'Ungültige Betragslogik.';
    } elseif ($amountMode === 'tolerance' && $toleranceAmount === null && $tolerancePct === null) {
        $error = 'Toleranz ist erforderlich.';
    } elseif ($amountMode === 'range' && ($minAmount === null || $maxAmount === null)) {
        $error = 'Min- und Maxbetrag sind erforderlich.';
    }

    $txStmt = $pdo->prepare('select * from transactions where id = :id and household_id = :hid');
    $txStmt->execute(['id' => $txId, 'hid' => $household['id']]);
    $txRow = $txStmt->fetch();
    if ($error === null && !$txRow) {
        $error = 'Transaktion nicht gefunden.';
    }

    if ($error === null) {
        $direction = $direction !== '' ? $direction : (string)$txRow['type'];
        if (!in_array($direction, ['income', 'expense'], true)) {
            $error = 'Wiederkehrend ist nur für Einnahme/Ausgabe möglich.';
        }
    }

    $amountCents = $amountOverride !== null ? $amountOverride : (int)$txRow['amount_cents'];
    if ($error === null && $amountCents <= 0) {
        $error = 'Betrag ungültig.';
    }

    if ($error === null) {
        $startDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        if ($startDateObj && hb_is_period_closed($pdo, $household['id'], $startDateObj)) {
            $error = 'Der Monat ist bereits abgeschlossen. Änderungen sind gesperrt.';
        }
        if ($endDate !== '') {
            $endDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
            if (!$endDateObj) {
                $error = 'Enddatum ist ungültig.';
            } elseif ($startDateObj && $endDateObj < $startDateObj) {
                $error = 'Enddatum muss nach dem Startdatum liegen.';
            }
        }
    }

    $accountId = $accountOverride ?? $txRow['account_id'];
    $categoryId = $categoryOverride ?? $txRow['category_id'];
    $payeeId = $payeeOverride ?? $txRow['payee_id'];
    $note = $noteOverride !== '' ? $noteOverride : ($txRow['note'] ?? null);

    if ($accountId && !hb_find_by_id($accounts, (int)$accountId)) {
        $error = 'Konto gehört nicht zum Haushalt.';
    }
    if ($categoryId && !hb_find_by_id($categories, (int)$categoryId)) {
        $error = 'Kategorie gehört nicht zum Haushalt.';
    }
    if ($payeeId && !hb_find_by_id($payees, (int)$payeeId)) {
        $error = 'Payee gehört nicht zum Haushalt.';
    }

    if ($error === null) {
        $insert = $pdo->prepare(
            'insert into recurring_payments
                (household_id, name, direction, amount_cents, interval_unit, interval_value, start_date, end_date,
                 priority, is_optional, account_id, category_id, payee_id, note, is_active,
                 amount_mode, tolerance_cents, tolerance_pct, min_amount_cents, max_amount_cents)
             values
                (:hid, :name, :direction, :amount, :unit, :ival, :start_date, :end_date,
                 :priority, :is_optional, :account_id, :category_id, :payee_id, :note, true,
                 :amount_mode, :tolerance_cents, :tolerance_pct, :min_amount_cents, :max_amount_cents)'
        );
        $insert->execute([
            'hid' => $household['id'],
            'name' => $name,
            'direction' => $direction,
            'amount' => $amountCents,
            'unit' => $intervalUnit,
            'ival' => $intervalValue,
            'start_date' => $startDate,
            'end_date' => $endDate !== '' ? $endDate : null,
            'priority' => $priority,
            'is_optional' => $isOptional ? 1 : 0,
            'account_id' => $accountId,
            'category_id' => $categoryId,
            'payee_id' => $payeeId,
            'note' => $note !== '' ? $note : null,
            'amount_mode' => $amountMode,
            'tolerance_cents' => $amountMode === 'tolerance' ? $toleranceAmount : null,
            'tolerance_pct' => $amountMode === 'tolerance' ? $tolerancePct : null,
            'min_amount_cents' => $amountMode === 'range' ? $minAmount : null,
            'max_amount_cents' => $amountMode === 'range' ? $maxAmount : null,
        ]);

        $startDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        if ($startDateObj) {
            [$periodStart, $periodEnd] = hb_household_period_bounds($household, $startDateObj);
            hb_ensure_month_plan($pdo, $household, $periodStart, $periodEnd);
        }

        header('Location: /transactions.php?msg=recurring_saved');
        exit;
    }
}

if (in_array($action, ['store', 'update'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'] ?? 'expense';
    $bookingDate = $_POST['booking_date'] ?? '';
    $amountCents = hb_parse_cents((string)($_POST['amount'] ?? ''));
    $accountId = $_POST['account_id'] !== '' ? (int)$_POST['account_id'] : null;
    $categoryId = $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : null;
    $payeeId = $_POST['payee_id'] !== '' ? (int)$_POST['payee_id'] : null;
    $note = trim((string)($_POST['note'] ?? ''));
    $transferFrom = $_POST['transfer_from_account_id'] !== '' ? (int)$_POST['transfer_from_account_id'] : null;
    $transferTo = $_POST['transfer_to_account_id'] !== '' ? (int)$_POST['transfer_to_account_id'] : null;
    $tagIds = array_filter(array_map('intval', $_POST['tag_ids'] ?? []));
    $splitCats = $_POST['split_category_id'] ?? [];
    $splitAmounts = $_POST['split_amount'] ?? [];
    $id = (int)($_POST['id'] ?? 0);
    $rowVersion = (int)($_POST['row_version'] ?? 0);

    if (!in_array($type, ['income', 'expense', 'transfer'], true)) {
        $error = 'Ungültiger Typ.';
    } elseif (!$bookingDate) {
        $error = 'Buchungsdatum fehlt.';
    } elseif ($amountCents === null || $amountCents < 0) {
        $error = 'Betrag ungültig.';
    }

    if ($error === null) {
        $bookingDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $bookingDate);
        if ($bookingDateObj && hb_is_period_closed($pdo, $household['id'], $bookingDateObj)) {
            $error = 'Der Monat ist bereits abgeschlossen. Änderungen sind gesperrt.';
        }
    }

    if ($accountId && !hb_find_by_id($accounts, $accountId)) {
        $error = 'Konto gehört nicht zum Haushalt.';
    }
    if ($transferFrom && !hb_find_by_id($accounts, $transferFrom)) {
        $error = 'Transfer-Quellkonto ungültig.';
    }
    if ($transferTo && !hb_find_by_id($accounts, $transferTo)) {
        $error = 'Transfer-Zielkonto ungültig.';
    }
    if ($categoryId && !hb_find_by_id($categories, $categoryId)) {
        $error = 'Kategorie gehört nicht zum Haushalt.';
    }
    if ($payeeId && !hb_find_by_id($payees, $payeeId)) {
        $error = 'Payee gehört nicht zum Haushalt.';
    }
    foreach ($tagIds as $tid) {
        if (!hb_find_by_id($tags, $tid)) {
            $error = 'Tag gehört nicht zum Haushalt.';
            break;
        }
    }

    if ($type === 'transfer') {
        if (!$transferFrom || !$transferTo) {
            $error = 'Von- und Zielkonto erforderlich.';
        } elseif ($transferFrom === $transferTo) {
            $error = 'Transfer benötigt zwei unterschiedliche Konten.';
        }
    } else {
        if (!$accountId) {
            $error = 'Konto ist erforderlich.';
        }
        if (!$categoryId) {
            // allow splits fallback
            $hasSplits = false;
            foreach ($splitAmounts as $sa) {
                if (hb_parse_cents((string)$sa) > 0) {
                    $hasSplits = true;
                    break;
                }
            }
            if (!$hasSplits) {
                $error = 'Kategorie oder Splits erforderlich.';
            }
        }
    }

    // Validate splits sum
    $splits = [];
    $splitSum = 0;
    foreach ($splitCats as $idx => $catIdRaw) {
        $catId = (int)$catIdRaw;
        $cents = hb_parse_cents((string)($splitAmounts[$idx] ?? ''));
        if ($catId && $cents !== null && $cents > 0) {
            if (!hb_find_by_id($categories, $catId)) {
                $error = 'Split-Kategorie gehört nicht zum Haushalt.';
                break;
            }
            $splits[] = ['category_id' => $catId, 'amount_cents' => $cents];
            $splitSum += $cents;
        }
    }
    if ($splits && $splitSum !== $amountCents) {
        $error = 'Split-Summe muss dem Betrag entsprechen.';
    }

    if ($error === null) {
        if ($action === 'store') {
            $stmt = $pdo->prepare(
                'insert into transactions (household_id, type, booking_date, amount_cents, currency_code, account_id, category_id, payee_id, note, transfer_from_account_id, transfer_to_account_id)
                 values (:hid, :type, :booking_date, :amount, :cur, :account_id, :category_id, :payee_id, :note, :tf, :tt)
                 returning id'
            );
            $stmt->execute([
                'hid' => $household['id'],
                'type' => $type,
                'booking_date' => $bookingDate,
                'amount' => $amountCents,
                'cur' => $household['currency_code'],
                'account_id' => $type === 'transfer' ? null : $accountId,
                'category_id' => $type === 'transfer' ? null : $categoryId,
                'payee_id' => $type === 'transfer' ? null : $payeeId,
                'note' => $note !== '' ? $note : null,
                'tf' => $type === 'transfer' ? $transferFrom : null,
                'tt' => $type === 'transfer' ? $transferTo : null,
            ]);
            $transactionId = (int)$stmt->fetchColumn();
        } else {
            $transactionId = $id;
            $own = $pdo->prepare('select id from transactions where id = :id and household_id = :hid');
            $own->execute(['id' => $transactionId, 'hid' => $household['id']]);
            if (!$own->fetch()) {
                $error = 'Transaktion nicht gefunden.';
            } else {
                $stmt = $pdo->prepare(
                    'update transactions
                        set type = :type,
                            booking_date = :booking_date,
                            amount_cents = :amount,
                            currency_code = :cur,
                            account_id = :account_id,
                            category_id = :category_id,
                            payee_id = :payee_id,
                            note = :note,
                            transfer_from_account_id = :tf,
                            transfer_to_account_id = :tt,
                            updated_at = now()
                      where id = :id and household_id = :hid and row_version = :row_version'
                );
                $stmt->execute([
                    'type' => $type,
                    'booking_date' => $bookingDate,
                    'amount' => $amountCents,
                    'cur' => $household['currency_code'],
                    'account_id' => $type === 'transfer' ? null : $accountId,
                    'category_id' => $type === 'transfer' ? null : $categoryId,
                    'payee_id' => $type === 'transfer' ? null : $payeeId,
                    'note' => $note !== '' ? $note : null,
                    'tf' => $type === 'transfer' ? $transferFrom : null,
                    'tt' => $type === 'transfer' ? $transferTo : null,
                    'id' => $transactionId,
                    'hid' => $household['id'],
                    'row_version' => $rowVersion,
                ]);
                if ($stmt->rowCount() === 0) {
                    $fresh = $pdo->prepare('select * from transactions where id = :id and household_id = :hid');
                    $fresh->execute(['id' => $transactionId, 'hid' => $household['id']]);
                    $current = $fresh->fetch() ?: [];
                    $conflictRows = hb_build_conflict_rows(
                        [
                            'type' => 'Typ',
                            'booking_date' => 'Datum',
                            'amount_cents' => 'Betrag',
                            'account_id' => 'Konto',
                            'category_id' => 'Kategorie',
                            'payee_id' => 'Payee',
                            'note' => 'Notiz',
                            'transfer_from_account_id' => 'Transfer Von',
                            'transfer_to_account_id' => 'Transfer Zu',
                        ],
                        $current,
                        [
                            'type' => $type,
                            'booking_date' => $bookingDate,
                            'amount_cents' => (string)$amountCents,
                            'account_id' => (string)($accountId ?? ''),
                            'category_id' => (string)($categoryId ?? ''),
                            'payee_id' => (string)($payeeId ?? ''),
                            'note' => $note,
                            'transfer_from_account_id' => (string)($transferFrom ?? ''),
                            'transfer_to_account_id' => (string)($transferTo ?? ''),
                        ]
                    );
                    $conflict = hb_render_conflict_table($conflictRows);
                    $transaction = array_merge($current, [
                        'type' => $type,
                        'booking_date' => $bookingDate,
                        'amount_cents' => $amountCents,
                        'account_id' => $accountId,
                        'category_id' => $categoryId,
                        'payee_id' => $payeeId,
                        'note' => $note,
                        'transfer_from_account_id' => $transferFrom,
                        'transfer_to_account_id' => $transferTo,
                        'row_version' => $current['row_version'] ?? 0,
                    ]);
                    $transactionSplits = $splits;
                    $transactionTags = array_map(fn($id) => ['tag_id' => $id], $tagIds);
                    $action = 'edit';
                }
            }
        }

        if ($error === null && empty($conflict)) {
            // sync splits
            $pdo->prepare('delete from transaction_splits where transaction_id = :id')->execute(['id' => $transactionId]);
            foreach ($splits as $split) {
                $ins = $pdo->prepare(
                    'insert into transaction_splits (transaction_id, category_id, amount_cents, note)
                     values (:tid, :cid, :amount, null)'
                );
                $ins->execute([
                    'tid' => $transactionId,
                    'cid' => $split['category_id'],
                    'amount' => $split['amount_cents'],
                ]);
            }

            // sync tags
            $pdo->prepare('delete from transaction_tags where transaction_id = :id')->execute(['id' => $transactionId]);
            foreach ($tagIds as $tagId) {
                $insTag = $pdo->prepare('insert into transaction_tags (transaction_id, tag_id) values (:tid, :tag)');
                $insTag->execute(['tid' => $transactionId, 'tag' => $tagId]);
            }

            header('Location: /transactions.php?msg=saved');
            exit;
        }
    }
}

$attachmentsForTx = [];
$transaction = null;
$transactionSplits = [];
$transactionTags = [];

if ($action === 'upload_attachment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $txId = (int)($_POST['transaction_id'] ?? 0);
    $txCheck = $pdo->prepare('select id, household_id from transactions where id = :id and household_id = :hid');
    $txCheck->execute(['id' => $txId, 'hid' => $household['id']]);
    $txRow = $txCheck->fetch();
    if (!$txRow) {
        $error = 'Transaktion nicht gefunden.';
    } elseif (!isset($_FILES['attachment']) || $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Upload fehlgeschlagen.';
    } else {
        $file = $_FILES['attachment'];
        if ($file['size'] > 5 * 1024 * 1024) {
            $error = 'Datei zu groß (max. 5MB).';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
            $original = basename($file['name']);
            $ext = pathinfo($original, PATHINFO_EXTENSION);
            $stored = bin2hex(random_bytes(8)) . ($ext ? '.' . preg_replace('/[^A-Za-z0-9.-]/', '', $ext) : '');
            $dir = hb_ensure_upload_dir((int)$household['id']);
            $target = $dir . '/' . $stored;
            if (!move_uploaded_file($file['tmp_name'], $target)) {
                $error = 'Datei konnte nicht gespeichert werden.';
            } else {
                $relPath = $household['id'] . '/' . $stored;
                $ins = $pdo->prepare(
                    'insert into attachments (household_id, transaction_id, original_filename, stored_filename, mime_type, size_bytes, storage_path)
                     values (:hid, :tx, :orig, :stored, :mime, :size, :path)'
                );
                $ins->execute([
                    'hid' => $household['id'],
                    'tx' => $txId,
                    'orig' => $original,
                    'stored' => $stored,
                    'mime' => $mime,
                    'size' => (int)$file['size'],
                    'path' => $relPath,
                ]);
                header('Location: /transactions.php?action=show&id=' . $txId . '&msg=attachment_saved');
                exit;
            }
        }
    }
}

if (($action === 'edit' || $action === 'show') && empty($conflict)) {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare(
        'select t.*, p.name as payee_name, c.name as category_name, a.name as account_name,
                sp.name as suggested_plan_name, sp.planned_date as suggested_plan_date,
                pp.name as planned_name, pp.planned_date as planned_date
           from transactions t
           left join payees p on p.id = t.payee_id
           left join categories c on c.id = t.category_id
           left join accounts a on a.id = t.account_id
           left join planned_payments sp on sp.id = t.suggested_planned_payment_id
           left join planned_payments pp on pp.id = t.planned_payment_id
          where t.id = :id and t.household_id = :hid'
    );
    $stmt->execute(['id' => $id, 'hid' => $household['id']]);
    $transaction = $stmt->fetch();
    if ($transaction) {
        $splitStmt = $pdo->prepare(
            'select ts.*, c.name as category_name from transaction_splits ts
             left join categories c on c.id = ts.category_id
            where ts.transaction_id = :id'
        );
        $splitStmt->execute(['id' => $transaction['id']]);
        $transactionSplits = $splitStmt->fetchAll();

        $tagStmt = $pdo->prepare(
            'select tt.tag_id, tg.name from transaction_tags tt
             join tags tg on tg.id = tt.tag_id
            where tt.transaction_id = :id'
        );
        $tagStmt->execute(['id' => $transaction['id']]);
        $transactionTags = $tagStmt->fetchAll();

        $attStmt = $pdo->prepare('select * from attachments where transaction_id = :id order by created_at desc');
        $attStmt->execute(['id' => $transaction['id']]);
        $attachmentsForTx = $attStmt->fetchAll();
    } else {
        $error = 'Transaktion nicht gefunden.';
        $action = 'list';
    }
}

// Filters
$filters = [
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'account_id' => $_GET['account_id'] ?? '',
    'category_id' => $_GET['category_id'] ?? '',
    'type' => $_GET['type'] ?? '',
    'text' => $_GET['text'] ?? '',
];

$where = ['t.household_id = :hid', 't.is_reviewed = true'];
$params = ['hid' => $household['id']];

if ($filters['date_from'] !== '') {
    $where[] = 't.booking_date >= :date_from';
    $params['date_from'] = $filters['date_from'];
}
if ($filters['date_to'] !== '') {
    $where[] = 't.booking_date <= :date_to';
    $params['date_to'] = $filters['date_to'];
}
if ($filters['account_id'] !== '') {
    $where[] = '(t.account_id = :account_id or t.transfer_from_account_id = :account_id or t.transfer_to_account_id = :account_id)';
    $params['account_id'] = (int)$filters['account_id'];
}
if ($filters['category_id'] !== '') {
    $where[] = 't.category_id = :category_id';
    $params['category_id'] = (int)$filters['category_id'];
}
if ($filters['type'] !== '') {
    $where[] = 't.type = :type';
    $params['type'] = $filters['type'];
}
if ($filters['text'] !== '') {
    $where[] = '(t.note ilike :text or p.name ilike :text or c.name ilike :text or a.name ilike :text)';
    $params['text'] = '%' . $filters['text'] . '%';
}

$whereSql = $where ? 'where ' . implode(' and ', $where) : '';
$listSql = <<<SQL
    select t.*, a.name as account_name, c.name as category_name, p.name as payee_name,
           sp.name as suggested_plan_name, sp.planned_date as suggested_plan_date,
           pp.name as planned_name, pp.planned_date as planned_date
      from transactions t
      left join accounts a on a.id = t.account_id
      left join categories c on c.id = t.category_id
      left join payees p on p.id = t.payee_id
      left join planned_payments sp on sp.id = t.suggested_planned_payment_id
      left join planned_payments pp on pp.id = t.planned_payment_id
      {$whereSql}
     order by t.booking_date desc, t.id desc
SQL;

$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);
$transactions = $listStmt->fetchAll();

ob_start();
?>
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h4 mb-0">Transaktionen</h1>
      <div class="text-muted small">Haushalt: <?= htmlspecialchars($household['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <a class="btn btn-sm btn-outline-secondary" href="/accounts.php">Konten</a>
      <a class="btn btn-sm btn-outline-secondary" href="/categories.php">Kategorien</a>
      <a class="btn btn-sm btn-outline-secondary" href="/tags.php">Tags</a>
      <a class="btn btn-sm btn-outline-secondary" href="/payees.php">Empfänger</a>
    </div>
  </div>

  <?php if ($msg === 'saved'): ?>
    <div class="alert alert-success">Transaktion gespeichert.</div>
  <?php elseif ($msg === 'deleted'): ?>
    <div class="alert alert-success">Transaktion gelöscht.</div>
  <?php elseif ($msg === 'recurring_saved'): ?>
    <div class="alert alert-success">Wiederkehrende Zahlung erstellt.</div>
  <?php endif; ?>
  <?php if ($msg === 'attachment_saved'): ?>
    <div class="alert alert-success">Anhang gespeichert.</div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-12">
      <div class="hb-whitebox mb-3">
        <div class="hb-whitebox-body">
          <h2 class="h6">Filter</h2>
          <form class="row g-2" method="get" action="/transactions.php">
            <div class="col-md-3">
              <label class="form-label small">Von</label>
              <input type="date" class="form-control form-control-sm" name="date_from" value="<?= htmlspecialchars($filters['date_from'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label small">Bis</label>
              <input type="date" class="form-control form-control-sm" name="date_to" value="<?= htmlspecialchars($filters['date_to'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label small">Konto</label>
              <select class="form-select form-select-sm" name="account_id">
                <option value="">Alle</option>
                <?php foreach ($accounts as $acc): ?>
                  <option value="<?= (int)$acc['id'] ?>" <?= $filters['account_id'] == $acc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($acc['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label small">Kategorie</label>
              <select class="form-select form-select-sm" name="category_id">
                <option value="">Alle</option>
                <?php foreach ($categories as $cat): ?>
                  <option value="<?= (int)$cat['id'] ?>" <?= $filters['category_id'] == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label small">Typ</label>
              <select class="form-select form-select-sm" name="type">
                <option value="">Alle</option>
                <?php foreach (['income', 'expense', 'transfer'] as $t): ?>
                  <option value="<?= $t ?>" <?= $filters['type'] === $t ? 'selected' : '' ?>><?= $t ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small">Text</label>
              <input type="text" class="form-control form-control-sm" name="text" placeholder="Suche" value="<?= htmlspecialchars($filters['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
            <div class="col-md-2 align-self-end">
              <button class="btn btn-sm btn-outline-primary" type="submit">Filtern</button>
            </div>
          </form>
        </div>
      </div>

        <div class="hb-whitebox">
          <div class="hb-whitebox-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h2 class="h6 mb-0">Letzte 200</h2>
            <a class="btn btn-sm btn-primary" href="/transactions.php?action=new">Neue Transaktion</a>
          </div>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>Datum</th>
                  <th>Typ</th>
                  <th>Betrag</th>
                  <th>Konto</th>
                  <th>Kategorie</th>
                  <th>Payee</th>
                  <th class="text-end">Aktionen</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($transactions as $tx): ?>
                  <tr>
                    <td><?= htmlspecialchars($tx['booking_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($tx['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= number_format($tx['amount_cents'] / 100, 2, ',', '.') ?> €</td>
                    <td><?= htmlspecialchars($tx['account_name'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td>
                      <?= htmlspecialchars($tx['category_name'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      <?php if (empty($tx['planned_payment_id']) && !empty($tx['suggested_planned_payment_id']) && !empty($tx['suggested_plan_name'])): ?>
                        <div class="small text-warning">Vorschlag: <?= htmlspecialchars($tx['suggested_plan_date'] . ' · ' . $tx['suggested_plan_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                      <?php elseif (!empty($tx['planned_payment_id']) && !empty($tx['planned_name'])): ?>
                        <div class="small text-muted">Plan: <?= htmlspecialchars($tx['planned_date'] . ' · ' . $tx['planned_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                      <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($tx['payee_name'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td class="text-end">
                      <div class="d-flex justify-content-end gap-1">
                        <a class="btn btn-sm btn-outline-secondary" href="/transactions.php?action=show&id=<?= (int)$tx['id'] ?>">Details</a>
                        <a class="btn btn-sm btn-outline-primary" href="/transactions.php?action=edit&id=<?= (int)$tx['id'] ?>">Bearbeiten</a>
                        <form method="post" action="/transactions.php" data-confirm="Transaktion wirklich löschen?">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="id" value="<?= (int)$tx['id'] ?>">
                          <button type="submit" class="btn btn-sm btn-outline-danger">Löschen</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$transactions): ?>
                  <tr><td colspan="7" class="text-muted">Keine Transaktionen gefunden.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-4 mt-1">
    <div class="col-12">
      </div>
    </div>

  <?php if (in_array($action, ['new', 'edit', 'show'], true)): ?>
    <?php ob_start(); ?>
          <?php if (!empty($conflict)): ?>
            <?= $conflict ?>
          <?php endif; ?>
          <?php
          $isEdit = $action === 'edit' && $transaction;
          $targetAction = $isEdit ? 'update' : 'store';
          ?>
          <h2 class="h6 mb-3"><?= $isEdit ? 'Transaktion bearbeiten' : 'Neue Transaktion' ?></h2>
          <?php if ($action === 'show' && $transaction): ?>
            <p><strong>Typ:</strong> <?= htmlspecialchars($transaction['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <p><strong>Betrag:</strong> <?= number_format($transaction['amount_cents'] / 100, 2, ',', '.') ?> €</p>
            <p><strong>Konto:</strong> <?= htmlspecialchars($transaction['account_name'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <p><strong>Kategorie:</strong> <?= htmlspecialchars($transaction['category_name'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php if (empty($transaction['planned_payment_id']) && !empty($transaction['suggested_planned_payment_id']) && !empty($transaction['suggested_plan_name'])): ?>
              <p><strong>Vorschlag:</strong> <?= htmlspecialchars($transaction['suggested_plan_date'] . ' · ' . $transaction['suggested_plan_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php elseif (!empty($transaction['planned_payment_id']) && !empty($transaction['planned_name'])): ?>
              <p><strong>Plan:</strong> <?= htmlspecialchars($transaction['planned_date'] . ' · ' . $transaction['planned_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php endif; ?>
            <p><strong>Payee:</strong> <?= htmlspecialchars($transaction['payee_name'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <p><strong>Notiz:</strong> <?= nl2br(htmlspecialchars($transaction['note'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>
            <?php if ($transactionSplits): ?>
              <p class="mb-1"><strong>Splits:</strong></p>
              <ul class="mb-2">
                <?php foreach ($transactionSplits as $sp): ?>
                  <li><?= htmlspecialchars($sp['category_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>: <?= number_format($sp['amount_cents'] / 100, 2, ',', '.') ?> €</li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <?php if ($transactionTags): ?>
              <p class="mb-1"><strong>Tags:</strong></p>
              <ul class="mb-2">
                <?php foreach ($transactionTags as $tt): ?>
                  <li><?= htmlspecialchars($tt['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <?php if ($attachmentsForTx): ?>
              <p class="mb-1"><strong>Anhänge:</strong></p>
              <ul class="mb-2">
                <?php foreach ($attachmentsForTx as $att): ?>
                  <li>
                    <a href="/attachments.php?action=download&id=<?= (int)$att['id'] ?>">
                      <?= htmlspecialchars($att['original_filename'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </a>
                    <span class="text-muted small">(<?= number_format($att['size_bytes'] / 1024, 1, ',', '.') ?> KB)</span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <div class="border rounded p-3 bg-light">
              <form method="post" action="/transactions.php?action=upload_attachment" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_attachment">
                <input type="hidden" name="transaction_id" value="<?= (int)$transaction['id'] ?>">
                <div class="mb-2">
                  <label class="form-label">Anhang hochladen (max 5MB)</label>
                  <input type="file" class="form-control" name="attachment" required>
                </div>
                <button class="btn btn-sm btn-outline-primary" type="submit">Upload</button>
              </form>
            </div>
            <a class="btn btn-sm btn-outline-secondary" href="/transactions.php?action=edit&id=<?= (int)$transaction['id'] ?>">Bearbeiten</a>
            <form method="post" action="/transactions.php" class="d-inline" data-confirm="Transaktion wirklich löschen?">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$transaction['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger">Löschen</button>
            </form>
          <?php else: ?>
            <form method="post" action="/transactions.php" id="transaction-form">
              <input type="hidden" name="action" value="<?= $targetAction ?>">
              <?php if ($isEdit): ?>
                <input type="hidden" name="id" value="<?= (int)$transaction['id'] ?>">
                <input type="hidden" name="row_version" value="<?= (int)($transaction['row_version'] ?? 0) ?>">
              <?php endif; ?>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">
                    Typ
                    <span class="text-muted" data-bs-toggle="tooltip" title="Einnahme/Ausgabe wirken auf das Konto, Transfer verschiebt zwischen Konten. Betrag wird intern positiv gespeichert.">ℹ️</span>
                  </label>
                  <select class="form-select" name="type">
                    <?php foreach (['income', 'expense', 'transfer'] as $t): ?>
                      <option value="<?= $t ?>" <?= ($transaction['type'] ?? '') === $t ? 'selected' : '' ?>><?= $t ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Datum</label>
                  <input type="date" class="form-control" name="booking_date" required value="<?= htmlspecialchars($transaction['booking_date'] ?? date('Y-m-d'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                </div>
              </div>
              <div class="mt-3">
                <label class="form-label">
                  Betrag
                  <span class="text-muted" data-bs-toggle="tooltip" title="Bitte positiv eingeben; Richtung ergibt sich aus dem Typ. Intern als Cent gespeichert.">ℹ️</span>
                </label>
                <input type="text" class="form-control" name="amount" required value="<?= isset($transaction['amount_cents']) ? number_format($transaction['amount_cents'] / 100, 2, ',', '.') : '' ?>" placeholder="z.B. 12,34">
                <div class="form-text">Betrag wird intern positiv gespeichert; Typ steuert Richtung.</div>
              </div>
              <div class="mt-3">
                <label class="form-label">
                  Konto
                  <span class="text-muted" data-bs-toggle="tooltip" title="Pflicht bei Einnahme/Ausgabe. Für Transfer leer lassen und stattdessen Transfer-Konten unten nutzen.">ℹ️</span>
                </label>
                <select class="form-select" name="account_id">
                  <option value="">--</option>
                  <?php foreach ($accounts as $acc): ?>
                    <option value="<?= (int)$acc['id'] ?>" <?= ($transaction['account_id'] ?? null) == $acc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($acc['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mt-3">
                <label class="form-label d-flex justify-content-between align-items-center">
                  <span>
                    Kategorie
                    <span class="text-muted" data-bs-toggle="tooltip" title="Kann leer bleiben, wenn Splits genutzt werden.">ℹ️</span>
                  </span>
                  <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-outline-secondary py-0 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#tx-splits">Split</button>
                    <button class="btn btn-sm btn-outline-secondary py-0 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#category-inline">+ Neu</button>
                  </div>
                </label>
                <?php
                $categorySelectorId = 'category-transaction';
                $categorySelectorName = 'category_id';
                $categorySelectorCategories = $categories;
                $categorySelectorSelected = $transaction['category_id'] ?? null;
                $categorySelectorPlaceholder = 'Kategorie suchen...';
                $categoryModalTarget = '#categoryModal';
                $categorySelectorShowAdd = false;
                require __DIR__ . '/../templates/partials/category_selector.php';
                ?>
                <?php if (empty($transaction['planned_payment_id']) && !empty($transaction['suggested_planned_payment_id']) && !empty($transaction['suggested_plan_name'])): ?>
                  <div class="small text-warning mt-1">Vorschlag: <?= htmlspecialchars($transaction['suggested_plan_date'] . ' · ' . $transaction['suggested_plan_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <?php endif; ?>
                <div class="collapse mt-2" id="category-inline">
                  <div class="border rounded-3 p-2 bg-body-tertiary hb-inline-category">
                    <div class="row g-2 align-items-end">
                      <div class="col-md-7">
                        <label class="form-label small" for="category-inline-name">Name</label>
                        <input type="text" class="form-control form-control-sm" id="category-inline-name" data-category-field="name" required>
                        <div class="invalid-feedback">Name ist erforderlich.</div>
                      </div>
                      <div class="col-md-5">
                        <label class="form-label small" for="category-inline-type">Typ</label>
                        <select class="form-select form-select-sm" id="category-inline-type" data-category-field="type">
                          <option value="expense">Ausgabe</option>
                          <option value="income">Einnahme</option>
                        </select>
                      </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2">
                      <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#category-inline">Abbrechen</button>
                      <button type="button" class="btn btn-sm btn-primary hb-category-inline-save">Speichern</button>
                    </div>
                  </div>
                </div>
              </div>
              <div class="mt-3">
                <?php $splitOpen = $transactionSplits ? 'show' : ''; ?>
                <div class="collapse <?= $splitOpen ?>" id="tx-splits">
                  <label class="form-label">
                    Splits (optional)
                    <span class="text-muted" data-bs-toggle="tooltip" title="Verteile den Betrag auf mehrere Kategorien; Summe muss exakt dem Betrag entsprechen.">ℹ️</span>
                  </label>
                  <?php for ($i = 0; $i < 3; $i++): ?>
                    <?php $existing = $transactionSplits[$i] ?? null; ?>
                    <div class="row g-2 mb-2">
                      <div class="col-7">
                        <select class="form-select form-select-sm" name="split_category_id[]">
                          <option value="">Kategorie wählen</option>
                          <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int)$cat['id'] ?>" <?= ($existing['category_id'] ?? null) == $cat['id'] ? 'selected' : '' ?>>
                              <?= htmlspecialchars($cat['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-5">
                        <input type="text" class="form-control form-control-sm" name="split_amount[]" value="<?= $existing ? number_format($existing['amount_cents'] / 100, 2, ',', '.') : '' ?>" placeholder="0,00">
                      </div>
                    </div>
                  <?php endfor; ?>
                  <div class="form-text">Summe der Splits muss dem Betrag entsprechen.</div>
                </div>
              </div>
              <div class="mt-3">
                <label class="form-label d-flex justify-content-between align-items-center">
                  <span>
                    Payee
                    <span class="text-muted" data-bs-toggle="tooltip" title="Empfänger/Zahler der Buchung. Optional.">ℹ️</span>
                  </span>
                  <button class="btn btn-sm btn-outline-secondary py-0 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#payee-inline" aria-expanded="false">+ Neu</button>
                </label>
                <?php
                $payeeSelectorId = 'payee-transaction';
                $payeeSelectorName = 'payee_id';
                $payeeSelectorPayees = $payees;
                $payeeSelectorSelected = $transaction['payee_id'] ?? null;
                $payeeSelectorPlaceholder = 'Payee suchen...';
                $payeeSelectorDisabled = false;
                $payeeSelectorReadonly = false;
                $payeeSelectorShowAdd = false;
                require __DIR__ . '/../templates/partials/payee_selector.php';
                ?>
                <div class="collapse mt-2" id="payee-inline">
                  <div class="border rounded-3 p-2 bg-body-tertiary hb-inline-payee">
                    <div class="row g-2">
                      <div class="col-md-6">
                        <label class="form-label small" for="payee-inline-name">Name</label>
                        <input type="text" class="form-control form-control-sm" id="payee-inline-name" data-payee-field="name" required>
                        <div class="invalid-feedback">Name ist erforderlich.</div>
                      </div>
                      <div class="col-md-6">
                        <label class="form-label small" for="payee-inline-iban">IBAN</label>
                        <input type="text" class="form-control form-control-sm" id="payee-inline-iban" data-payee-field="iban">
                      </div>
                    </div>
                    <div class="row g-2 mt-1">
                      <div class="col-md-6">
                        <label class="form-label small" for="payee-inline-address">Adresse</label>
                        <textarea class="form-control form-control-sm" id="payee-inline-address" rows="2" data-payee-field="address_text"></textarea>
                      </div>
                      <div class="col-md-6">
                        <label class="form-label small" for="payee-inline-bic">BIC</label>
                        <input type="text" class="form-control form-control-sm" id="payee-inline-bic" data-payee-field="bic">
                      </div>
                    </div>
                    <div class="mt-2">
                      <label class="form-label small" for="payee-inline-notes">Notizen</label>
                      <textarea class="form-control form-control-sm" id="payee-inline-notes" rows="2" data-payee-field="notes"></textarea>
                    </div>
                    <div class="mt-2 d-flex justify-content-end gap-2">
                      <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#payee-inline">Abbrechen</button>
                      <button type="button" class="btn btn-sm btn-primary hb-payee-inline-save">Speichern</button>
                    </div>
                  </div>
                </div>
              </div>
              <div class="mt-3">
                <label class="form-label d-flex justify-content-between align-items-center">
                  <span>
                    Tags
                    <span class="text-muted" data-bs-toggle="tooltip" title="Mehrfachauswahl möglich, um Buchungen zu gruppieren/filtern.">ℹ️</span>
                  </span>
                  <button class="btn btn-sm btn-outline-secondary py-0 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#tag-inline" aria-expanded="false">+ Neu</button>
                </label>
                <?php
                $currentTags = array_map(fn($t) => (int)$t['tag_id'], $transactionTags);
                $tagSelectorId = 'tags-transaction';
                $tagSelectorName = 'tag_ids[]';
                $tagSelectorTags = $tags;
                $tagSelectorSelected = $currentTags;
                $tagSelectorPlaceholder = 'Tag suchen...';
                $tagModalTarget = '#tagModal';
                $tagSelectorShowAdd = false;
                require __DIR__ . '/../templates/partials/tag_selector.php';
                ?>
                <div class="form-text">Mehrfachauswahl möglich.</div>
                <div class="collapse mt-2" id="tag-inline">
                  <div class="border rounded-3 p-2 bg-body-tertiary hb-inline-tag">
                    <div class="row g-2 align-items-end">
                      <div class="col-md-6">
                        <label class="form-label small" for="tag-inline-name">Name</label>
                        <input type="text" class="form-control form-control-sm" id="tag-inline-name" data-tag-field="name" required>
                        <div class="invalid-feedback">Name ist erforderlich.</div>
                      </div>
                      <div class="col-md-4">
                        <label class="form-label small" for="tag-inline-color">Farbe (Hex)</label>
                        <input type="text" class="form-control form-control-sm" id="tag-inline-color" data-tag-field="color" placeholder="#3a6ea5">
                      </div>
                      <div class="col-md-2">
                        <label class="form-label small" for="tag-inline-picker">Picker</label>
                        <input type="color" class="form-control form-control-color w-100" id="tag-inline-picker" data-tag-field="color_picker" value="#3a6ea5">
                      </div>
                    </div>
                    <div class="mt-2 d-flex justify-content-end gap-2">
                      <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#tag-inline">Abbrechen</button>
                      <button type="button" class="btn btn-sm btn-primary hb-tag-inline-save">Speichern</button>
                    </div>
                  </div>
                </div>
              </div>
              <div class="mt-3">
                <label class="form-label">Notiz</label>
                <textarea class="form-control" name="note" rows="2"><?= htmlspecialchars($transaction['note'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
              </div>
              <div class="row g-3 mt-3">
                <div class="col-md-6">
                  <label class="form-label">
                    Transfer von Konto
                    <span class="text-muted" data-bs-toggle="tooltip" title="Nur bei Typ 'transfer' nutzen; Konto, von dem abgebucht wird.">ℹ️</span>
                  </label>
                  <select class="form-select" name="transfer_from_account_id">
                    <option value="">--</option>
                    <?php foreach ($accounts as $acc): ?>
                      <option value="<?= (int)$acc['id'] ?>" <?= ($transaction['transfer_from_account_id'] ?? null) == $acc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($acc['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label">
                    Transfer zu Konto
                    <span class="text-muted" data-bs-toggle="tooltip" title="Nur bei Typ 'transfer' nutzen; Konto, das die Gutschrift erhält.">ℹ️</span>
                  </label>
                  <select class="form-select" name="transfer_to_account_id">
                    <option value="">--</option>
                    <?php foreach ($accounts as $acc): ?>
                      <option value="<?= (int)$acc['id'] ?>" <?= ($transaction['transfer_to_account_id'] ?? null) == $acc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($acc['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <button type="submit" class="btn btn-success mt-3">Speichern</button>
            </form>
            <?php if (!empty($transaction['id']) && ($transaction['type'] ?? '') !== 'transfer'): ?>
              <?php
              $recurringName = $transaction['payee_name'] ?? $transaction['category_name'] ?? $transaction['note'] ?? 'Wiederkehrend';
              ?>
              <div class="mt-4 border-top pt-3">
                <div class="d-flex justify-content-between align-items-center">
                  <h6 class="mb-0">Als wiederkehrend speichern</h6>
                  <button class="btn btn-sm btn-outline-secondary py-0 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#recurring-inline" aria-expanded="false">Details</button>
                </div>
                <div class="collapse mt-2" id="recurring-inline">
                  <form method="post" action="/transactions.php" class="hb-recurring-form">
                    <input type="hidden" name="action" value="create_recurring">
                    <input type="hidden" name="transaction_id" value="<?= (int)$transaction['id'] ?>">
                    <input type="hidden" name="recurring_account_id" value="">
                    <input type="hidden" name="recurring_category_id" value="">
                    <input type="hidden" name="recurring_payee_id" value="">
                    <input type="hidden" name="recurring_note" value="">
                    <input type="hidden" name="recurring_amount" value="">
                    <input type="hidden" name="recurring_direction" value="">
                    <div class="row g-2 align-items-end">
                      <div class="col-md-6">
                        <label class="form-label small">Name</label>
                        <input type="text" class="form-control form-control-sm" name="recurring_name" value="<?= htmlspecialchars($recurringName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
                      </div>
                      <div class="col-6 col-md-2">
                        <label class="form-label small">Intervall</label>
                        <select class="form-select form-select-sm" name="recurring_interval_unit">
                          <option value="day">Tag</option>
                          <option value="week">Woche</option>
                          <option value="month" selected>Monat</option>
                          <option value="year">Jahr</option>
                        </select>
                      </div>
                      <div class="col-6 col-md-2">
                        <label class="form-label small">Alle</label>
                        <input type="number" class="form-control form-control-sm" name="recurring_interval_value" value="1" min="1">
                      </div>
                      <div class="col-md-2">
                        <label class="form-label small">Start</label>
                        <input type="date" class="form-control form-control-sm" name="recurring_start_date" value="<?= htmlspecialchars($transaction['booking_date'] ?? date('Y-m-d'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                      </div>
                    </div>
                    <div class="row g-2 align-items-end mt-2">
                      <div class="col-md-4">
                        <label class="form-label small">Betragslogik</label>
                        <select class="form-select form-select-sm" name="recurring_amount_mode">
                          <option value="fixed" selected>Fix</option>
                          <option value="tolerance">Toleranz</option>
                          <option value="range">Spanne</option>
                        </select>
                      </div>
                      <div class="col-md-4">
                        <label class="form-label small">Toleranz (Betrag)</label>
                        <input type="text" class="form-control form-control-sm" name="recurring_tolerance_amount" placeholder="z. B. 5,00">
                      </div>
                      <div class="col-md-4">
                        <label class="form-label small">Toleranz (%)</label>
                        <input type="text" class="form-control form-control-sm" name="recurring_tolerance_pct" placeholder="z. B. 5">
                      </div>
                    </div>
                    <div class="row g-2 align-items-end mt-2">
                      <div class="col-md-4">
                        <label class="form-label small">Minbetrag</label>
                        <input type="text" class="form-control form-control-sm" name="recurring_min_amount" placeholder="z. B. 40,00">
                      </div>
                      <div class="col-md-4">
                        <label class="form-label small">Maxbetrag</label>
                        <input type="text" class="form-control form-control-sm" name="recurring_max_amount" placeholder="z. B. 60,00">
                      </div>
                      <div class="col-md-4">
                        <label class="form-label small">Ende</label>
                        <input type="date" class="form-control form-control-sm" name="recurring_end_date">
                      </div>
                    </div>
                    <div class="row g-2 align-items-center mt-2">
                      <div class="col-6 col-md-3">
                        <label class="form-label small">Priorität</label>
                        <select class="form-select form-select-sm" name="recurring_priority">
                          <?php for ($p = 1; $p <= 5; $p++): ?>
                            <option value="<?= $p ?>" <?= $p === 3 ? 'selected' : '' ?>><?= $p ?></option>
                          <?php endfor; ?>
                        </select>
                      </div>
                      <div class="col-6 col-md-3">
                        <div class="form-check mt-4">
                          <input class="form-check-input" type="checkbox" name="recurring_is_optional" id="recurring-optional">
                          <label class="form-check-label small" for="recurring-optional">Optional</label>
                        </div>
                      </div>
                      <div class="col-md-6 text-end">
                        <button type="submit" class="btn btn-sm btn-primary">Wiederkehrend speichern</button>
                      </div>
                    </div>
                  </form>
                </div>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        <?php
        $modalContent = ob_get_clean();
        $modalTitle = $action === 'show' ? 'Transaktionsdetails' : ($action === 'edit' ? 'Transaktion bearbeiten' : 'Neue Transaktion');
        ?>
        <div class="modal fade" id="hb-transaction-modal" tabindex="-1" aria-labelledby="hb-transaction-modal-label" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="hb-transaction-modal-label"><?= htmlspecialchars($modalTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h5>
                <a href="/transactions.php" class="btn-close" aria-label="Schließen"></a>
              </div>
              <div class="modal-body">
                <?= $modalContent ?>
              </div>
            </div>
          </div>
        </div>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$extraScripts = <<<HTML
<script src="/js/chip-selector.js"></script>
HTML;
$extraScripts .= <<<HTML
<script>
document.addEventListener('DOMContentLoaded', () => {
  const modalEl = document.getElementById('hb-transaction-modal');
  if (modalEl) {
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
  }
  const inline = document.querySelector('.hb-inline-payee');
  if (inline) {
    const saveBtn = inline.querySelector('.hb-payee-inline-save');
    const nameInput = inline.querySelector('[data-payee-field="name"]');
    const selector = document.querySelector('.hb-payee-selector');
    const collapseEl = document.getElementById('payee-inline');
    const collapse = collapseEl ? bootstrap.Collapse.getOrCreateInstance(collapseEl, { toggle: false }) : null;
    saveBtn?.addEventListener('click', async () => {
      if (!nameInput || !selector) return;
      const name = nameInput.value.trim();
      if (!name) {
        nameInput.classList.add('is-invalid');
        nameInput.focus();
        return;
      }
      nameInput.classList.remove('is-invalid');
      const formData = new FormData();
      inline.querySelectorAll('[data-payee-field]').forEach((field) => {
        if (!(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement)) return;
        const key = field.getAttribute('data-payee-field');
        if (!key) return;
        formData.append(key, field.value.trim());
      });
      const response = await fetch('/payees.php?action=create', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      if (!response.ok) {
        nameInput.classList.add('is-invalid');
        return;
      }
      const payload = await response.json();
      if (!payload || !payload.id || !window.hbAddPayeeOption) return;
      window.hbAddPayeeOption(payload, selector);
      inline.querySelectorAll('[data-payee-field]').forEach((field) => {
        if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) {
          field.value = '';
          field.classList.remove('is-invalid');
        }
      });
      collapse?.hide();
    });
  }
  const recurringForm = document.querySelector('.hb-recurring-form');
  if (recurringForm) {
    recurringForm.addEventListener('submit', () => {
      const txForm = document.getElementById('transaction-form');
      if (!txForm) return;
      const getValue = (selector) => txForm.querySelector(selector)?.value || '';
      recurringForm.querySelector('input[name="recurring_account_id"]').value = getValue('[name="account_id"]');
      recurringForm.querySelector('input[name="recurring_category_id"]').value = getValue('input[name="category_id"]');
      recurringForm.querySelector('input[name="recurring_payee_id"]').value = getValue('input[name="payee_id"]');
      recurringForm.querySelector('input[name="recurring_note"]').value = getValue('[name="note"]');
      recurringForm.querySelector('input[name="recurring_amount"]').value = getValue('[name="amount"]');
      recurringForm.querySelector('input[name="recurring_direction"]').value = getValue('[name="type"]');
    });
  }
  const tagInline = document.querySelector('.hb-inline-tag');
  if (tagInline) {
    const saveBtn = tagInline.querySelector('.hb-tag-inline-save');
    const nameInput = tagInline.querySelector('[data-tag-field="name"]');
    const colorInput = tagInline.querySelector('[data-tag-field="color"]');
    const picker = tagInline.querySelector('[data-tag-field="color_picker"]');
    const selector = document.querySelector('.hb-tag-selector');
    const collapseEl = document.getElementById('tag-inline');
    const collapse = collapseEl ? bootstrap.Collapse.getOrCreateInstance(collapseEl, { toggle: false }) : null;
    if (picker && colorInput) {
      picker.addEventListener('input', () => {
        colorInput.value = picker.value;
      });
    }
    saveBtn?.addEventListener('click', async () => {
      if (!nameInput || !selector) return;
      const name = nameInput.value.trim();
      if (!name) {
        nameInput.classList.add('is-invalid');
        nameInput.focus();
        return;
      }
      nameInput.classList.remove('is-invalid');
      if (picker && colorInput && !colorInput.value) {
        colorInput.value = picker.value;
      }
      const formData = new FormData();
      formData.append('name', name);
      if (colorInput && colorInput.value.trim()) {
        formData.append('color', colorInput.value.trim());
      }
      const response = await fetch('/tags.php?action=create', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      if (!response.ok) {
        nameInput.classList.add('is-invalid');
        return;
      }
      const payload = await response.json();
      if (!payload || !payload.id || !window.hbAddTagOption) return;
      window.hbAddTagOption(payload, selector);
      tagInline.querySelectorAll('[data-tag-field]').forEach((field) => {
        if (field instanceof HTMLInputElement) {
          field.value = field.getAttribute('data-tag-field') === 'color_picker' ? '#3a6ea5' : '';
          field.classList.remove('is-invalid');
        }
      });
      collapse?.hide();
    });
  }
  const categoryInline = document.querySelector('.hb-inline-category');
  if (categoryInline) {
    const saveBtn = categoryInline.querySelector('.hb-category-inline-save');
    const nameInput = categoryInline.querySelector('[data-category-field="name"]');
    const typeSelect = categoryInline.querySelector('[data-category-field="type"]');
    const selector = document.querySelector('.hb-category-selector');
    const collapseEl = document.getElementById('category-inline');
    const collapse = collapseEl ? bootstrap.Collapse.getOrCreateInstance(collapseEl, { toggle: false }) : null;
    saveBtn?.addEventListener('click', async () => {
      if (!nameInput || !selector || !typeSelect) return;
      const name = nameInput.value.trim();
      if (!name) {
        nameInput.classList.add('is-invalid');
        nameInput.focus();
        return;
      }
      nameInput.classList.remove('is-invalid');
      const formData = new FormData();
      formData.append('name', name);
      formData.append('type', typeSelect.value);
      const response = await fetch('/categories.php?action=create', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      if (!response.ok) {
        nameInput.classList.add('is-invalid');
        return;
      }
      const payload = await response.json();
      if (!payload || !payload.id || !window.hbAddCategoryOption) return;
      window.hbAddCategoryOption(payload, selector);
      nameInput.value = '';
      nameInput.classList.remove('is-invalid');
      typeSelect.value = 'expense';
      collapse?.hide();
    });
  }
});
document.addEventListener('submit', (event) => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement)) return;
  const msg = form.getAttribute('data-confirm');
  if (msg && !window.confirm(msg)) {
    event.preventDefault();
  }
});
</script>
HTML;
$tagModalId = 'tagModal';
ob_start();
require __DIR__ . '/../templates/partials/tag_modal.php';
$content .= ob_get_clean();
$categoryModalId = 'categoryModal';
ob_start();
require __DIR__ . '/../templates/partials/category_modal.php';
$content .= ob_get_clean();
require __DIR__ . '/../templates/layout.php';
