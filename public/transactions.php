<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

hb_require_login();
$serverPdo = hb_get_pdo();
$household = hb_require_household($serverPdo);
$pdo = hb_household_pdo($serverPdo, (int)$household['id']);
$db = hb_dbal_household();
$currentHousehold = $household;
$currentUser = hb_current_user($serverPdo);
$pageTitle = 'Transactions';
$activeNav = 'transactions';
$breadcrumbs = [
    ['label' => 'Transactions', 'href' => '/transactions.php'],
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
        $error = hb_t('Transaction not found.');
    } else {
        $del = $pdo->prepare('delete from transactions where id = :id and household_id = :hid');
        $del->execute(['id' => $txId, 'hid' => $household['id']]);
        header('Location: /transactions.php?msg=deleted');
        exit;
    }
}

if ($action === 'bulk_update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $idsRaw = $_POST['ids'] ?? [];
    if (!is_array($idsRaw)) {
        $idsRaw = [];
    }
    $ids = [];
    foreach ($idsRaw as $r) {
        $i = (int)$r;
        if ($i > 0) {
            $ids[] = $i;
        }
    }
    $ids = array_values(array_unique($ids));

    $categoryRaw = $_POST['category_id'] ?? '';
    $hasCategoryUpdate = is_string($categoryRaw) && $categoryRaw !== '';
    $categoryId = $hasCategoryUpdate ? (int)$categoryRaw : null;

    $tagIdsRaw = $_POST['tag_ids'] ?? [];
    if (!is_array($tagIdsRaw)) {
        $tagIdsRaw = [];
    }
    $tagIds = [];
    foreach ($tagIdsRaw as $r) {
        $i = (int)$r;
        if ($i > 0) {
            $tagIds[] = $i;
        }
    }
    $tagIds = array_values(array_unique($tagIds));
    $tagMode = (string)($_POST['tag_mode'] ?? '');
    $hasTagUpdate = in_array($tagMode, ['add', 'replace', 'clear'], true);

    if (!$ids || (!$hasCategoryUpdate && !$hasTagUpdate)) {
        header('Location: /transactions.php?msg=bulk_none');
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $verifyStmt = $pdo->prepare(
        "select t.id, t.type,
                (select count(*) from transaction_splits ts where ts.transaction_id = t.id) as split_count
           from transactions t
          where t.household_id = ? and t.id in ($placeholders)"
    );
    $verifyStmt->execute(array_merge([$household['id']], $ids));
    $verified = $verifyStmt->fetchAll();
    $verifiedIds = array_map(static fn($r) => (int)$r['id'], $verified);

    if ($hasCategoryUpdate && $categoryId !== null) {
        $catCheck = $pdo->prepare('select id from categories where id = :id and household_id = :hid');
        $catCheck->execute(['id' => $categoryId, 'hid' => $household['id']]);
        if (!$catCheck->fetch()) {
            $hasCategoryUpdate = false;
        }
    }
    if ($hasTagUpdate && $tagIds) {
        $tagPh = implode(',', array_fill(0, count($tagIds), '?'));
        $tagCheck = $pdo->prepare("select id from tags where household_id = ? and id in ($tagPh)");
        $tagCheck->execute(array_merge([$household['id']], $tagIds));
        $validTags = array_map(static fn($r) => (int)$r['id'], $tagCheck->fetchAll());
        $tagIds = array_values(array_intersect($tagIds, $validTags));
    }

    $catUpdated = 0;
    $catSkipped = 0;
    $tagsUpdated = 0;

    $pdo->beginTransaction();
    try {
        if ($hasCategoryUpdate) {
            $eligible = [];
            foreach ($verified as $r) {
                if (($r['type'] ?? '') === 'transfer' || (int)$r['split_count'] > 0) {
                    $catSkipped++;
                    continue;
                }
                $eligible[] = (int)$r['id'];
            }
            if ($eligible) {
                $eligPh = implode(',', array_fill(0, count($eligible), '?'));
                $upd = $pdo->prepare("update transactions set category_id = ? where household_id = ? and id in ($eligPh)");
                $upd->execute(array_merge([$categoryId], [$household['id']], $eligible));
                $catUpdated = count($eligible);
            }
        }
        if ($hasTagUpdate) {
            $delStmt = $pdo->prepare('delete from transaction_tags where transaction_id = :id');
            $insStmt = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? $pdo->prepare(
                    'insert into transaction_tags (transaction_id, tag_id)
                     select :tid, :tag
                      where not exists (
                        select 1
                          from transaction_tags
                         where transaction_id = :tid
                           and tag_id = :tag
                      )'
                )
                : $pdo->prepare('insert into transaction_tags (transaction_id, tag_id) values (:tid, :tag) on conflict do nothing');
            foreach ($verifiedIds as $txId) {
                if ($tagMode === 'replace' || $tagMode === 'clear') {
                    $delStmt->execute(['id' => $txId]);
                }
                if ($tagMode !== 'clear' && $tagIds) {
                    foreach ($tagIds as $tid) {
                        $insStmt->execute(['tid' => $txId, 'tag' => $tid]);
                    }
                }
                $tagsUpdated++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $count = max($catUpdated, $tagsUpdated);
    header('Location: /transactions.php?msg=bulk_saved&n=' . $count . '&skip=' . $catSkipped);
    exit;
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
    $accountOverride = (($_POST['recurring_account_id'] ?? '') !== '') ? (int)$_POST['recurring_account_id'] : null;
    $categoryOverride = (($_POST['recurring_category_id'] ?? '') !== '') ? (int)$_POST['recurring_category_id'] : null;
    $payeeOverride = (($_POST['recurring_payee_id'] ?? '') !== '') ? (int)$_POST['recurring_payee_id'] : null;
    $noteOverride = trim((string)($_POST['recurring_note'] ?? ''));

    if ($name === '') {
        $error = hb_t('Recurring payment name is required.');
    } elseif (!in_array($intervalUnit, ['day', 'week', 'month', 'year'], true)) {
        $error = hb_t('Invalid interval.');
    } elseif ($intervalValue < 1) {
        $error = hb_t('Interval value must be positive.');
    } elseif ($startDate === '') {
        $error = hb_t('Start date is required.');
    } elseif (!in_array($amountMode, ['fixed', 'tolerance', 'range'], true)) {
        $error = hb_t('Invalid amount logic.');
    } elseif ($amountMode === 'tolerance' && $toleranceAmount === null && $tolerancePct === null) {
        $error = hb_t('Tolerance is required.');
    } elseif ($amountMode === 'range' && ($minAmount === null || $maxAmount === null)) {
        $error = hb_t('Min and max amount are required.');
    }

    $txStmt = $pdo->prepare('select * from transactions where id = :id and household_id = :hid');
    $txStmt->execute(['id' => $txId, 'hid' => $household['id']]);
    $txRow = $txStmt->fetch();
    if ($error === null && !$txRow) {
        $error = hb_t('Transaction not found.');
    }

    if ($error === null) {
        $direction = $direction !== '' ? $direction : (string)$txRow['type'];
        if (!in_array($direction, ['income', 'expense'], true)) {
            $error = hb_t('Recurring is only allowed for income/expense.');
        }
    }

    $amountCents = $amountOverride !== null ? $amountOverride : (int)$txRow['amount_cents'];
    if ($error === null && $amountCents <= 0) {
        $error = hb_t('Amount is invalid.');
    }

    if ($error === null) {
        $startDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        if ($startDateObj && hb_is_period_closed($pdo, $household['id'], $startDateObj)) {
            $error = hb_t('The month is already closed. Changes are locked.');
        }
        if ($endDate !== '') {
            $endDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
            if (!$endDateObj) {
                $error = hb_t('End date is invalid.');
            } elseif ($startDateObj && $endDateObj < $startDateObj) {
                $error = hb_t('End date must be after start date.');
            }
        }
    }

    $accountId = $accountOverride ?? $txRow['account_id'];
    $categoryId = $categoryOverride ?? $txRow['category_id'];
    $payeeId = $payeeOverride ?? $txRow['payee_id'];
    $note = $noteOverride !== '' ? $noteOverride : ($txRow['note'] ?? null);

    if ($accountId && !hb_find_by_id($accounts, (int)$accountId)) {
        $error = hb_t('Account does not belong to the household.');
    }
    if ($categoryId && !hb_find_by_id($categories, (int)$categoryId)) {
        $error = hb_t('Category does not belong to the household.');
    }
    if ($payeeId && !hb_find_by_id($payees, (int)$payeeId)) {
        $error = hb_t('Payee does not belong to the household.');
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
    $accountId = (($_POST['account_id'] ?? '') !== '') ? (int)$_POST['account_id'] : null;
    $categoryIdRaw = $_POST['category_id'] ?? null;
    $categoryId = ($categoryIdRaw === '' || $categoryIdRaw === null) ? null : (int)$categoryIdRaw;
    $payeeIdRaw = $_POST['payee_id'] ?? null;
    $payeeId = ($payeeIdRaw === '' || $payeeIdRaw === null) ? null : (int)$payeeIdRaw;
    $note = trim((string)($_POST['note'] ?? ''));
    $transferFrom = (($_POST['transfer_from_account_id'] ?? '') !== '') ? (int)$_POST['transfer_from_account_id'] : null;
    $transferTo = (($_POST['transfer_to_account_id'] ?? '') !== '') ? (int)$_POST['transfer_to_account_id'] : null;
    $tagIds = array_filter(array_map('intval', $_POST['tag_ids'] ?? []));
    $splitCats = $_POST['split_category_id'] ?? [];
    $splitAmounts = $_POST['split_amount'] ?? [];
    $id = (int)($_POST['id'] ?? 0);
    $rowVersion = (int)($_POST['row_version'] ?? 0);

    if (!in_array($type, ['income', 'expense', 'transfer'], true)) {
        $error = hb_t('Invalid type.');
    } elseif (!$bookingDate) {
        $error = hb_t('Booking date is required.');
    } elseif ($amountCents === null || $amountCents < 0) {
        $error = hb_t('Amount is invalid.');
    }

    if ($error === null) {
        $bookingDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $bookingDate);
        if ($bookingDateObj && hb_is_period_closed($pdo, $household['id'], $bookingDateObj)) {
            $error = hb_t('The month is already closed. Changes are locked.');
        }
    }

    if ($accountId && !hb_find_by_id($accounts, $accountId)) {
        $error = hb_t('Account does not belong to the household.');
    }
    if ($transferFrom && !hb_find_by_id($accounts, $transferFrom)) {
        $error = hb_t('Invalid transfer source account.');
    }
    if ($transferTo && !hb_find_by_id($accounts, $transferTo)) {
        $error = hb_t('Invalid transfer target account.');
    }
    if ($categoryId && !hb_find_by_id($categories, $categoryId)) {
        $error = hb_t('Category does not belong to the household.');
    }
    if ($payeeId && !hb_find_by_id($payees, $payeeId)) {
        $error = hb_t('Payee does not belong to the household.');
    }
    foreach ($tagIds as $tid) {
        if (!hb_find_by_id($tags, $tid)) {
            $error = hb_t('Tag does not belong to the household.');
            break;
        }
    }

    if ($type === 'transfer') {
        if (!$transferFrom || !$transferTo) {
            $error = hb_t('Source and target accounts are required.');
        } elseif ($transferFrom === $transferTo) {
            $error = hb_t('Transfer requires two different accounts.');
        }
    } else {
        if (!$accountId) {
            $error = hb_t('Account is required.');
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
                $error = hb_t('Category or splits are required.');
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
                $error = hb_t('Split category does not belong to the household.');
                break;
            }
            $splits[] = ['category_id' => $catId, 'amount_cents' => $cents];
            $splitSum += $cents;
        }
    }
    if ($splits && $splitSum !== $amountCents) {
        $error = hb_t('Split total must match the amount.');
    }

    if ($error === null) {
        if ($action === 'store') {
            $transactionId = hb_dbal_insert_and_get_id($db, 'transactions', [
                'household_id' => (int)$household['id'],
                'type' => $type,
                'booking_date' => $bookingDate,
                'amount_cents' => $amountCents,
                'currency_code' => (string)$household['currency_code'],
                'account_id' => $type === 'transfer' ? null : $accountId,
                'category_id' => $categoryId,
                'payee_id' => $type === 'transfer' ? null : $payeeId,
                'note' => $note !== '' ? $note : null,
                'transfer_from_account_id' => $type === 'transfer' ? $transferFrom : null,
                'transfer_to_account_id' => $type === 'transfer' ? $transferTo : null,
                'is_reviewed' => true,
            ], 'id', ['is_reviewed' => \Doctrine\DBAL\ParameterType::BOOLEAN]);
        } else {
            $transactionId = $id;
            $own = $pdo->prepare('select id from transactions where id = :id and household_id = :hid');
            $own->execute(['id' => $transactionId, 'hid' => $household['id']]);
            if (!$own->fetch()) {
                $error = hb_t('Transaction not found.');
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
                            is_reviewed = true,
                            updated_at = :updated_at
                      where id = :id and household_id = :hid and row_version = :row_version'
                );
                    $stmt->execute([
                        'type' => $type,
                        'booking_date' => $bookingDate,
                        'amount' => $amountCents,
                        'cur' => $household['currency_code'],
                        'account_id' => $type === 'transfer' ? null : $accountId,
                        'category_id' => $categoryId,
                        'payee_id' => $type === 'transfer' ? null : $payeeId,
                        'note' => $note !== '' ? $note : null,
                        'tf' => $type === 'transfer' ? $transferFrom : null,
                        'tt' => $type === 'transfer' ? $transferTo : null,
                    'id' => $transactionId,
                    'hid' => $household['id'],
                    'row_version' => $rowVersion,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
                if ($stmt->rowCount() === 0) {
                    $fresh = $pdo->prepare('select * from transactions where id = :id and household_id = :hid');
                    $fresh->execute(['id' => $transactionId, 'hid' => $household['id']]);
                    $current = $fresh->fetch() ?: [];
                    $conflictRows = hb_build_conflict_rows(
                        [
                            'type' => hb_t('Type'),
                            'booking_date' => hb_t('Date'),
                            'amount_cents' => hb_t('Amount'),
                            'account_id' => hb_t('Account'),
                            'category_id' => hb_t('Category'),
                            'payee_id' => hb_t('Payee'),
                            'note' => hb_t('Note'),
                            'transfer_from_account_id' => hb_t('Transfer from'),
                            'transfer_to_account_id' => hb_t('Transfer to'),
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
            $pdo->beginTransaction();
            try {
                $pdo->prepare('delete from transaction_splits where transaction_id = :id')->execute(['id' => $transactionId]);
                $insSplit = $pdo->prepare(
                    'insert into transaction_splits (household_id, transaction_id, category_id, amount_cents, note)
                     values (:hid, :tid, :cid, :amount, null)'
                );
                foreach ($splits as $split) {
                    $insSplit->execute([
                        'hid' => $household['id'],
                        'tid' => $transactionId,
                        'cid' => $split['category_id'],
                        'amount' => $split['amount_cents'],
                    ]);
                }

                $pdo->prepare('delete from transaction_tags where transaction_id = :id')->execute(['id' => $transactionId]);
                $insTag = $pdo->prepare('insert into transaction_tags (transaction_id, tag_id) values (:tid, :tag)');
                foreach ($tagIds as $tagId) {
                    $insTag->execute(['tid' => $transactionId, 'tag' => $tagId]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
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
        $error = hb_t('Transaction not found.');
    } elseif (!isset($_FILES['attachment']) || $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
        $error = hb_t('Upload failed.');
    } else {
        $file = $_FILES['attachment'];
        if ($file['size'] > 5 * 1024 * 1024) {
            $error = hb_t('File too large (max 5MB).');
        } else {
            $original = basename($file['name']);
            $uploaded = file_get_contents((string)$file['tmp_name']);
            if ($uploaded === false) {
                $error = hb_t('File could not be saved.');
            } else {
                $storedMeta = hb_attachment_store_binary($serverPdo, (int)$household['id'], $uploaded, $original);
                $ins = $pdo->prepare(
                    'insert into attachments (household_id, transaction_id, original_filename, stored_filename, mime_type, size_bytes, storage_path)
                     values (:hid, :tx, :orig, :stored, :mime, :size, :path)'
                );
                $ins->execute([
                    'hid' => $household['id'],
                    'tx' => $txId,
                    'orig' => $original,
                    'stored' => $storedMeta['stored_filename'],
                    'mime' => $storedMeta['mime_type'],
                    'size' => $storedMeta['size_bytes'],
                    'path' => $storedMeta['storage_path'],
                ]);
                header('Location: /transactions.php?action=show&id=' . $txId . '&msg=attachment_saved');
                exit;
            }
        }
    }
}

if ($action === 'link_existing_attachment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $txId = (int)($_POST['transaction_id'] ?? 0);
    $attachmentId = (int)($_POST['attachment_id'] ?? 0);
    $txCheck = $pdo->prepare('select id from transactions where id = :id and household_id = :hid');
    $txCheck->execute(['id' => $txId, 'hid' => $household['id']]);
    if (!$txCheck->fetch()) {
        $error = hb_t('Transaction not found.');
    } elseif ($attachmentId < 1) {
        $error = hb_t('Please select an attachment.');
    } else {
        $att = $pdo->prepare('select id, transaction_id from attachments where id = :id and household_id = :hid');
        $att->execute(['id' => $attachmentId, 'hid' => $household['id']]);
        $row = $att->fetch();
        if (!$row) {
            $error = hb_t('Attachment not found.');
        } elseif ($row['transaction_id'] !== null && (int)$row['transaction_id'] !== $txId) {
            $error = hb_t('Attachment is already linked to another transaction.');
        } else {
            $pdo->prepare('update attachments set transaction_id = :tx where id = :id and household_id = :hid')
                ->execute(['tx' => $txId, 'id' => $attachmentId, 'hid' => $household['id']]);
            header('Location: /transactions.php?action=show&id=' . $txId . '&msg=attachment_linked');
            exit;
        }
    }
}

if (($action === 'edit' || $action === 'show') && empty($conflict)) {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare(
        'select t.*, p.name as payee_name, c.name as category_name, a.name as account_name,
                af.name as transfer_from_name, at.name as transfer_to_name,
                sp.name as suggested_plan_name, sp.planned_date as suggested_plan_date,
                pp.name as planned_name, pp.planned_date as planned_date
           from transactions t
           left join payees p on p.id = t.payee_id
           left join categories c on c.id = t.category_id
           left join accounts a on a.id = t.account_id
           left join accounts af on af.id = t.transfer_from_account_id
           left join accounts at on at.id = t.transfer_to_account_id
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
        $error = hb_t('Transaction not found.');
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
    $where[] = '(lower(t.note) like lower(:text) or lower(p.name) like lower(:text) or lower(c.name) like lower(:text) or lower(a.name) like lower(:text))';
    $params['text'] = '%' . $filters['text'] . '%';
}

$whereSql = $where ? 'where ' . implode(' and ', $where) : '';
$listSql = <<<SQL
    select t.*, a.name as account_name, c.name as category_name, p.name as payee_name,
           af.name as transfer_from_name, at.name as transfer_to_name,
           sp.name as suggested_plan_name, sp.planned_date as suggested_plan_date,
           pp.name as planned_name, pp.planned_date as planned_date
      from transactions t
      left join accounts a on a.id = t.account_id
      left join accounts af on af.id = t.transfer_from_account_id
      left join accounts at on at.id = t.transfer_to_account_id
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

$typeLabels = [
    'income' => hb_t('Income'),
    'expense' => hb_t('Expense'),
    'transfer' => hb_t('Transfer'),
];

$quickDefaultStmt = $pdo->prepare(
    'select account_id, category_id, payee_id from transactions
      where household_id = :hid and type != \'transfer\'
      order by booking_date desc, id desc limit 1'
);
$quickDefaultStmt->execute(['hid' => $household['id']]);
$quickDefault = $quickDefaultStmt->fetch() ?: [];

ob_start();
?>
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h4 mb-0"><?= htmlspecialchars(hb_t('Transactions'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
      <div class="text-muted small"><?= htmlspecialchars(hb_t('Household:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars($household['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <a class="btn btn-sm btn-outline-secondary" href="/accounts.php"><?= htmlspecialchars(hb_t('Accounts'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
      <a class="btn btn-sm btn-outline-secondary" href="/categories.php"><?= htmlspecialchars(hb_t('Categories'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
      <a class="btn btn-sm btn-outline-secondary" href="/tags.php"><?= htmlspecialchars(hb_t('Tags'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
      <a class="btn btn-sm btn-outline-secondary" href="/payees.php"><?= htmlspecialchars(hb_t('Payees'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
    </div>
  </div>

  <?php if ($msg === 'saved'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Transaction saved.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php elseif ($msg === 'deleted'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Transaction deleted.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php elseif ($msg === 'recurring_saved'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Recurring payment created.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php elseif ($msg === 'bulk_saved'): ?>
    <?php
    $bulkN = max(0, (int)($_GET['n'] ?? 0));
    $bulkSkip = max(0, (int)($_GET['skip'] ?? 0));
    $bulkText = sprintf(hb_t('%d transactions updated.'), $bulkN);
    if ($bulkSkip > 0) {
        $bulkText .= ' ' . sprintf(hb_t('%d skipped (transfers or splits).'), $bulkSkip);
    }
    ?>
    <div class="alert alert-success"><?= htmlspecialchars($bulkText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php elseif ($msg === 'bulk_none'): ?>
    <div class="alert alert-warning"><?= htmlspecialchars(hb_t('Nothing to update — select transactions and an action.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($msg === 'attachment_saved'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Attachment saved.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php elseif ($msg === 'attachment_linked'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Attachment linked.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-12">
      <div class="hb-whitebox mb-3">
        <div class="hb-whitebox-body">
          <h2 class="h6"><?= htmlspecialchars(hb_t('Filter'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
          <form class="row g-2" method="get" action="/transactions.php">
            <div class="col-12 col-md-3">
              <label class="form-label small"><?= htmlspecialchars(hb_t('From'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="date" class="form-control form-control-sm" name="date_from" value="<?= htmlspecialchars($filters['date_from'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
            <div class="col-12 col-md-3">
              <label class="form-label small"><?= htmlspecialchars(hb_t('To'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="date" class="form-control form-control-sm" name="date_to" value="<?= htmlspecialchars($filters['date_to'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
            <div class="col-12 col-md-3">
              <label class="form-label small"><?= htmlspecialchars(hb_t('Account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <select class="form-select form-select-sm" name="account_id">
                <option value=""><?= htmlspecialchars(hb_t('All'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <?php foreach ($accounts as $acc): ?>
                  <option value="<?= (int)$acc['id'] ?>" <?= $filters['account_id'] == $acc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($acc['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-3">
              <label class="form-label small"><?= htmlspecialchars(hb_t('Category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <select class="form-select form-select-sm" name="category_id">
                <option value=""><?= htmlspecialchars(hb_t('All'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <?php foreach ($categories as $cat): ?>
                  <option value="<?= (int)$cat['id'] ?>" <?= $filters['category_id'] == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-3">
              <label class="form-label small"><?= htmlspecialchars(hb_t('Type'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <select class="form-select form-select-sm" name="type">
                <option value=""><?= htmlspecialchars(hb_t('All'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <?php foreach (['income', 'expense', 'transfer'] as $t): ?>
                  <option value="<?= $t ?>" <?= $filters['type'] === $t ? 'selected' : '' ?>>
                    <?= htmlspecialchars($typeLabels[$t] ?? $t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label small"><?= htmlspecialchars(hb_t('Text'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="text" class="form-control form-control-sm" name="text" placeholder="<?= htmlspecialchars(hb_t('Search'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" value="<?= htmlspecialchars($filters['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
            <div class="col-12 col-md-2 align-self-end">
              <button class="btn btn-sm btn-outline-primary w-100" type="submit"><?= htmlspecialchars(hb_t('Filter'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
            </div>
          </form>
        </div>
      </div>

        <form id="hb-bulk-form" method="post" action="/transactions.php" class="d-none">
          <input type="hidden" name="action" value="bulk_update">
        </form>
        <div class="hb-whitebox">
          <div class="hb-whitebox-body">
          <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-2">
            <h2 class="h6 mb-0"><?= htmlspecialchars(hb_t('Last 200'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <a class="btn btn-sm btn-primary" href="/transactions.php?action=new"><?= htmlspecialchars(hb_t('New transaction'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
          </div>
          <div class="table-responsive d-none d-md-block">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th class="hb-bulk-col">
                    <input type="checkbox" class="form-check-input" id="hb-bulk-select-all" aria-label="<?= htmlspecialchars(hb_t('Select all'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  </th>
                  <th><?= htmlspecialchars(hb_t('Date'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(hb_t('Type'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(hb_t('Amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(hb_t('Account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(hb_t('Category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(hb_t('Payee'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th class="text-end"><?= htmlspecialchars(hb_t('Actions'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($transactions as $tx): ?>
                  <?php
                  $accountLabel = $tx['account_name'] ?? '-';
                  if (($tx['type'] ?? '') === 'transfer') {
                      $fromName = $tx['transfer_from_name'] ?? hb_t('Transfer from');
                      $toName = $tx['transfer_to_name'] ?? hb_t('Transfer to');
                      $accountLabel = trim($fromName . ' → ' . $toName);
                  }
                  ?>
                  <tr>
                    <td class="hb-bulk-col">
                      <input type="checkbox" class="form-check-input hb-bulk-check" form="hb-bulk-form" name="ids[]" value="<?= (int)$tx['id'] ?>" aria-label="<?= htmlspecialchars(hb_t('Select transaction'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </td>
                    <td><?= htmlspecialchars($tx['booking_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($typeLabels[$tx['type']] ?? $tx['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= number_format($tx['amount_cents'] / 100, 2, ',', '.') ?> €</td>
                    <td><?= htmlspecialchars($accountLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td>
                      <?= htmlspecialchars($tx['category_name'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      <?php if (empty($tx['planned_payment_id']) && !empty($tx['suggested_planned_payment_id']) && !empty($tx['suggested_plan_name'])): ?>
                        <div class="small text-warning"><?= htmlspecialchars(hb_t('Suggestion:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars($tx['suggested_plan_date'] . ' · ' . $tx['suggested_plan_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                      <?php elseif (!empty($tx['planned_payment_id']) && !empty($tx['planned_name'])): ?>
                        <div class="small text-muted"><?= htmlspecialchars(hb_t('Plan:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars($tx['planned_date'] . ' · ' . $tx['planned_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                      <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($tx['payee_name'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td class="text-end">
                      <div class="d-flex justify-content-end gap-1">
                        <a class="btn btn-sm btn-outline-secondary" href="/transactions.php?action=show&id=<?= (int)$tx['id'] ?>"><?= htmlspecialchars(hb_t('Details'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                        <a class="btn btn-sm btn-outline-primary" href="/transactions.php?action=edit&id=<?= (int)$tx['id'] ?>"><?= htmlspecialchars(hb_t('Edit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                        <form method="post" action="/transactions.php" data-confirm="<?= htmlspecialchars(hb_t('Delete transaction?'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="id" value="<?= (int)$tx['id'] ?>">
                          <button type="submit" class="btn btn-sm btn-outline-danger"><?= htmlspecialchars(hb_t('Delete'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$transactions): ?>
                  <tr><td colspan="8" class="text-muted"><?= htmlspecialchars(hb_t('No transactions found.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <div class="d-md-none">
            <?php foreach ($transactions as $tx): ?>
              <?php
              $accountLabel = $tx['account_name'] ?? '-';
              if (($tx['type'] ?? '') === 'transfer') {
                  $fromName = $tx['transfer_from_name'] ?? hb_t('Transfer from');
                  $toName = $tx['transfer_to_name'] ?? hb_t('Transfer to');
                  $accountLabel = trim($fromName . ' → ' . $toName);
              }
              ?>
              <div class="hb-mobile-card p-3">
                <div class="hb-mobile-card-row mb-3">
                  <div class="d-flex align-items-start gap-2">
                    <input type="checkbox" class="form-check-input hb-bulk-check mt-1" form="hb-bulk-form" name="ids[]" value="<?= (int)$tx['id'] ?>" aria-label="<?= htmlspecialchars(hb_t('Select transaction'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <div>
                      <div class="fw-semibold"><?= number_format($tx['amount_cents'] / 100, 2, ',', '.') ?> €</div>
                      <div class="text-muted small"><?= htmlspecialchars($tx['booking_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · <?= htmlspecialchars($typeLabels[$tx['type']] ?? $tx['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    </div>
                  </div>
                  <div class="text-md-end">
                    <div class="fw-semibold"><?= htmlspecialchars($tx['payee_name'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <div class="text-muted small"><?= htmlspecialchars($accountLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                  </div>
                </div>
                <div class="hb-mobile-meta">
                  <div>
                    <span class="hb-mobile-meta-label"><?= htmlspecialchars(hb_t('Category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <div><?= htmlspecialchars($tx['category_name'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php if (empty($tx['planned_payment_id']) && !empty($tx['suggested_planned_payment_id']) && !empty($tx['suggested_plan_name'])): ?>
                      <div class="small text-warning mt-1"><?= htmlspecialchars(hb_t('Suggestion:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars($tx['suggested_plan_date'] . ' · ' . $tx['suggested_plan_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php elseif (!empty($tx['planned_payment_id']) && !empty($tx['planned_name'])): ?>
                      <div class="small text-muted mt-1"><?= htmlspecialchars(hb_t('Plan:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars($tx['planned_date'] . ' · ' . $tx['planned_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="hb-mobile-actions mt-3">
                  <a class="btn btn-outline-secondary btn-sm" href="/transactions.php?action=show&id=<?= (int)$tx['id'] ?>"><?= htmlspecialchars(hb_t('Details'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                  <div class="hb-mobile-actions-inline">
                    <a class="btn btn-outline-primary btn-sm" href="/transactions.php?action=edit&id=<?= (int)$tx['id'] ?>"><?= htmlspecialchars(hb_t('Edit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                    <form method="post" action="/transactions.php" data-confirm="<?= htmlspecialchars(hb_t('Delete transaction?'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$tx['id'] ?>">
                      <button type="submit" class="btn btn-outline-danger btn-sm"><?= htmlspecialchars(hb_t('Delete'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                    </form>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
            <?php if (!$transactions): ?>
              <div class="text-muted"><?= htmlspecialchars(hb_t('No transactions found.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="hb-bulk-bar" id="hb-bulk-bar" hidden>
    <div class="hb-bulk-bar-inner">
      <div class="hb-bulk-bar-count">
        <span id="hb-bulk-count">0</span>
        <span class="hb-bulk-bar-label"><?= htmlspecialchars(hb_t('selected'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
      </div>
      <div class="hb-bulk-bar-actions">
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#hb-bulk-modal">
          <i class="bi bi-pencil-square me-1" aria-hidden="true"></i><?= htmlspecialchars(hb_t('Edit selected'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="hb-bulk-clear">
          <?= htmlspecialchars(hb_t('Cancel'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </button>
      </div>
    </div>
  </div>

  <div class="modal fade" id="hb-bulk-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><?= htmlspecialchars(hb_t('Edit selected transactions'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(hb_t('Close'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small mb-3">
            <span id="hb-bulk-modal-count">0</span> <?= htmlspecialchars(hb_t('transactions will be updated.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </p>

          <div class="mb-3">
            <label class="form-label" for="hb-bulk-category">
              <?= htmlspecialchars(hb_t('Set category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </label>
            <select class="form-select" id="hb-bulk-category" form="hb-bulk-form" name="category_id">
              <option value=""><?= htmlspecialchars(hb_t('— Keep current —'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text"><?= htmlspecialchars(hb_t('Transfers and split transactions are skipped.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
          </div>

          <div class="mb-2">
            <label class="form-label"><?= htmlspecialchars(hb_t('Tags'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <div class="btn-group btn-group-sm w-100 mb-2" role="group" aria-label="<?= htmlspecialchars(hb_t('Tag mode'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <input type="radio" class="btn-check" name="tag_mode" id="hb-bulk-tag-mode-none" value="" checked form="hb-bulk-form">
              <label class="btn btn-outline-secondary" for="hb-bulk-tag-mode-none"><?= htmlspecialchars(hb_t('Keep'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="radio" class="btn-check" name="tag_mode" id="hb-bulk-tag-mode-add" value="add" form="hb-bulk-form">
              <label class="btn btn-outline-secondary" for="hb-bulk-tag-mode-add"><?= htmlspecialchars(hb_t('Add'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="radio" class="btn-check" name="tag_mode" id="hb-bulk-tag-mode-replace" value="replace" form="hb-bulk-form">
              <label class="btn btn-outline-secondary" for="hb-bulk-tag-mode-replace"><?= htmlspecialchars(hb_t('Replace'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="radio" class="btn-check" name="tag_mode" id="hb-bulk-tag-mode-clear" value="clear" form="hb-bulk-form">
              <label class="btn btn-outline-secondary" for="hb-bulk-tag-mode-clear"><?= htmlspecialchars(hb_t('Clear all'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            </div>
            <?php if (!$tags): ?>
              <div class="text-muted small"><?= htmlspecialchars(hb_t('No tags defined yet.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php else: ?>
              <div class="hb-bulk-tag-list">
                <?php foreach ($tags as $tag): ?>
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" form="hb-bulk-form" name="tag_ids[]" value="<?= (int)$tag['id'] ?>" id="hb-bulk-tag-<?= (int)$tag['id'] ?>">
                    <label class="form-check-label" for="hb-bulk-tag-<?= (int)$tag['id'] ?>">
                      <?= htmlspecialchars($tag['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </label>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= htmlspecialchars(hb_t('Cancel'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
          <button type="submit" class="btn btn-primary" form="hb-bulk-form"><?= htmlspecialchars(hb_t('Apply'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
        </div>
      </div>
    </div>
  </div>

  <style>
    .hb-bulk-col {
      width: 36px;
      padding-right: 0;
    }
    .hb-bulk-bar {
      position: fixed;
      left: 0;
      right: 0;
      bottom: 0;
      z-index: 1040;
      background: #0f172a;
      color: #f8fafc;
      box-shadow: 0 -8px 24px -8px rgba(15, 23, 42, 0.45);
      padding: 0.6rem 1rem calc(0.6rem + env(safe-area-inset-bottom, 0px));
    }
    .hb-bulk-bar-inner {
      max-width: 960px;
      margin: 0 auto;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
    }
    .hb-bulk-bar-count {
      font-weight: 600;
    }
    .hb-bulk-bar-count #hb-bulk-count {
      font-size: 1.1rem;
      margin-right: 0.35rem;
    }
    .hb-bulk-bar-label {
      opacity: 0.85;
      font-weight: 500;
    }
    .hb-bulk-bar-actions {
      display: flex;
      gap: 0.5rem;
      flex-wrap: wrap;
    }
    .hb-bulk-tag-list {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
      gap: 0.25rem 1rem;
      max-height: 240px;
      overflow-y: auto;
      padding: 0.25rem;
      border: 1px solid var(--bs-border-color);
      border-radius: 0.375rem;
    }
    body.hb-bulk-active {
      padding-bottom: 5rem;
    }
    @media (max-width: 575px) {
      .hb-bulk-bar-actions .btn {
        padding-inline: 0.6rem;
      }
    }
  </style>

  <script>
    (function () {
      const form = document.getElementById('hb-bulk-form');
      const bar = document.getElementById('hb-bulk-bar');
      const counter = document.getElementById('hb-bulk-count');
      const modalCounter = document.getElementById('hb-bulk-modal-count');
      const selectAll = document.getElementById('hb-bulk-select-all');
      const clearBtn = document.getElementById('hb-bulk-clear');
      if (!form || !bar) return;
      const checks = () => Array.from(document.querySelectorAll('.hb-bulk-check'));
      const selected = () => checks().filter((c) => c.checked);
      const update = () => {
        const n = selected().length;
        if (counter) counter.textContent = String(n);
        if (modalCounter) modalCounter.textContent = String(n);
        if (n > 0) {
          bar.hidden = false;
          document.body.classList.add('hb-bulk-active');
        } else {
          bar.hidden = true;
          document.body.classList.remove('hb-bulk-active');
        }
        if (selectAll) {
          const all = checks();
          selectAll.checked = all.length > 0 && all.every((c) => c.checked);
          selectAll.indeterminate = !selectAll.checked && all.some((c) => c.checked);
        }
      };
      document.addEventListener('change', (event) => {
        if (event.target && event.target.classList && event.target.classList.contains('hb-bulk-check')) {
          update();
        }
      });
      if (selectAll) {
        selectAll.addEventListener('change', () => {
          checks().forEach((c) => { c.checked = selectAll.checked; });
          update();
        });
      }
      if (clearBtn) {
        clearBtn.addEventListener('click', () => {
          checks().forEach((c) => { c.checked = false; });
          update();
        });
      }
      form.addEventListener('submit', (event) => {
        const cat = document.getElementById('hb-bulk-category');
        const mode = document.querySelector('input[name="tag_mode"]:checked');
        const hasCat = cat && cat.value !== '';
        const hasMode = mode && mode.value !== '';
        if (!hasCat && !hasMode) {
          event.preventDefault();
          alert('<?= htmlspecialchars(hb_t('Choose a category or tag mode first.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>');
        }
      });
      update();
    })();
  </script>

  <?php if (in_array($action, ['new', 'edit', 'show'], true)): ?>
    <?php ob_start(); ?>
          <?php if (!empty($conflict)): ?>
            <?= $conflict ?>
          <?php endif; ?>
          <?php
          $isEdit = $action === 'edit' && $transaction;
          $targetAction = $isEdit ? 'update' : 'store';
          ?>
          <h2 class="h6 mb-3"><?= htmlspecialchars($isEdit ? hb_t('Edit transaction') : hb_t('New transaction'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
          <?php if ($action === 'show' && $transaction): ?>
            <p><strong><?= htmlspecialchars(hb_t('Type'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>:</strong> <?= htmlspecialchars($typeLabels[$transaction['type']] ?? $transaction['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <p><strong><?= htmlspecialchars(hb_t('Amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>:</strong> <?= number_format($transaction['amount_cents'] / 100, 2, ',', '.') ?> €</p>
              <?php
              $detailAccount = $transaction['account_name'] ?? '-';
              if (($transaction['type'] ?? '') === 'transfer') {
                $fromName = $transaction['transfer_from_name'] ?? hb_t('Transfer from');
                $toName = $transaction['transfer_to_name'] ?? hb_t('Transfer to');
                $detailAccount = trim($fromName . ' → ' . $toName);
              }
              ?>
              <p><strong><?= htmlspecialchars(hb_t('Account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>:</strong> <?= htmlspecialchars($detailAccount, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <p><strong><?= htmlspecialchars(hb_t('Category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>:</strong> <?= htmlspecialchars($transaction['category_name'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php if (empty($transaction['planned_payment_id']) && !empty($transaction['suggested_planned_payment_id']) && !empty($transaction['suggested_plan_name'])): ?>
              <p><strong><?= htmlspecialchars(hb_t('Suggestion:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong> <?= htmlspecialchars($transaction['suggested_plan_date'] . ' · ' . $transaction['suggested_plan_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php elseif (!empty($transaction['planned_payment_id']) && !empty($transaction['planned_name'])): ?>
              <p><strong><?= htmlspecialchars(hb_t('Plan:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong> <?= htmlspecialchars($transaction['planned_date'] . ' · ' . $transaction['planned_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php endif; ?>
            <p><strong><?= htmlspecialchars(hb_t('Payee'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>:</strong> <?= htmlspecialchars($transaction['payee_name'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <p><strong><?= htmlspecialchars(hb_t('Note'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>:</strong> <?= nl2br(htmlspecialchars($transaction['note'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>
            <?php if ($transactionSplits): ?>
              <p class="mb-1"><strong><?= htmlspecialchars(hb_t('Splits'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>:</strong></p>
              <ul class="mb-2">
                <?php foreach ($transactionSplits as $sp): ?>
                  <li><?= htmlspecialchars($sp['category_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>: <?= number_format($sp['amount_cents'] / 100, 2, ',', '.') ?> €</li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <?php if ($transactionTags): ?>
              <p class="mb-1"><strong><?= htmlspecialchars(hb_t('Tags'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>:</strong></p>
              <ul class="mb-2">
                <?php foreach ($transactionTags as $tt): ?>
                  <li><?= htmlspecialchars($tt['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <?php if ($attachmentsForTx): ?>
              <p class="mb-1"><strong><?= htmlspecialchars(hb_t('Attachments'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>:</strong></p>
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
                  <label class="form-label"><?= htmlspecialchars(hb_t('Upload attachment (max 5MB)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <input type="file" class="form-control" name="attachment" required>
                </div>
                <button class="btn btn-sm btn-outline-primary" type="submit"><?= htmlspecialchars(hb_t('Upload'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
              </form>
              <form method="post" action="/transactions.php?action=link_existing_attachment" class="mt-2">
                <input type="hidden" name="action" value="link_existing_attachment">
                <input type="hidden" name="transaction_id" value="<?= (int)$transaction['id'] ?>">
                <div class="mb-2">
                  <label class="form-label"><?= htmlspecialchars(hb_t('Link existing receipt'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <select class="form-select" name="attachment_id" required>
                    <option value=""><?= htmlspecialchars(hb_t('Select attachment'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php
                    $availableAttStmt = $pdo->prepare('select id, original_filename, size_bytes, created_at from attachments where household_id = :hid and transaction_id is null order by created_at desc limit 200');
                    $availableAttStmt->execute(['hid' => $household['id']]);
                    foreach (($availableAttStmt->fetchAll() ?: []) as $availableAtt):
                    ?>
                      <option value="<?= (int)$availableAtt['id'] ?>">
                        #<?= (int)$availableAtt['id'] ?> · <?= htmlspecialchars((string)$availableAtt['original_filename'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · <?= number_format(((int)$availableAtt['size_bytes']) / 1024, 1, ',', '.') ?> KB
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <button class="btn btn-sm btn-outline-secondary" type="submit"><?= htmlspecialchars(hb_t('Link'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
              </form>
            </div>
            <a class="btn btn-sm btn-outline-secondary" href="/transactions.php?action=edit&id=<?= (int)$transaction['id'] ?>"><?= htmlspecialchars(hb_t('Edit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
            <form method="post" action="/transactions.php" class="d-inline" data-confirm="<?= htmlspecialchars(hb_t('Delete transaction?'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$transaction['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger"><?= htmlspecialchars(hb_t('Delete'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
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
                    <?= htmlspecialchars(hb_t('Type'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('Income/expense affect the account; transfers move between accounts. Amount is stored as positive cents.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
                  </label>
                  <select class="form-select" name="type">
                    <?php foreach (['income', 'expense', 'transfer'] as $t): ?>
                      <option value="<?= $t ?>" <?= ($transaction['type'] ?? '') === $t ? 'selected' : '' ?>>
                        <?= htmlspecialchars($typeLabels[$t] ?? $t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label"><?= htmlspecialchars(hb_t('Date'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <input type="date" class="form-control" name="booking_date" required value="<?= htmlspecialchars($transaction['booking_date'] ?? date('Y-m-d'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                </div>
              </div>
              <div class="mt-3">
                <label class="form-label">
                  <?= htmlspecialchars(hb_t('Amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('Please enter a positive amount; direction is derived from the type. Stored internally as cents.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
                </label>
                <input type="text" class="form-control" name="amount" required value="<?= isset($transaction['amount_cents']) ? number_format($transaction['amount_cents'] / 100, 2, ',', '.') : '' ?>" placeholder="<?= htmlspecialchars(hb_t('e.g. 12,34'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <div class="form-text"><?= htmlspecialchars(hb_t('Amount is stored as positive cents; type controls direction.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              </div>
              <div class="mt-3">
                <label class="form-label">
                  <?= htmlspecialchars(hb_t('Account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('Required for income/expense. Leave empty for transfers and use transfer accounts below.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
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
                    <?= htmlspecialchars(hb_t('Category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('Can be empty when using splits.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
                  </span>
                  <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-outline-secondary py-0 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#tx-splits"><?= htmlspecialchars(hb_t('Split'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                    <button class="btn btn-sm btn-outline-secondary py-0 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#category-inline">+ <?= htmlspecialchars(hb_t('New'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                  </div>
                </label>
                <?php
                $categorySelectorId = 'category-transaction';
                $categorySelectorName = 'category_id';
                $categorySelectorCategories = $categories;
                $categorySelectorSelected = $transaction['category_id'] ?? null;
                $categorySelectorPlaceholder = hb_t('Search category...');
                $categoryModalTarget = '#categoryModal';
                $categorySelectorShowAdd = false;
                require __DIR__ . '/../templates/partials/category_selector.php';
                ?>
                <?php if (empty($transaction['planned_payment_id']) && !empty($transaction['suggested_planned_payment_id']) && !empty($transaction['suggested_plan_name'])): ?>
                  <div class="small text-warning mt-1"><?= htmlspecialchars(hb_t('Suggestion:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars($transaction['suggested_plan_date'] . ' · ' . $transaction['suggested_plan_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <?php endif; ?>
                <div class="collapse mt-2" id="category-inline">
                  <div class="border rounded-3 p-2 bg-body-tertiary hb-inline-category">
                    <div class="row g-2 align-items-end">
                      <div class="col-md-7">
                        <label class="form-label small" for="category-inline-name"><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <input type="text" class="form-control form-control-sm" id="category-inline-name" data-category-field="name">
                        <div class="invalid-feedback"><?= htmlspecialchars(hb_t('Name is required.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                      </div>
                      <div class="col-md-5">
                        <label class="form-label small" for="category-inline-type"><?= htmlspecialchars(hb_t('Type'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <select class="form-select form-select-sm" id="category-inline-type" data-category-field="type">
                          <option value="expense"><?= htmlspecialchars(hb_t('Expense'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                          <option value="income"><?= htmlspecialchars(hb_t('Income'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                        </select>
                      </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2">
                      <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#category-inline"><?= htmlspecialchars(hb_t('Cancel'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                      <button type="button" class="btn btn-sm btn-primary hb-category-inline-save"><?= htmlspecialchars(hb_t('Save'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                    </div>
                  </div>
                </div>
              </div>
              <div class="mt-3">
                <?php $splitOpen = $transactionSplits ? 'show' : ''; ?>
                <div class="collapse <?= $splitOpen ?>" id="tx-splits">
                  <label class="form-label">
                    <?= htmlspecialchars(hb_t('Splits (optional)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('Distribute the amount across categories; total must match the amount.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
                  </label>
                  <?php for ($i = 0; $i < 3; $i++): ?>
                    <?php $existing = $transactionSplits[$i] ?? null; ?>
                    <div class="row g-2 mb-2">
                      <div class="col-7">
                        <select class="form-select form-select-sm" name="split_category_id[]">
                          <option value=""><?= htmlspecialchars(hb_t('Select category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
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
                  <div class="form-text"><?= htmlspecialchars(hb_t('Split total must match the amount.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                </div>
              </div>
              <div class="mt-3">
                <label class="form-label d-flex justify-content-between align-items-center">
                  <span>
                    <?= htmlspecialchars(hb_t('Payee'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('Payee/payer of the transaction. Optional.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
                  </span>
                  <button class="btn btn-sm btn-outline-secondary py-0 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#payee-inline" aria-expanded="false">+ <?= htmlspecialchars(hb_t('New'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                </label>
                <?php
                $payeeSelectorId = 'payee-transaction';
                $payeeSelectorName = 'payee_id';
                $payeeSelectorPayees = $payees;
                $payeeSelectorSelected = $transaction['payee_id'] ?? null;
                $payeeSelectorPlaceholder = hb_t('Search payee...');
                $payeeSelectorDisabled = false;
                $payeeSelectorReadonly = false;
                $payeeSelectorShowAdd = false;
                require __DIR__ . '/../templates/partials/payee_selector.php';
                ?>
                <div class="collapse mt-2" id="payee-inline">
                  <div class="border rounded-3 p-2 bg-body-tertiary hb-inline-payee">
                    <div class="row g-2">
                      <div class="col-md-6">
                        <label class="form-label small" for="payee-inline-name"><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <input type="text" class="form-control form-control-sm" id="payee-inline-name" data-payee-field="name">
                        <div class="invalid-feedback"><?= htmlspecialchars(hb_t('Name is required.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                      </div>
                      <div class="col-md-6">
                        <label class="form-label small" for="payee-inline-iban"><?= htmlspecialchars(hb_t('IBAN'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <input type="text" class="form-control form-control-sm" id="payee-inline-iban" data-payee-field="iban">
                      </div>
                    </div>
                    <div class="row g-2 mt-1">
                      <div class="col-md-6">
                        <label class="form-label small" for="payee-inline-address"><?= htmlspecialchars(hb_t('Address'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <textarea class="form-control form-control-sm" id="payee-inline-address" rows="2" data-payee-field="address_text"></textarea>
                      </div>
                      <div class="col-md-6">
                        <label class="form-label small" for="payee-inline-bic"><?= htmlspecialchars(hb_t('BIC'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <input type="text" class="form-control form-control-sm" id="payee-inline-bic" data-payee-field="bic">
                      </div>
                    </div>
                    <div class="mt-2">
                      <label class="form-label small" for="payee-inline-notes"><?= htmlspecialchars(hb_t('Notes'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                      <textarea class="form-control form-control-sm" id="payee-inline-notes" rows="2" data-payee-field="notes"></textarea>
                    </div>
                    <div class="mt-2 d-flex justify-content-end gap-2">
                      <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#payee-inline"><?= htmlspecialchars(hb_t('Cancel'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                      <button type="button" class="btn btn-sm btn-primary hb-payee-inline-save"><?= htmlspecialchars(hb_t('Save'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                    </div>
                  </div>
                </div>
              </div>
              <div class="mt-3">
                <label class="form-label d-flex justify-content-between align-items-center">
                  <span>
                    <?= htmlspecialchars(hb_t('Tags'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('Multi-select to group/filter transactions.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
                  </span>
                  <button class="btn btn-sm btn-outline-secondary py-0 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#tag-inline" aria-expanded="false">+ <?= htmlspecialchars(hb_t('New'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                </label>
                <?php
                $currentTags = array_map(fn($t) => (int)$t['tag_id'], $transactionTags);
                $tagSelectorId = 'tags-transaction';
                $tagSelectorName = 'tag_ids[]';
                $tagSelectorTags = $tags;
                $tagSelectorSelected = $currentTags;
                $tagSelectorPlaceholder = hb_t('Search tag...');
                $tagModalTarget = '#tagModal';
                $tagSelectorShowAdd = false;
                require __DIR__ . '/../templates/partials/tag_selector.php';
                ?>
                <div class="form-text"><?= htmlspecialchars(hb_t('Multiple selection possible.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <div class="collapse mt-2" id="tag-inline">
                  <div class="border rounded-3 p-2 bg-body-tertiary hb-inline-tag">
                    <div class="row g-2 align-items-end">
                      <div class="col-md-6">
                        <label class="form-label small" for="tag-inline-name"><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <input type="text" class="form-control form-control-sm" id="tag-inline-name" data-tag-field="name">
                        <div class="invalid-feedback"><?= htmlspecialchars(hb_t('Name is required.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                      </div>
                      <div class="col-md-4">
                        <label class="form-label small" for="tag-inline-color"><?= htmlspecialchars(hb_t('Color (hex)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <input type="text" class="form-control form-control-sm" id="tag-inline-color" data-tag-field="color" placeholder="#3a6ea5">
                      </div>
                      <div class="col-md-2">
                        <label class="form-label small" for="tag-inline-picker"><?= htmlspecialchars(hb_t('Picker'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <input type="color" class="form-control form-control-color w-100" id="tag-inline-picker" data-tag-field="color_picker" value="#3a6ea5">
                      </div>
                    </div>
                    <div class="mt-2 d-flex justify-content-end gap-2">
                      <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#tag-inline"><?= htmlspecialchars(hb_t('Cancel'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                      <button type="button" class="btn btn-sm btn-primary hb-tag-inline-save"><?= htmlspecialchars(hb_t('Save'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                    </div>
                  </div>
                </div>
              </div>
              <div class="mt-3">
                <label class="form-label"><?= htmlspecialchars(hb_t('Note'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <textarea class="form-control" name="note" rows="2"><?= htmlspecialchars($transaction['note'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
              </div>
              <div class="row g-3 mt-3">
                <div class="col-md-6">
                  <label class="form-label">
                    <?= htmlspecialchars(hb_t('Transfer from account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('Only for type "transfer"; account that is debited.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
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
                    <?= htmlspecialchars(hb_t('Transfer to account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('Only for type "transfer"; account that receives the credit.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
                  </label>
                  <select class="form-select" name="transfer_to_account_id">
                    <option value="">--</option>
                    <?php foreach ($accounts as $acc): ?>
                      <option value="<?= (int)$acc['id'] ?>" <?= ($transaction['transfer_to_account_id'] ?? null) == $acc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($acc['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <button type="submit" class="btn btn-success mt-3"><?= htmlspecialchars(hb_t('Save'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
            </form>
            <?php if (!empty($transaction['id']) && ($transaction['type'] ?? '') !== 'transfer'): ?>
              <?php
              $recurringName = $transaction['payee_name'] ?? $transaction['category_name'] ?? $transaction['note'] ?? hb_t('Recurring');
              ?>
              <div class="mt-4 border-top pt-3">
                <div class="d-flex justify-content-between align-items-center">
                  <h6 class="mb-0"><?= htmlspecialchars(hb_t('Save as recurring'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h6>
                  <button class="btn btn-sm btn-outline-secondary py-0 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#recurring-inline" aria-expanded="false"><?= htmlspecialchars(hb_t('Details'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
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
                        <label class="form-label small"><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <input type="text" class="form-control form-control-sm" name="recurring_name" value="<?= htmlspecialchars($recurringName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
                      </div>
                      <div class="col-6 col-md-2">
                        <label class="form-label small"><?= htmlspecialchars(hb_t('Interval'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <select class="form-select form-select-sm" name="recurring_interval_unit">
                          <option value="day"><?= htmlspecialchars(hb_t('Day'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                          <option value="week"><?= htmlspecialchars(hb_t('Week'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                          <option value="month" selected><?= htmlspecialchars(hb_t('Month'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                          <option value="year"><?= htmlspecialchars(hb_t('Year'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                        </select>
                      </div>
                      <div class="col-6 col-md-2">
                        <label class="form-label small"><?= htmlspecialchars(hb_t('Every'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <input type="number" class="form-control form-control-sm" name="recurring_interval_value" value="1" min="1">
                      </div>
                      <div class="col-md-2">
                        <label class="form-label small"><?= htmlspecialchars(hb_t('Start'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <input type="date" class="form-control form-control-sm" name="recurring_start_date" value="<?= htmlspecialchars($transaction['booking_date'] ?? date('Y-m-d'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                      </div>
                    </div>
                    <div class="row g-2 align-items-end mt-2">
                      <div class="col-md-4">
                        <label class="form-label small"><?= htmlspecialchars(hb_t('Amount logic'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <select class="form-select form-select-sm" name="recurring_amount_mode">
                          <option value="fixed" selected><?= htmlspecialchars(hb_t('Fixed'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                          <option value="tolerance"><?= htmlspecialchars(hb_t('Tolerance'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                          <option value="range"><?= htmlspecialchars(hb_t('Range'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                        </select>
                      </div>
                      <div class="col-md-4" data-recurring-group="tolerance">
                        <label class="form-label small"><?= htmlspecialchars(hb_t('Tolerance (amount)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <input type="text" class="form-control form-control-sm" name="recurring_tolerance_amount" placeholder="<?= htmlspecialchars(hb_t('e.g. 5,00'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                      </div>
                      <div class="col-md-4" data-recurring-group="tolerance">
                        <label class="form-label small"><?= htmlspecialchars(hb_t('Tolerance (%)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <input type="text" class="form-control form-control-sm" name="recurring_tolerance_pct" placeholder="<?= htmlspecialchars(hb_t('e.g. 5'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                      </div>
                    </div>
                    <div class="row g-2 align-items-end mt-2">
                      <div class="col-md-4" data-recurring-group="range">
                        <label class="form-label small"><?= htmlspecialchars(hb_t('Min amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <input type="text" class="form-control form-control-sm" name="recurring_min_amount" placeholder="<?= htmlspecialchars(hb_t('e.g. 40,00'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                      </div>
                      <div class="col-md-4" data-recurring-group="range">
                        <label class="form-label small"><?= htmlspecialchars(hb_t('Max amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <input type="text" class="form-control form-control-sm" name="recurring_max_amount" placeholder="<?= htmlspecialchars(hb_t('e.g. 60,00'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                      </div>
                      <div class="col-md-4">
                        <label class="form-label small"><?= htmlspecialchars(hb_t('End'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <input type="date" class="form-control form-control-sm" name="recurring_end_date">
                      </div>
                    </div>
                    <div class="row g-2 align-items-center mt-2">
                      <div class="col-6 col-md-3">
                        <label class="form-label small"><?= htmlspecialchars(hb_t('Priority'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <select class="form-select form-select-sm" name="recurring_priority">
                          <?php for ($p = 1; $p <= 5; $p++): ?>
                            <option value="<?= $p ?>" <?= $p === 3 ? 'selected' : '' ?>><?= $p ?></option>
                          <?php endfor; ?>
                        </select>
                      </div>
                      <div class="col-6 col-md-3">
                        <div class="form-check mt-4">
                          <input class="form-check-input" type="checkbox" name="recurring_is_optional" id="recurring-optional">
                          <label class="form-check-label small" for="recurring-optional"><?= htmlspecialchars(hb_t('Optional'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        </div>
                      </div>
                      <div class="col-md-6 text-end">
                        <button type="submit" class="btn btn-sm btn-primary"><?= htmlspecialchars(hb_t('Save recurring'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                      </div>
                    </div>
                  </form>
                </div>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        <?php
        $modalContent = ob_get_clean();
        $modalTitle = $action === 'show' ? hb_t('Transaction details') : ($action === 'edit' ? hb_t('Edit transaction') : hb_t('New transaction'));
        ?>
        <div class="modal fade" id="hb-transaction-modal" tabindex="-1" aria-labelledby="hb-transaction-modal-label" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="hb-transaction-modal-label"><?= htmlspecialchars($modalTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h5>
                <a href="/transactions.php" class="btn-close" aria-label="<?= htmlspecialchars(hb_t('Close'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></a>
              </div>
              <div class="modal-body">
                <?= $modalContent ?>
              </div>
            </div>
          </div>
        </div>
  <?php endif; ?>
</div>

<!-- Quick-Add FAB (mobile only) -->
<button class="d-md-none btn btn-success rounded-circle shadow position-fixed"
        id="hb-quick-add-fab"
        style="bottom:1.5rem;right:1.25rem;width:3.25rem;height:3.25rem;font-size:1.5rem;z-index:1040;line-height:1;"
        data-bs-toggle="modal" data-bs-target="#hb-quick-add-modal"
        aria-label="<?= htmlspecialchars(hb_t('Quick add transaction'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">+</button>

<!-- Quick-Add Modal -->
<div class="modal fade" id="hb-quick-add-modal" tabindex="-1" aria-labelledby="hb-quick-add-modal-label" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="hb-quick-add-modal-label"><?= htmlspecialchars(hb_t('Quick add'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" action="/transactions.php">
        <?= hb_csrf_field() ?>
        <input type="hidden" name="action" value="store">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label"><?= htmlspecialchars(hb_t('Type'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <div class="btn-group w-100" role="group">
              <input type="radio" class="btn-check" name="type" id="qa-type-expense" value="expense" checked>
              <label class="btn btn-outline-danger" for="qa-type-expense"><?= htmlspecialchars(hb_t('Expense'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="radio" class="btn-check" name="type" id="qa-type-income" value="income">
              <label class="btn btn-outline-success" for="qa-type-income"><?= htmlspecialchars(hb_t('Income'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            </div>
          </div>
          <div class="mb-3">
            <label for="qa-amount" class="form-label"><?= htmlspecialchars(hb_t('Amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <input type="text" class="form-control form-control-lg" id="qa-amount" name="amount" required
                   placeholder="<?= htmlspecialchars(hb_t('e.g. 12,34'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                   inputmode="decimal" autocomplete="off">
          </div>
          <div class="mb-3">
            <label for="qa-date" class="form-label"><?= htmlspecialchars(hb_t('Date'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <input type="date" class="form-control" id="qa-date" name="booking_date" required value="<?= date('Y-m-d') ?>">
          </div>
          <div class="mb-3">
            <label for="qa-account" class="form-label"><?= htmlspecialchars(hb_t('Account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <select class="form-select" id="qa-account" name="account_id" required>
              <option value=""><?= htmlspecialchars(hb_t('Select account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <?php foreach ($accounts as $acc): ?>
                <option value="<?= (int)$acc['id'] ?>" <?= (int)($quickDefault['account_id'] ?? 0) === (int)$acc['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($acc['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label for="qa-category" class="form-label"><?= htmlspecialchars(hb_t('Category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <select class="form-select" id="qa-category" name="category_id">
              <option value=""><?= htmlspecialchars(hb_t('No category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= (int)$cat['id'] ?>" <?= (int)($quickDefault['category_id'] ?? 0) === (int)$cat['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($cat['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label for="qa-payee" class="form-label"><?= htmlspecialchars(hb_t('Payee'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <span class="text-muted">(<?= htmlspecialchars(hb_t('optional'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)</span></label>
            <select class="form-select" id="qa-payee" name="payee_id">
              <option value=""><?= htmlspecialchars(hb_t('None'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <?php foreach ($payees as $pay): ?>
                <option value="<?= (int)$pay['id'] ?>" <?= (int)($quickDefault['payee_id'] ?? 0) === (int)$pay['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($pay['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-1">
            <label for="qa-note" class="form-label"><?= htmlspecialchars(hb_t('Note'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <span class="text-muted">(<?= htmlspecialchars(hb_t('optional'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)</span></label>
            <input type="text" class="form-control" id="qa-note" name="note" maxlength="500">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= htmlspecialchars(hb_t('Cancel'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
          <button type="submit" class="btn btn-success"><?= htmlspecialchars(hb_t('Save'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
        </div>
      </form>
    </div>
  </div>
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
  const toggleRecurringFields = (form) => {
    const mode = form.querySelector('select[name="recurring_amount_mode"]')?.value || 'fixed';
    const showTolerance = mode === 'tolerance';
    const showRange = mode === 'range';
    form.querySelectorAll('[data-recurring-group="tolerance"]').forEach((el) => {
      el.classList.toggle('d-none', !showTolerance);
    });
    form.querySelectorAll('[data-recurring-group="range"]').forEach((el) => {
      el.classList.toggle('d-none', !showRange);
    });
  };
  document.querySelectorAll('.hb-recurring-form').forEach((form) => {
    toggleRecurringFields(form);
    form.querySelector('select[name="recurring_amount_mode"]')?.addEventListener('change', () => toggleRecurringFields(form));
  });
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
