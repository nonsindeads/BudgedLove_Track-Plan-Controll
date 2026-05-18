<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

hb_require_login();
$pdo = hb_get_pdo();
$household = hb_require_household($pdo);
$currentHousehold = $household;
$currentUser = hb_current_user($pdo);

$pageTitle = 'Open bookings';
$activeNav = 'open_bookings';
$breadcrumbs = [
    ['label' => 'Open bookings', 'href' => '/open_bookings.php'],
];

$action = $_POST['action'] ?? 'list';
$msg = $_GET['msg'] ?? null;
$error = null;
$conflict = null;

if ($action === 'create_receipt_group' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $accountId = $_POST['group_account_id'] !== '' ? (int)($_POST['group_account_id'] ?? 0) : null;
    $payeeText = trim((string)($_POST['group_payee'] ?? ''));
    $bookingDate = trim((string)($_POST['group_booking_date'] ?? ''));
    $totalAmountCents = hb_parse_cents((string)($_POST['group_total_amount'] ?? ''));
    $groupType = (string)($_POST['group_type'] ?? 'expense');
    $note = trim((string)($_POST['group_note'] ?? ''));
    $attachmentId = (int)($_POST['group_existing_attachment_id'] ?? 0);
    $splitCats = $_POST['group_split_category_id'] ?? [];
    $splitAmounts = $_POST['group_split_amount'] ?? [];

    if (!$accountId) {
        $error = hb_t('Account is required.');
    } elseif ($bookingDate === '') {
        $error = hb_t('Date is required.');
    } elseif ($totalAmountCents === null || $totalAmountCents <= 0) {
        $error = hb_t('Amount must be greater than zero.');
    } elseif (!in_array($groupType, ['expense', 'income'], true)) {
        $error = hb_t('Invalid type.');
    }

    if ($error === null) {
        $accCheck = $pdo->prepare('select id from accounts where id = :id and household_id = :hid');
        $accCheck->execute(['id' => $accountId, 'hid' => $household['id']]);
        if (!$accCheck->fetch()) {
            $error = hb_t('Account does not belong to the household.');
        }
    }

    $splits = [];
    $splitSum = 0;
    if ($error === null) {
        $catCheck = $pdo->prepare('select id from categories where id = :id and household_id = :hid');
        foreach ($splitCats as $idx => $catIdRaw) {
            $catId = (int)$catIdRaw;
            $cents = hb_parse_cents((string)($splitAmounts[$idx] ?? ''));
            if ($catId && $cents !== null && $cents > 0) {
                $catCheck->execute(['id' => $catId, 'hid' => $household['id']]);
                if (!$catCheck->fetch()) {
                    $error = hb_t('Split category does not belong to the household.');
                    break;
                }
                $splits[] = [
                    'category_id' => $catId,
                    'amount_cents' => $cents,
                    'sort_order' => $idx,
                ];
                $splitSum += $cents;
            }
        }
        if ($error === null && !$splits) {
            $error = hb_t('At least one split is required.');
        } elseif ($error === null && $splitSum !== $totalAmountCents) {
            $error = hb_t('Split total must match the amount.');
        }
    }

    if ($error === null && $attachmentId > 0) {
        $attStmt = $pdo->prepare('select id from attachments where id = :id and household_id = :hid');
        $attStmt->execute(['id' => $attachmentId, 'hid' => $household['id']]);
        if (!$attStmt->fetch()) {
            $error = hb_t('Attachment not found.');
        }
    }

    if (
        $error === null
        && (!isset($_FILES['group_attachment']) || (int)($_FILES['group_attachment']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)
        && $attachmentId < 1
    ) {
        $error = hb_t('Please upload a receipt or link an existing attachment.');
    }

    if ($error === null) {
        $receiptId = null;
        $createdAttachmentId = null;
        $db = hb_dbal_household();
        try {
            $db->beginTransaction();
            $receiptId = hb_dbal_insert_and_get_id($db, 'receipts', [
                'household_id' => $household['id'],
                'merchant' => $payeeText !== '' ? $payeeText : null,
                'receipt_date' => $bookingDate,
                'total_amount_cents' => $totalAmountCents,
                'currency_code' => 'EUR',
                'status' => 'draft',
            ]);

            if (isset($_FILES['group_attachment']) && (int)($_FILES['group_attachment']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $file = $_FILES['group_attachment'];
                if ((int)$file['size'] > 10 * 1024 * 1024) {
                    throw new RuntimeException((string)hb_t('File too large (max 10MB).'));
                }
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
                $original = basename((string)$file['name']);
                $ext = pathinfo($original, PATHINFO_EXTENSION);
                $stored = bin2hex(random_bytes(8)) . ($ext ? '.' . preg_replace('/[^A-Za-z0-9.-]/', '', (string)$ext) : '');
                $dir = hb_ensure_upload_dir((int)$household['id']);
                $target = $dir . '/' . $stored;
                if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
                    throw new RuntimeException((string)hb_t('File could not be saved.'));
                }
                $relPath = $household['id'] . '/' . $stored;
                $createdAttachmentId = hb_dbal_insert_and_get_id($db, 'attachments', [
                    'household_id' => $household['id'],
                    'transaction_id' => null,
                    'receipt_id' => $receiptId,
                    'original_filename' => $original,
                    'stored_filename' => $stored,
                    'mime_type' => $mime,
                    'size_bytes' => (int)$file['size'],
                    'storage_path' => $relPath,
                ]);
                $db->update('receipts', [
                    'file_path' => $relPath,
                    'storage_key' => $relPath,
                    'mime_type' => $mime,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ], [
                    'id' => $receiptId,
                    'household_id' => $household['id'],
                ]);
            } elseif ($attachmentId > 0) {
                $db->update('attachments', [
                    'receipt_id' => $receiptId,
                ], [
                    'id' => $attachmentId,
                    'household_id' => $household['id'],
                ]);
            }

            $groupId = hb_dbal_insert_and_get_id($db, 'transaction_groups', [
                'household_id' => $household['id'],
                'receipt_id' => $receiptId,
                'account_id' => $accountId,
                'payee' => $payeeText !== '' ? $payeeText : null,
                'booking_date' => $bookingDate,
                'total_amount_cents' => $totalAmountCents,
                'currency_code' => 'EUR',
                'type' => $groupType,
                'notes' => $note !== '' ? $note : null,
                'status' => 'draft',
            ]);

            foreach ($splits as $split) {
                $db->insert('transaction_splits', [
                    'household_id' => $household['id'],
                    'transaction_group_id' => $groupId,
                    'transaction_id' => null,
                    'amount_cents' => $split['amount_cents'],
                    'category_id' => $split['category_id'],
                    'note' => null,
                    'sort_order' => $split['sort_order'],
                ]);
            }
            $db->commit();
            header('Location: /open_bookings.php?msg=receipt_group_saved');
            exit;
        } catch (Throwable $e) {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }
            if ($createdAttachmentId !== null) {
                // Attachment row was inside transaction. If rollback happened, it is already gone.
            }
            $error = $e instanceof RuntimeException ? $e->getMessage() : hb_t('Receipt draft could not be created.');
        }
    }
}

if ($action === 'upload_attachment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $txId = (int)($_POST['transaction_id'] ?? 0);
    $txCheck = $pdo->prepare('select id from transactions where id = :id and household_id = :hid and is_reviewed = false');
    $txCheck->execute(['id' => $txId, 'hid' => $household['id']]);
    if (!$txCheck->fetch()) {
        $error = hb_t('Booking not found or already reviewed.');
    } elseif (!isset($_FILES['attachment']) || $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
        $error = hb_t('Upload failed.');
    } else {
        $file = $_FILES['attachment'];
        if ((int)$file['size'] > 5 * 1024 * 1024) {
            $error = hb_t('File too large (max 5MB).');
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
            $original = basename((string)$file['name']);
            $ext = pathinfo($original, PATHINFO_EXTENSION);
            $stored = bin2hex(random_bytes(8)) . ($ext ? '.' . preg_replace('/[^A-Za-z0-9.-]/', '', (string)$ext) : '');
            $dir = hb_ensure_upload_dir((int)$household['id']);
            $target = $dir . '/' . $stored;
            if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
                $error = hb_t('File could not be saved.');
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
                header('Location: /open_bookings.php?msg=attachment_saved');
                exit;
            }
        }
    }
}

if ($action === 'link_existing_attachment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $txId = (int)($_POST['transaction_id'] ?? 0);
    $attachmentId = (int)($_POST['attachment_id'] ?? 0);
    $txCheck = $pdo->prepare('select id from transactions where id = :id and household_id = :hid and is_reviewed = false');
    $txCheck->execute(['id' => $txId, 'hid' => $household['id']]);
    if (!$txCheck->fetch()) {
        $error = hb_t('Booking not found or already reviewed.');
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
            header('Location: /open_bookings.php?msg=attachment_linked');
            exit;
        }
    }
}

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $finalize = isset($_POST['finalize']) && $_POST['finalize'] === '1';
    $txId = (int)($_POST['transaction_id'] ?? 0);
    $rowVersion = (int)($_POST['row_version'] ?? 0);
    $type = (string)($_POST['type'] ?? '');
    $accountId = $_POST['account_id'] !== '' ? (int)($_POST['account_id'] ?? 0) : null;
    $categoryId = $_POST['category_id'] !== '' ? (int)($_POST['category_id'] ?? 0) : null;
    $payeeId = $_POST['payee_id'] !== '' ? (int)($_POST['payee_id'] ?? 0) : null;
    $plannedPaymentId = $_POST['planned_payment_id'] !== '' ? (int)($_POST['planned_payment_id'] ?? 0) : null;
    $note = trim((string)($_POST['note'] ?? ''));
    $tagIdsRaw = $_POST['tag_ids'] ?? [];
    $tagIds = hb_normalize_id_list(is_array($tagIdsRaw) ? $tagIdsRaw : [$tagIdsRaw]);
    $splitCats = $_POST['split_category_id'] ?? [];
    $splitAmounts = $_POST['split_amount'] ?? [];
    $transferFrom = $_POST['transfer_from_account_id'] !== '' ? (int)($_POST['transfer_from_account_id'] ?? 0) : null;
    $transferTo = $_POST['transfer_to_account_id'] !== '' ? (int)($_POST['transfer_to_account_id'] ?? 0) : null;

    $txCheck = $pdo->prepare('select id, amount_cents, counterparty_name from transactions where id = :id and household_id = :hid and is_reviewed = false');
    $txCheck->execute(['id' => $txId, 'hid' => $household['id']]);
    $txRow = $txCheck->fetch();
    if (!$txRow) {
        $error = hb_t('Booking not found or already reviewed.');
    }
    if ($error === null && $type === '') {
        $type = (string)($txRow['type'] ?? 'expense');
    }
    if ($error === null && !in_array($type, ['income', 'expense', 'transfer'], true)) {
        $error = hb_t('Invalid type.');
    }

    if ($error === null && $type !== 'transfer') {
        if (!$accountId) {
            $error = hb_t('Account is required.');
        } else {
            $accCheck = $pdo->prepare('select id from accounts where id = :id and household_id = :hid');
            $accCheck->execute(['id' => $accountId, 'hid' => $household['id']]);
            if (!$accCheck->fetch()) {
                $error = hb_t('Account does not belong to the household.');
            }
        }
    }

    if ($error === null && $type !== 'transfer' && $categoryId !== null) {
        $catCheck = $pdo->prepare('select id from categories where id = :id and household_id = :hid');
        $catCheck->execute(['id' => $categoryId, 'hid' => $household['id']]);
        if (!$catCheck->fetch()) {
            $error = hb_t('Category does not belong to the household.');
        }
    }
    if ($error === null && $type !== 'transfer' && $payeeId !== null) {
        $payeeCheck = $pdo->prepare('select id from payees where id = :id and household_id = :hid');
        $payeeCheck->execute(['id' => $payeeId, 'hid' => $household['id']]);
        if (!$payeeCheck->fetch()) {
            $error = hb_t('Payee does not belong to the household.');
        }
    }
    if ($error === null && $type !== 'transfer' && $plannedPaymentId !== null) {
        $planCheck = $pdo->prepare('select id from planned_payments where id = :id and household_id = :hid');
        $planCheck->execute(['id' => $plannedPaymentId, 'hid' => $household['id']]);
        if (!$planCheck->fetch()) {
            $error = hb_t('Planned payment does not belong to the household.');
        }
    }
    if ($error === null && $tagIds) {
        $tagCheck = $pdo->prepare('select id from tags where id = :id and household_id = :hid');
        foreach ($tagIds as $tagId) {
            $tagCheck->execute(['id' => $tagId, 'hid' => $household['id']]);
            if (!$tagCheck->fetch()) {
                $error = hb_t('Tag does not belong to the household.');
                break;
            }
        }
    }

    $splits = [];
    $splitSum = 0;
    if ($error === null && $type !== 'transfer') {
        $catCheck = $pdo->prepare('select id from categories where id = :id and household_id = :hid');
        foreach ($splitCats as $idx => $catIdRaw) {
            $catId = (int)$catIdRaw;
            $cents = hb_parse_cents((string)($splitAmounts[$idx] ?? ''));
            if ($catId && $cents !== null && $cents > 0) {
                $catCheck->execute(['id' => $catId, 'hid' => $household['id']]);
                if (!$catCheck->fetch()) {
                    $error = hb_t('Split category does not belong to the household.');
                    break;
                }
                $splits[] = ['category_id' => $catId, 'amount_cents' => $cents];
                $splitSum += $cents;
            }
        }
        if ($error === null && $splits && $splitSum !== (int)$txRow['amount_cents']) {
            $error = hb_t('Split total must match the amount.');
        }
    }

    if ($error === null && $type !== 'transfer' && !$splits && $categoryId === null) {
        $error = hb_t('Category is required.');
    }

    if ($error === null && $type === 'transfer') {
        if (!$transferFrom || !$transferTo) {
            $error = hb_t('Source and target accounts are required.');
        } elseif ($transferFrom === $transferTo) {
            $error = hb_t('Transfer requires two different accounts.');
        } else {
            $accCheck = $pdo->prepare('select id from accounts where id = :id and household_id = :hid');
            $accCheck->execute(['id' => $transferFrom, 'hid' => $household['id']]);
            if (!$accCheck->fetch()) {
                $error = hb_t('Invalid transfer source account.');
            }
            if ($error === null) {
                $accCheck->execute(['id' => $transferTo, 'hid' => $household['id']]);
                if (!$accCheck->fetch()) {
                    $error = hb_t('Invalid transfer target account.');
                }
            }
        }
    }

    if ($error === null) {
        $categoryId = $type === 'transfer' ? null : $categoryId;
        $payeeId = $type === 'transfer' ? null : $payeeId;
        $plannedPaymentId = $type === 'transfer' ? null : $plannedPaymentId;
        $stmt = $pdo->prepare(
            'update transactions
                set type = :type,
                    account_id = :account_id,
                    category_id = :category_id,
                    payee_id = :payee_id,
                    planned_payment_id = :planned_payment_id,
                    note = :note,
                    transfer_from_account_id = :transfer_from,
                    transfer_to_account_id = :transfer_to,
                    is_reviewed = :is_reviewed,
                    suggested_payee_id = null,
                    suggested_planned_payment_id = null,
                    updated_at = :updated_at
              where id = :id and household_id = :hid and row_version = :row_version and is_reviewed = false'
        );
        $stmt->execute([
            'type' => $type,
            'account_id' => $type === 'transfer' ? null : $accountId,
            'category_id' => $categoryId,
            'payee_id' => $payeeId,
            'planned_payment_id' => $plannedPaymentId,
            'note' => $note !== '' ? $note : null,
            'transfer_from' => $type === 'transfer' ? $transferFrom : null,
            'transfer_to' => $type === 'transfer' ? $transferTo : null,
            'is_reviewed' => $finalize ? true : false,
            'updated_at' => gmdate('Y-m-d H:i:s'),
            'id' => $txId,
            'hid' => $household['id'],
            'row_version' => $rowVersion,
        ]);
        if ($stmt->rowCount() === 0) {
            $fresh = $pdo->prepare('select * from transactions where id = :id and household_id = :hid');
            $fresh->execute(['id' => $txId, 'hid' => $household['id']]);
            $current = $fresh->fetch() ?: [];
            $conflictRows = hb_build_conflict_rows(
                [
                    'type' => hb_t('Type'),
                    'account_id' => hb_t('Account'),
                    'category_id' => hb_t('Category'),
                    'payee_id' => hb_t('Payee'),
                    'planned_payment_id' => hb_t('Planned payment'),
                    'note' => hb_t('Note'),
                    'transfer_from_account_id' => hb_t('Transfer from'),
                    'transfer_to_account_id' => hb_t('Transfer to'),
                ],
                $current,
                [
                    'type' => (string)$type,
                    'account_id' => (string)($accountId ?? ''),
                    'category_id' => (string)($categoryId ?? ''),
                    'payee_id' => (string)($payeeId ?? ''),
                    'planned_payment_id' => (string)($plannedPaymentId ?? ''),
                    'note' => $note,
                    'transfer_from_account_id' => (string)($transferFrom ?? ''),
                    'transfer_to_account_id' => (string)($transferTo ?? ''),
                ]
            );
            $conflict = hb_render_conflict_table($conflictRows);
        } else {
            $pdo->prepare('delete from transaction_splits where transaction_id = :id')->execute(['id' => $txId]);
            foreach ($splits as $split) {
                $ins = $pdo->prepare(
                    'insert into transaction_splits (transaction_id, category_id, amount_cents, note)
                     values (:tid, :cid, :amount, null)'
                );
                $ins->execute([
                    'tid' => $txId,
                    'cid' => $split['category_id'],
                    'amount' => $split['amount_cents'],
                ]);
            }
            $pdo->prepare('delete from transaction_tags where transaction_id = :id')->execute(['id' => $txId]);
            foreach ($tagIds as $tagId) {
                $pdo->prepare('insert into transaction_tags (transaction_id, tag_id) values (:tid, :tag)')
                    ->execute(['tid' => $txId, 'tag' => $tagId]);
            }
            if ($finalize && $plannedPaymentId !== null) {
                $planUpdate = $pdo->prepare(
                    "update planned_payments
                        set status = 'done',
                            resolved_at = :resolved_at,
                            resolved_transaction_id = :tx_id,
                            updated_at = :updated_at
                      where id = :id and household_id = :hid"
                );
                $planUpdate->execute([
                    'tx_id' => $txId,
                    'resolved_at' => gmdate('Y-m-d H:i:s'),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                    'id' => $plannedPaymentId,
                    'hid' => $household['id'],
                ]);
            }
            $counterpartyName = trim((string)($txRow['counterparty_name'] ?? ''));
            if ($finalize && $type !== 'transfer' && $counterpartyName !== '') {
                $mappingUpsert = $pdo->prepare(
                    'insert into payee_mappings (household_id, counterparty_name, payee_id, category_id, tag_ids)
                     values (:hid, :counterparty_name, :payee_id, :category_id, :tag_ids)
                     on conflict (household_id, counterparty_name) do update
                        set payee_id = excluded.payee_id,
                            category_id = excluded.category_id,
                            tag_ids = excluded.tag_ids,
                            updated_at = :updated_at'
                );
                $mappingUpsert->execute([
                    'hid' => $household['id'],
                    'counterparty_name' => $counterpartyName,
                    'payee_id' => $payeeId,
                    'category_id' => $categoryId,
                    'tag_ids' => hb_php_int_array_to_pg($tagIds),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }
            header('Location: /open_bookings.php?msg=' . ($finalize ? 'saved' : 'saved_draft'));
            exit;
        }
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
    $amountMode = (string)($_POST['recurring_amount_mode'] ?? 'fixed');
    $toleranceAmount = hb_parse_cents((string)($_POST['recurring_tolerance_amount'] ?? ''));
    $tolerancePctRaw = trim((string)($_POST['recurring_tolerance_pct'] ?? ''));
    $tolerancePct = $tolerancePctRaw !== '' ? (float)str_replace(',', '.', $tolerancePctRaw) : null;
    $minAmount = hb_parse_cents((string)($_POST['recurring_min_amount'] ?? ''));
    $maxAmount = hb_parse_cents((string)($_POST['recurring_max_amount'] ?? ''));
    $categoryOverride = $_POST['recurring_category_id'] !== '' ? (int)($_POST['recurring_category_id'] ?? 0) : null;
    $payeeOverride = $_POST['recurring_payee_id'] !== '' ? (int)($_POST['recurring_payee_id'] ?? 0) : null;
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
        $error = hb_t('Booking not found.');
    }

    if ($error === null) {
        $direction = (string)$txRow['type'];
        if (!in_array($direction, ['income', 'expense'], true)) {
            $error = hb_t('Recurring is only allowed for income/expense.');
        }
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

    $categoryId = $categoryOverride ?? $txRow['category_id'];
    $payeeId = $payeeOverride ?? $txRow['payee_id'];
    $note = $noteOverride !== '' ? $noteOverride : ($txRow['note'] ?? null);

    if ($categoryId) {
        $catCheck = $pdo->prepare('select id from categories where id = :id and household_id = :hid');
        $catCheck->execute(['id' => $categoryId, 'hid' => $household['id']]);
        if (!$catCheck->fetch()) {
            $error = hb_t('Category does not belong to the household.');
        }
    }
    if ($error === null && $payeeId) {
        $payeeCheck = $pdo->prepare('select id from payees where id = :id and household_id = :hid');
        $payeeCheck->execute(['id' => $payeeId, 'hid' => $household['id']]);
        if (!$payeeCheck->fetch()) {
            $error = hb_t('Payee does not belong to the household.');
        }
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
            'direction' => $txRow['type'],
            'amount' => (int)$txRow['amount_cents'],
            'unit' => $intervalUnit,
            'ival' => $intervalValue,
            'start_date' => $startDate,
            'end_date' => $endDate !== '' ? $endDate : null,
            'priority' => $priority,
            'is_optional' => $isOptional ? 1 : 0,
            'account_id' => $txRow['account_id'],
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

        header('Location: /open_bookings.php?msg=recurring_saved');
        exit;
    }
}

$accountsStmt = $pdo->prepare('select id, name from accounts where household_id = :hid order by name asc');
$accountsStmt->execute(['hid' => $household['id']]);
$accounts = $accountsStmt->fetchAll();

$categories = $pdo->prepare(
    'select id, name, type from categories where household_id = :hid and is_active = true order by name asc'
);
$categories->execute(['hid' => $household['id']]);
$categories = $categories->fetchAll();

$tagsStmt = $pdo->prepare('select id, name, color from tags where household_id = :hid and is_active = true order by name asc');
$tagsStmt->execute(['hid' => $household['id']]);
$tags = $tagsStmt->fetchAll();

$payeesStmt = $pdo->prepare('select id, name from payees where household_id = :hid order by name asc');
$payeesStmt->execute(['hid' => $household['id']]);
$payees = $payeesStmt->fetchAll();

$plansStmt = $pdo->prepare(
    "select *
       from planned_payments
      where household_id = :hid and status in ('open', 'overdue', 'suggested')
      order by planned_date asc, id asc"
);
$plansStmt->execute(['hid' => $household['id']]);
$plans = $plansStmt->fetchAll();
$planById = [];
$recurringById = [];
if ($plans) {
    foreach ($plans as $plan) {
        $planById[(int)$plan['id']] = $plan;
    }
    $recurringIds = array_values(array_unique(array_filter(array_map(
        fn($row) => (int)($row['recurring_payment_id'] ?? 0),
        $plans
    ))));
    if ($recurringIds) {
        $in = implode(',', array_fill(0, count($recurringIds), '?'));
        $recStmt = $pdo->prepare("select * from recurring_payments where id in ({$in})");
        $recStmt->execute($recurringIds);
        foreach ($recStmt->fetchAll() as $rec) {
            $recurringById[(int)$rec['id']] = $rec;
        }
    }
}

$txStmt = $pdo->prepare(
    'select t.*, a.name as account_name, p.name as payee_name, sp.name as suggested_payee_name
       from transactions t
       left join accounts a on a.id = t.account_id
       left join payees p on p.id = t.payee_id
       left join payees sp on sp.id = t.suggested_payee_id
      where t.household_id = :hid and t.is_reviewed = false
      order by t.booking_date desc, t.id desc'
);
$txStmt->execute(['hid' => $household['id']]);
$openBookings = $txStmt->fetchAll();

$suggestedPlans = [];
if ($openBookings && $plans) {
    foreach ($openBookings as $tx) {
        if (!empty($tx['planned_payment_id']) || !empty($tx['suggested_planned_payment_id'])) {
            continue;
        }
        $suggested = hb_suggest_planned_payment($plans, $recurringById, $tx);
        if ($suggested) {
            $suggestedPlans[(int)$tx['id']] = (int)$suggested['id'];
        }
    }
}

$txTags = [];
if ($openBookings) {
    $ids = array_map(fn($row) => (int)$row['id'], $openBookings);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $tagStmt = $pdo->prepare("select transaction_id, tag_id from transaction_tags where transaction_id in ({$in})");
    $tagStmt->execute($ids);
    foreach ($tagStmt->fetchAll() as $row) {
        $txTags[(int)$row['transaction_id']][] = (int)$row['tag_id'];
    }
}

$txSplits = [];
if ($openBookings) {
    $ids = array_map(fn($row) => (int)$row['id'], $openBookings);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $splitStmt = $pdo->prepare("select * from transaction_splits where transaction_id in ({$in}) order by id asc");
    $splitStmt->execute($ids);
    foreach ($splitStmt->fetchAll() as $row) {
        $txSplits[(int)$row['transaction_id']][] = $row;
    }
}

$txAttachments = [];
if ($openBookings) {
    $ids = array_map(fn($row) => (int)$row['id'], $openBookings);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $attStmt = $pdo->prepare("select id, transaction_id, original_filename, size_bytes from attachments where transaction_id in ({$in}) order by created_at desc");
    $attStmt->execute($ids);
    foreach ($attStmt->fetchAll() as $row) {
        $txAttachments[(int)$row['transaction_id']][] = $row;
    }
}

$availableAttachmentsStmt = $pdo->prepare(
    'select id, original_filename, size_bytes, created_at
       from attachments
      where household_id = :hid and transaction_id is null
      order by created_at desc
      limit 200'
);
$availableAttachmentsStmt->execute(['hid' => $household['id']]);
$availableAttachments = $availableAttachmentsStmt->fetchAll() ?: [];

$groupDraftsStmt = $pdo->prepare(
    "select tg.id, tg.booking_date, tg.payee, tg.total_amount_cents, tg.type, tg.status, a.name as account_name, r.id as receipt_id
       from transaction_groups tg
  left join accounts a on a.id = tg.account_id
  left join receipts r on r.id = tg.receipt_id
      where tg.household_id = :hid and tg.status = 'draft'
      order by tg.created_at desc
      limit 20"
);
$groupDraftsStmt->execute(['hid' => $household['id']]);
$groupDrafts = $groupDraftsStmt->fetchAll() ?: [];

ob_start();
?>
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h4 mb-0"><?= htmlspecialchars(hb_t('Open bookings'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
      <div class="text-muted small"><?= htmlspecialchars(hb_t('Review, assign, and finalize new transactions.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    </div>
    <div class="text-muted small"><?= htmlspecialchars(hb_t('Open:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= count($openBookings) ?></div>
  </div>

  <?php if ($msg === 'saved'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Booking finalized.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php elseif ($msg === 'saved_draft'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Booking draft saved.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php elseif ($msg === 'recurring_saved'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Recurring payment created.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php elseif ($msg === 'attachment_saved'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Attachment saved.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php elseif ($msg === 'attachment_linked'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Attachment linked.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php elseif ($msg === 'receipt_group_saved'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Receipt split draft saved.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>
  <?= $conflict ?>

  <div class="row g-4">
    <div class="col-12">
      <div class="card shadow-sm">
        <div class="card-body">
          <h2 class="h6 mb-3"><?= htmlspecialchars(hb_t('New receipt split draft'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
          <form method="post" action="/open_bookings.php" enctype="multipart/form-data" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="create_receipt_group">
            <div class="col-md-2">
              <label class="form-label small"><?= htmlspecialchars(hb_t('Type'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <select class="form-select form-select-sm" name="group_type">
                <option value="expense"><?= htmlspecialchars(hb_t('Expense'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <option value="income"><?= htmlspecialchars(hb_t('Income'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label small"><?= htmlspecialchars(hb_t('Account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <select class="form-select form-select-sm" name="group_account_id" required>
                <option value=""><?= htmlspecialchars(hb_t('Account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <?php foreach ($accounts as $acc): ?>
                  <option value="<?= (int)$acc['id'] ?>"><?= htmlspecialchars($acc['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label small"><?= htmlspecialchars(hb_t('Payee'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="text" class="form-control form-control-sm" name="group_payee" placeholder="<?= htmlspecialchars(hb_t('e.g. EDEKA'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
            <div class="col-md-2">
              <label class="form-label small"><?= htmlspecialchars(hb_t('Date'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="date" class="form-control form-control-sm" name="group_booking_date" value="<?= htmlspecialchars(gmdate('Y-m-d'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
            </div>
            <div class="col-md-2">
              <label class="form-label small"><?= htmlspecialchars(hb_t('Total amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="text" class="form-control form-control-sm" name="group_total_amount" placeholder="0,00" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small"><?= htmlspecialchars(hb_t('Upload receipt (max 10MB)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="file" class="form-control form-control-sm" name="group_attachment">
            </div>
            <div class="col-md-6">
              <label class="form-label small"><?= htmlspecialchars(hb_t('Or link existing receipt'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <select class="form-select form-select-sm" name="group_existing_attachment_id">
                <option value=""><?= htmlspecialchars(hb_t('Select attachment'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <?php foreach ($availableAttachments as $availableAtt): ?>
                  <option value="<?= (int)$availableAtt['id'] ?>">
                    #<?= (int)$availableAtt['id'] ?> · <?= htmlspecialchars((string)$availableAtt['original_filename'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php for ($i = 0; $i < 5; $i++): ?>
              <div class="col-md-6">
                <label class="form-label small"><?= htmlspecialchars(hb_t('Split category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= $i + 1 ?></label>
                <select class="form-select form-select-sm" name="group_split_category_id[]">
                  <option value=""><?= htmlspecialchars(hb_t('Select category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label small"><?= htmlspecialchars(hb_t('Split amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= $i + 1 ?></label>
                <input type="text" class="form-control form-control-sm" name="group_split_amount[]" placeholder="0,00">
              </div>
            <?php endfor; ?>
            <div class="col-12">
              <label class="form-label small"><?= htmlspecialchars(hb_t('Note'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="text" class="form-control form-control-sm" name="group_note">
              <div class="form-text"><?= htmlspecialchars(hb_t('A receipt is stored once and linked to the full split group.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            </div>
            <div class="col-12 text-end">
              <button type="submit" class="btn btn-primary btn-sm"><?= htmlspecialchars(hb_t('Save receipt split draft'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php if ($groupDrafts): ?>
      <div class="col-12">
        <div class="card shadow-sm">
          <div class="card-body">
            <h2 class="h6 mb-3"><?= htmlspecialchars(hb_t('Latest receipt split drafts'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <div class="table-responsive">
              <table class="table table-sm mb-0">
                <thead>
                  <tr>
                    <th>#</th>
                    <th><?= htmlspecialchars(hb_t('Date'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars(hb_t('Payee'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars(hb_t('Account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars(hb_t('Amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars(hb_t('Type'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($groupDrafts as $group): ?>
                    <tr>
                      <td><?= (int)$group['id'] ?></td>
                      <td><?= htmlspecialchars((string)$group['booking_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                      <td><?= htmlspecialchars((string)($group['payee'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                      <td><?= htmlspecialchars((string)($group['account_name'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                      <td><?= number_format(((int)$group['total_amount_cents']) / 100, 2, ',', '.') ?> €</td>
                      <td><?= htmlspecialchars((string)$group['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
    <div class="col-12">
      <?php if (!$openBookings): ?>
        <div class="card shadow-sm">
          <div class="card-body text-muted"><?= htmlspecialchars(hb_t('No open bookings available.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        </div>
      <?php endif; ?>

      <?php foreach ($openBookings as $tx): ?>
        <?php
        $selectedPayee = $tx['payee_id'] ?? null;
        if (!$selectedPayee && !empty($tx['suggested_payee_id'])) {
            $selectedPayee = $tx['suggested_payee_id'];
        }
        $suggestedPlanId = $tx['suggested_planned_payment_id'] ?? ($suggestedPlans[(int)$tx['id']] ?? null);
        $selectedPlanId = $tx['planned_payment_id'] ?? $suggestedPlanId;
        $selectedTags = $txTags[(int)$tx['id']] ?? [];
        $splitRows = $txSplits[(int)$tx['id']] ?? [];
        $attachmentsForTx = $txAttachments[(int)$tx['id']] ?? [];
        $selectedType = (string)($tx['type'] ?? 'expense');
        $transferFromSelected = $tx['transfer_from_account_id'] ?? null;
        $transferToSelected = $tx['transfer_to_account_id'] ?? null;
        if (!$transferFromSelected && !$transferToSelected && !empty($tx['account_id'])) {
            if ($selectedType === 'expense') {
                $transferFromSelected = $tx['account_id'];
            } elseif ($selectedType === 'income') {
                $transferToSelected = $tx['account_id'];
            }
        }
        $directionBadge = $tx['type'] === 'income' ? 'bg-success' : 'bg-danger';
        ?>
        <div class="card shadow-sm mb-3">
          <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start mb-2">
              <div>
                <div class="fw-semibold"><?= htmlspecialchars($tx['counterparty_name'] ?: hb_t('Unknown payee'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <div class="text-muted small">
                  <?= htmlspecialchars($tx['booking_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  · <?= htmlspecialchars($tx['account_name'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </div>
              </div>
              <div class="text-end">
                <div class="fw-semibold"><?= number_format($tx['amount_cents'] / 100, 2, ',', '.') ?> €</div>
                <span class="badge <?= $directionBadge ?>">
                  <?= htmlspecialchars($tx['type'] === 'income' ? hb_t('Income') : ($tx['type'] === 'expense' ? hb_t('Expense') : (string)$tx['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </span>
              </div>
            </div>

            <?php if (!empty($tx['note'])): ?>
              <div class="text-muted small mb-3"><?= htmlspecialchars($tx['note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($attachmentsForTx): ?>
              <div class="mb-2">
                <div class="small fw-semibold"><?= htmlspecialchars(hb_t('Attachments'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <ul class="mb-2">
                  <?php foreach ($attachmentsForTx as $att): ?>
                    <li>
                      <a href="/attachments.php?action=download&id=<?= (int)$att['id'] ?>">
                        <?= htmlspecialchars((string)$att['original_filename'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      </a>
                      <span class="text-muted small">(<?= number_format(((int)$att['size_bytes']) / 1024, 1, ',', '.') ?> KB)</span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>

            <div class="border rounded p-2 bg-light mb-3">
              <form method="post" action="/open_bookings.php" enctype="multipart/form-data" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="upload_attachment">
                <input type="hidden" name="transaction_id" value="<?= (int)$tx['id'] ?>">
                <div class="col-md-8">
                  <label class="form-label small"><?= htmlspecialchars(hb_t('Upload attachment (max 5MB)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <input type="file" class="form-control form-control-sm" name="attachment" required>
                </div>
                <div class="col-md-4 text-md-end">
                  <button class="btn btn-sm btn-outline-primary" type="submit"><?= htmlspecialchars(hb_t('Upload'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                </div>
              </form>
              <form method="post" action="/open_bookings.php" class="row g-2 align-items-end mt-1">
                <input type="hidden" name="action" value="link_existing_attachment">
                <input type="hidden" name="transaction_id" value="<?= (int)$tx['id'] ?>">
                <div class="col-md-8">
                  <label class="form-label small"><?= htmlspecialchars(hb_t('Link existing receipt'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <select class="form-select form-select-sm" name="attachment_id" required>
                    <option value=""><?= htmlspecialchars(hb_t('Select attachment'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php foreach ($availableAttachments as $availableAtt): ?>
                      <option value="<?= (int)$availableAtt['id'] ?>">
                        #<?= (int)$availableAtt['id'] ?> · <?= htmlspecialchars((string)$availableAtt['original_filename'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · <?= number_format(((int)$availableAtt['size_bytes']) / 1024, 1, ',', '.') ?> KB
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-4 text-md-end">
                  <button class="btn btn-sm btn-outline-secondary" type="submit"><?= htmlspecialchars(hb_t('Link'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                </div>
              </form>
            </div>

            <?php if (!empty($tx['suggested_payee_name'])): ?>
              <div class="badge bg-info-subtle text-info mb-2"><?= htmlspecialchars(hb_t('Suggestion:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars($tx['suggested_payee_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($suggestedPlanId && empty($tx['planned_payment_id']) && isset($planById[(int)$suggestedPlanId])): ?>
              <div class="alert alert-warning py-1 px-2 small mb-2 d-flex align-items-center justify-content-between">
                <span><?= htmlspecialchars(hb_t('Suggestion:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars($planById[(int)$suggestedPlanId]['planned_date'] . ' · ' . $planById[(int)$suggestedPlanId]['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <button type="button" class="btn btn-sm btn-outline-warning hb-apply-plan" data-plan-id="<?= (int)$suggestedPlanId ?>"><?= htmlspecialchars(hb_t('Apply'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
              </div>
            <?php endif; ?>

            <form method="post" action="/open_bookings.php" class="row g-2 align-items-end hb-open-booking-form">
              <input type="hidden" name="action" value="save">
              <input type="hidden" name="transaction_id" value="<?= (int)$tx['id'] ?>">
              <input type="hidden" name="row_version" value="<?= (int)$tx['row_version'] ?>">
              <div class="col-md-3">
                <label class="form-label small"><?= htmlspecialchars(hb_t('Type'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <select class="form-select form-select-sm hb-open-type" name="type">
                  <?php foreach (['income', 'expense', 'transfer'] as $typeOption): ?>
                    <option value="<?= htmlspecialchars($typeOption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $selectedType === $typeOption ? 'selected' : '' ?>>
                      <?= htmlspecialchars(hb_t(ucfirst($typeOption)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4 hb-transfer-field d-none">
                <label class="form-label small"><?= htmlspecialchars(hb_t('Transfer from account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <select class="form-select form-select-sm" name="transfer_from_account_id">
                  <option value=""><?= htmlspecialchars(hb_t('Transfer from'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <?php foreach ($accounts as $acc): ?>
                    <option value="<?= (int)$acc['id'] ?>" <?= (int)($transferFromSelected ?? 0) === (int)$acc['id'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($acc['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4 hb-transfer-field d-none">
                <label class="form-label small"><?= htmlspecialchars(hb_t('Transfer to account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <select class="form-select form-select-sm" name="transfer_to_account_id">
                  <option value=""><?= htmlspecialchars(hb_t('Transfer to'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <?php foreach ($accounts as $acc): ?>
                    <option value="<?= (int)$acc['id'] ?>" <?= (int)($transferToSelected ?? 0) === (int)$acc['id'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($acc['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4 hb-non-transfer-field">
                <label class="form-label small"><?= htmlspecialchars(hb_t('Account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <select class="form-select form-select-sm" name="account_id">
                  <option value=""><?= htmlspecialchars(hb_t('Account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <?php foreach ($accounts as $acc): ?>
                    <option value="<?= (int)$acc['id'] ?>" <?= (int)($tx['account_id'] ?? 0) === (int)$acc['id'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($acc['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4 hb-non-transfer-field">
                <label class="form-label small d-flex justify-content-between align-items-center">
                  <span><?= htmlspecialchars(hb_t('Category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <button class="btn btn-sm btn-outline-secondary py-0 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#split-<?= (int)$tx['id'] ?>"><?= htmlspecialchars(hb_t('Split'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                </label>
                <?php
                $categorySelectorId = 'category-' . (int)$tx['id'];
                $categorySelectorName = 'category_id';
                $categorySelectorCategories = $categories;
                $categorySelectorSelected = $tx['category_id'] ?? null;
                $categorySelectorPlaceholder = hb_t('Search category...');
                $categoryModalTarget = '#categoryModal';
                require __DIR__ . '/../templates/partials/category_selector.php';
                ?>
                <?php $splitOpen = $splitRows ? 'show' : ''; ?>
                <div class="collapse <?= $splitOpen ?> mt-2" id="split-<?= (int)$tx['id'] ?>">
                  <div class="border rounded-3 p-2 bg-light-subtle">
                    <?php for ($i = 0; $i < 3; $i++): ?>
                      <?php $existing = $splitRows[$i] ?? null; ?>
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
                          <input type="text" class="form-control form-control-sm" name="split_amount[]" value="<?= $existing ? number_format($existing['amount_cents'] / 100, 2, ',', '.') : '' ?>" placeholder="<?= htmlspecialchars(hb_t('0.00'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        </div>
                      </div>
                    <?php endfor; ?>
                    <div class="form-text"><?= htmlspecialchars(hb_t('Split total equals amount.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                  </div>
                </div>
              </div>
              <div class="col-md-4 hb-non-transfer-field">
                <label class="form-label small"><?= htmlspecialchars(hb_t('Payee'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <?php
                $payeeSelectorId = 'payee-' . (int)$tx['id'];
                $payeeSelectorName = 'payee_id';
                $payeeSelectorPayees = $payees;
                $payeeSelectorSelected = $selectedPayee;
                $payeeSelectorPlaceholder = hb_t('Search payee...');
                $payeeModalTarget = '#payeeModal';
                require __DIR__ . '/../templates/partials/payee_selector.php';
                ?>
              </div>
              <div class="col-md-4 hb-non-transfer-field">
                <label class="form-label small"><?= htmlspecialchars(hb_t('Planned payment'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <select class="form-select form-select-sm" name="planned_payment_id">
                  <option value=""><?= htmlspecialchars(hb_t('Not set'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <?php foreach ($plans as $plan): ?>
                    <?php $selected = (int)$plan['id'] === (int)($selectedPlanId ?? 0); ?>
                    <option value="<?= (int)$plan['id'] ?>" <?= $selected ? 'selected' : '' ?>>
                      <?= htmlspecialchars($plan['planned_date'] . ' · ' . $plan['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label small"><?= htmlspecialchars(hb_t('Tags'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <?php
                $tagSelectorId = 'tags-' . (int)$tx['id'];
                $tagSelectorName = 'tag_ids[]';
                $tagSelectorTags = $tags;
                $tagSelectorSelected = $selectedTags;
                $tagSelectorPlaceholder = hb_t('Search tag...');
                $tagModalTarget = '#tagModal';
                require __DIR__ . '/../templates/partials/tag_selector.php';
                ?>
              </div>
              <div class="col-md-6">
                <label class="form-label small"><?= htmlspecialchars(hb_t('Note'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input class="form-control form-control-sm" type="text" name="note" value="<?= htmlspecialchars((string)($tx['note'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              </div>
              <div class="col-12 text-end">
                <button type="submit" name="save_only" value="1" class="btn btn-secondary btn-sm"><?= htmlspecialchars(hb_t("Save draft"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></button>
                <button type="submit" name="finalize" value="1" class="btn btn-success btn-sm"><?= htmlspecialchars(hb_t("Finalize booking"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></button>
              </div>
            </form>
            <?php if ($tx['type'] !== 'transfer'): ?>
              <?php
              $recurringName = $tx['payee_name'] ?? $tx['counterparty_name'] ?? $tx['note'] ?? hb_t('Recurring');
              $recurringId = (int)$tx['id'];
              ?>
              <div class="mt-3 border-top pt-2">
                <div class="d-flex justify-content-between align-items-center">
                  <h6 class="mb-0"><?= htmlspecialchars(hb_t('Save as recurring'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h6>
                  <button class="btn btn-sm btn-outline-secondary py-0 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#recurring-<?= $recurringId ?>" aria-expanded="false"><?= htmlspecialchars(hb_t('Details'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                </div>
                <div class="collapse mt-2" id="recurring-<?= $recurringId ?>">
                  <form method="post" action="/open_bookings.php" class="hb-recurring-form" data-transaction-id="<?= $recurringId ?>">
                    <input type="hidden" name="action" value="create_recurring">
                    <input type="hidden" name="transaction_id" value="<?= $recurringId ?>">
                    <input type="hidden" name="recurring_category_id" value="">
                    <input type="hidden" name="recurring_payee_id" value="">
                    <input type="hidden" name="recurring_note" value="">
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
                        <input type="date" class="form-control form-control-sm" name="recurring_start_date" value="<?= htmlspecialchars($tx['booking_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
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
                        <input type="text" class="form-control form-control-sm" name="recurring_tolerance_amount" placeholder="<?= htmlspecialchars(hb_t('e.g. 5.00'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                      </div>
                      <div class="col-md-4" data-recurring-group="tolerance">
                        <label class="form-label small"><?= htmlspecialchars(hb_t('Tolerance (%)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <input type="text" class="form-control form-control-sm" name="recurring_tolerance_pct" placeholder="<?= htmlspecialchars(hb_t('e.g. 5'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                      </div>
                    </div>
                    <div class="row g-2 align-items-end mt-2">
                      <div class="col-md-4" data-recurring-group="range">
                        <label class="form-label small"><?= htmlspecialchars(hb_t('Min amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <input type="text" class="form-control form-control-sm" name="recurring_min_amount" placeholder="<?= htmlspecialchars(hb_t('e.g. 40.00'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                      </div>
                      <div class="col-md-4" data-recurring-group="range">
                        <label class="form-label small"><?= htmlspecialchars(hb_t('Max amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <input type="text" class="form-control form-control-sm" name="recurring_max_amount" placeholder="<?= htmlspecialchars(hb_t('e.g. 60.00'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
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
                          <input class="form-check-input" type="checkbox" name="recurring_is_optional" id="recurring-opt-<?= $recurringId ?>">
                          <label class="form-check-label small" for="recurring-opt-<?= $recurringId ?>"><?= htmlspecialchars(hb_t('Optional'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
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
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php
  $tagModalId = 'tagModal';
  require __DIR__ . '/../templates/partials/tag_modal.php';
  $categoryModalId = 'categoryModal';
  require __DIR__ . '/../templates/partials/category_modal.php';
  $payeeModalId = 'payeeModal';
  require __DIR__ . '/../templates/partials/payee_modal.php';
  ?>
</div>
<?php
$content = ob_get_clean();
$extraScripts = '<script src="/js/chip-selector.js"></script>';
$extraScripts .= <<<HTML
<script>
document.addEventListener('click', (event) => {
  const btn = event.target.closest('.hb-apply-plan');
  if (!btn) return;
  const card = btn.closest('.card');
  if (!card) return;
  const select = card.querySelector('select[name="planned_payment_id"]');
  if (select) {
    select.value = btn.dataset.planId || '';
  }
});
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
document.querySelectorAll('.hb-open-booking-form').forEach((form) => {
  const typeSelect = form.querySelector('.hb-open-type');
  const transferFields = form.querySelectorAll('.hb-transfer-field');
  const nonTransferFields = form.querySelectorAll('.hb-non-transfer-field');
  const update = () => {
    const isTransfer = typeSelect?.value === 'transfer';
    transferFields.forEach((el) => el.classList.toggle('d-none', !isTransfer));
    nonTransferFields.forEach((el) => el.classList.toggle('d-none', isTransfer));
  };
  if (typeSelect) {
    typeSelect.addEventListener('change', update);
  }
  update();
});
document.addEventListener('submit', (event) => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement)) return;
  if (!form.classList.contains('hb-recurring-form')) return;
  const card = form.closest('.card');
  if (!card) return;
  const reviewForm = card.querySelector('form[action="/open_bookings.php"]');
  if (!reviewForm) return;
  const actionInput = reviewForm.querySelector('input[name="action"][value="save"]');
  if (!actionInput) return;
  const getValue = (selector) => reviewForm.querySelector(selector)?.value || '';
  form.querySelector('input[name="recurring_category_id"]').value = getValue('input[name="category_id"]');
  form.querySelector('input[name="recurring_payee_id"]').value = getValue('input[name="payee_id"]');
  form.querySelector('input[name="recurring_note"]').value = getValue('input[name="note"]');
});
</script>
HTML;
require __DIR__ . '/../templates/layout.php';
