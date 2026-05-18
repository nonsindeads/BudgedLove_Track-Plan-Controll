<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

hb_require_login();
$serverPdo = hb_get_pdo();
$household = hb_require_household($serverPdo);
$pdo = hb_household_pdo($serverPdo, (int)$household['id']);
$db = hb_dbal_household();
$dbPlatform = hb_dbal_platform($db);
$currentHousehold = $household;
$currentUser = hb_current_user($serverPdo);

$pageTitle = 'Import';
$activeNav = 'import';
$breadcrumbs = [
    ['label' => 'Import', 'href' => '/import.php'],
];

$accountsStmt = $pdo->prepare('select * from accounts where household_id = :hid and is_archived = false order by name asc');
$accountsStmt->execute(['hid' => $household['id']]);
$accounts = $accountsStmt->fetchAll();

$msg = null;
$error = null;
$summary = null;
$fileErrors = [];
$zipPreview = [];
$zipToken = null;
$pendingZip = false;

function hb_parse_camt_cents(string $amount): ?int
{
    $clean = str_replace([' ', "\u{00A0}"], '', trim($amount));
    if ($clean === '') {
        return null;
    }
    if (str_contains($clean, ',') && str_contains($clean, '.')) {
        $clean = str_replace('.', '', $clean);
        $clean = str_replace(',', '.', $clean);
    } elseif (str_contains($clean, ',')) {
        $clean = str_replace(',', '.', $clean);
    }
    if (!is_numeric($clean)) {
        return null;
    }
    return (int)round((float)$clean * 100);
}

function hb_mapping_pattern_matches(string $pattern, string $candidate): bool
{
    $pattern = trim($pattern);
    $candidate = trim($candidate);
    if ($pattern === '' || $candidate === '') {
        return false;
    }
    $normalizedPattern = mb_strtolower($pattern, 'UTF-8');
    $normalizedCandidate = mb_strtolower($candidate, 'UTF-8');
    if (!str_contains($normalizedPattern, '*') && !str_contains($normalizedPattern, '%')) {
        return $normalizedPattern === $normalizedCandidate;
    }
    $glob = str_replace('%', '*', $normalizedPattern);
    if (function_exists('fnmatch')) {
        return fnmatch($glob, $normalizedCandidate);
    }
    $regex = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($glob, '/')) . '$/u';
    return (bool)preg_match($regex, $normalizedCandidate);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $confirmZip = !empty($_POST['confirm_zip']);
    $resumeToken = (string)($_POST['zip_token'] ?? '');
    $accountId = (int)($_POST['account_id'] ?? 0);
    $account = null;
    foreach ($accounts as $acc) {
        if ((int)$acc['id'] === $accountId) {
            $account = $acc;
            break;
        }
    }

    if (!$account && !($confirmZip && $resumeToken !== '')) {
        $error = hb_t('Please select an account.');
    } else {
        $uploadedPath = '';
        $uploadedName = '';
        $isZip = false;

        if ($confirmZip && $resumeToken !== '') {
            $stored = $_SESSION['import_zip'][$resumeToken] ?? null;
            if (!$stored || empty($stored['path']) || !is_string($stored['path'])) {
                $error = hb_t('ZIP preview expired. Please upload again.');
            } else {
                $uploadedPath = $stored['path'];
                $uploadedName = (string)($stored['name'] ?? 'ZIP');
                $isZip = true;
                $zipPreview = $stored['files'] ?? [];
                $accountId = (int)($stored['account_id'] ?? $accountId);
                $account = null;
                foreach ($accounts as $acc) {
                    if ((int)$acc['id'] === $accountId) {
                        $account = $acc;
                        break;
                    }
                }
                if (!$account || !is_file($uploadedPath)) {
                    $error = hb_t('ZIP preview expired. Please upload again.');
                }
            }
        } else {
            if (empty($_FILES['statement']) || $_FILES['statement']['error'] !== UPLOAD_ERR_OK) {
                $error = hb_t('Please upload a valid XML or ZIP file.');
            } else {
                $uploadedName = (string)($_FILES['statement']['name'] ?? '');
                $extension = strtolower(pathinfo($uploadedName, PATHINFO_EXTENSION));
                $isZip = $extension === 'zip';
                if (!$isZip && $extension !== 'xml') {
                    $error = hb_t('Please upload an XML or ZIP file.');
                } else {
                    $uploadedPath = $_FILES['statement']['tmp_name'];
                }
            }
        }
    }

    if ($error === null) {
        $inserted = 0;
        $skipped = 0;
        $blocked = 0;
        $matched = 0;
        $details = [];
        $processedFiles = 0;
        $minDate = null;
        $maxDate = null;

        $pdoDriver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $isSqliteDriver = $pdoDriver === 'sqlite';
        if ($isSqliteDriver) {
            $mappingStmt = $pdo->prepare(
                'insert into payee_mappings (household_id, counterparty_name)
                 select :hid, :name
                  where not exists (
                    select 1
                      from payee_mappings
                     where household_id = :hid
                       and counterparty_name = :name
                  )'
            );
            $insertTag = $pdo->prepare(
                'insert into transaction_tags (transaction_id, tag_id)
                 select :transaction_id, :tag_id
                  where not exists (
                    select 1
                      from transaction_tags
                     where transaction_id = :transaction_id
                       and tag_id = :tag_id
                  )'
            );
        } else {
            $mappingStmt = $pdo->prepare(
                'insert into payee_mappings (household_id, counterparty_name)
                 values (:hid, :name)
                 on conflict (household_id, counterparty_name) do nothing'
            );
            $insertTag = $pdo->prepare(
                'insert into transaction_tags (transaction_id, tag_id)
                 values (:transaction_id, :tag_id)
                 on conflict do nothing'
            );
        }
        $noteContainsExpr = $isSqliteDriver
            ? "instr(note, :note_existing) > 0"
            : "position(:note_existing in note) > 0";
        $findTx = $pdo->prepare(
            'select id from transactions where household_id = :hid and import_hash = :hash'
        );
        $findImportedGroup = $pdo->prepare(
            'select id from transaction_groups where household_id = :hid and import_hash = :hash'
        );
        $dateDistanceExpr = $isSqliteDriver
            ? "abs(julianday(t.booking_date) - julianday(:booking_date_distance))"
            : "abs(t.booking_date - cast(:booking_date_distance as date))";
        $findReceiptDraft = $pdo->prepare(
            "select t.id
               from transactions t
          left join payees p on p.id = t.payee_id
              where t.household_id = :hid
                and t.is_reviewed = false
                and t.import_hash is null
                and t.type = :type
                and t.amount_cents = :amount
                and t.booking_date between :start and :end
                and coalesce(t.note, '') like '%[receipt-draft]%'
                and (
                    :payee = ''
                    or lower(coalesce(t.counterparty_name, '')) like lower(:payee_like_counterparty)
                    or lower(coalesce(p.name, '')) like lower(:payee_like_payee)
                    or lower(coalesce(t.note, '')) like lower(:payee_like_note)
                )
              order by
                case when t.booking_date = :booking_date_exact then 0 else 1 end,
                {$dateDistanceExpr} asc,
                t.id asc
              limit 1"
        );
        $matchReceiptDraft = $pdo->prepare(
            "update transactions
                set booking_date = :date,
                    account_id = :account_id,
                    counterparty_name = coalesce(nullif(:counterparty_name, ''), counterparty_name),
                    external_id = :external_id,
                    import_hash = :import_hash,
                    suggested_payee_id = coalesce(suggested_payee_id, :suggested_payee_id),
                    suggested_planned_payment_id = coalesce(suggested_planned_payment_id, :suggested_planned_payment_id),
                    note = case
                        when coalesce(note, '') = '' then :note_empty
                        when :note_blank is null or :note_blank = '' then note
                        when {$noteContainsExpr} then note
                        else note || ' | ' || :note_append
                    end,
                    updated_at = :updated_at
              where id = :id and household_id = :hid and is_reviewed = false"
        );
        $groupDateDistanceExpr = $isSqliteDriver
            ? "abs(julianday(tg.booking_date) - julianday(:booking_date_distance))"
            : "abs(tg.booking_date - cast(:booking_date_distance as date))";
        $findReceiptGroup = $pdo->prepare(
            "select tg.id, tg.receipt_id
               from transaction_groups tg
          left join payees p on p.id = tg.payee_id
              where tg.household_id = :hid
                and tg.status = 'draft'
                and tg.import_hash is null
                and tg.type = :type
                and tg.total_amount_cents = :amount
                and tg.booking_date between :start and :end
                and (
                    :payee = ''
                    or lower(coalesce(tg.payee, '')) like lower(:payee_like_group)
                    or lower(coalesce(p.name, '')) like lower(:payee_like_payee)
                    or lower(coalesce(tg.notes, '')) like lower(:payee_like_note)
                )
              order by
                case when tg.booking_date = :booking_date_exact then 0 else 1 end,
                {$groupDateDistanceExpr} asc,
                tg.id asc
              limit 1"
        );
        $matchReceiptGroup = $pdo->prepare(
            "update transaction_groups
                set status = 'booked',
                    account_id = :account_id,
                    payee = coalesce(nullif(:payee, ''), payee),
                    payee_id = coalesce(payee_id, :payee_id),
                    external_id = :external_id,
                    import_hash = :import_hash,
                    matched_transaction_id = :matched_transaction_id,
                    updated_at = :updated_at
              where id = :id and household_id = :hid"
        );
        $linkGroupSplitsToTx = $pdo->prepare(
            'update transaction_splits
                set transaction_id = :transaction_id,
                    updated_at = :updated_at
              where household_id = :hid
                and transaction_group_id = :group_id
                and transaction_id is null'
        );
        $matchReceiptGroupReceipt = $pdo->prepare(
            "update receipts
                set status = 'matched',
                    updated_at = :updated_at
              where id = :id and household_id = :hid"
        );

        $mappingLookupExact = [];
        $mappingLookupPattern = [];
        $mappingQuery = $pdo->prepare(
            'select counterparty_name, payee_id, category_id, tag_ids
               from payee_mappings
              where household_id = :hid'
        );
        $mappingQuery->execute(['hid' => $household['id']]);
        foreach ($mappingQuery->fetchAll() as $row) {
            $counterpartyPattern = trim((string)$row['counterparty_name']);
            $rule = [
                'payee_id' => !empty($row['payee_id']) ? (int)$row['payee_id'] : null,
                'category_id' => !empty($row['category_id']) ? (int)$row['category_id'] : null,
                'tag_ids' => hb_pg_int_array_to_php($row['tag_ids'] ?? '{}'),
                'pattern' => $counterpartyPattern,
            ];
            if (str_contains($counterpartyPattern, '*') || str_contains($counterpartyPattern, '%')) {
                $mappingLookupPattern[] = $rule;
            } else {
                $mappingLookupExact[mb_strtolower($counterpartyPattern, 'UTF-8')] = $rule;
            }
        }

        $planWindowStmt = $pdo->prepare(
            "select * from planned_payments
              where household_id = :hid
                and status in ('open','overdue','suggested')
                and planned_date between :start and :end"
        );

        $importCamt = function (string $xmlContent, string $sourceLabel) use (
            $pdo,
            $db,
            $household,
            $account,
            $findTx,
            $insertTag,
            $mappingStmt,
            $mappingLookupExact,
            $mappingLookupPattern,
            $planWindowStmt,
            $findReceiptDraft,
            $matchReceiptDraft,
            $findImportedGroup,
            $findReceiptGroup,
            $matchReceiptGroup,
            $matchReceiptGroupReceipt,
            $linkGroupSplitsToTx,
            &$inserted,
            &$skipped,
            &$blocked,
            &$matched,
            &$details,
            &$fileErrors,
            &$processedFiles,
            &$minDate,
            &$maxDate
        ): void {
            $xml = simplexml_load_string($xmlContent);
            if (!$xml) {
                $fileErrors[] = hb_t('File {name}: XML could not be parsed.', null, ['name' => $sourceLabel]);
                return;
            }
            $ns = $xml->getNamespaces(true);
            $nsUri = $ns[''] ?? null;
            if ($nsUri) {
                $xml->registerXPathNamespace('c', $nsUri);
            }
            $entries = $nsUri ? $xml->xpath('//c:Ntry') : [];
            if (!$entries) {
                $fileErrors[] = hb_t('File {name}: No transactions found.', null, ['name' => $sourceLabel]);
                return;
            }

            foreach ($entries as $entry) {
                $status = (string)($entry->Sts->Cd ?? '');
                if ($status !== 'BOOK') {
                    continue;
                }
                $entryAmt = (string)($entry->Amt ?? '');
                $entryDir = (string)($entry->CdtDbtInd ?? '');
                $bookingDate = (string)($entry->BookgDt->Dt ?? '');
                if ($bookingDate === '') {
                    $bookingDate = (string)($entry->ValDt->Dt ?? '');
                }
                $serviceRef = (string)($entry->AcctSvcrRef ?? '');
                $addtlInfo = trim((string)($entry->AddtlNtryInf ?? ''));

                $txDetails = $entry->NtryDtls->TxDtls ?? [];
                if (!is_array($txDetails) && $txDetails instanceof Traversable) {
                    $txDetails = iterator_to_array($txDetails);
                }
                if (!$txDetails) {
                    $txDetails = [$entry];
                }

                foreach ($txDetails as $tx) {
                    $txAmt = (string)($tx->Amt ?? $entryAmt);
                    $amountCents = hb_parse_camt_cents($txAmt);
                    if ($amountCents === null) {
                        $skipped++;
                        continue;
                    }
                    if ($amountCents <= 0) {
                        $blocked++;
                        continue;
                    }
                    $direction = $entryDir === 'CRDT' ? 'income' : 'expense';
                    $dateObj = DateTimeImmutable::createFromFormat('Y-m-d', $bookingDate);
                    if ($dateObj && hb_is_period_closed($pdo, $household['id'], $dateObj)) {
                        $blocked++;
                        continue;
                    }
                    if ($dateObj) {
                        [$periodStart, $periodEnd] = hb_household_period_bounds($household, $dateObj);
                        hb_ensure_month_plan($pdo, $household, $periodStart, $periodEnd);
                        $dateStr = $dateObj->format('Y-m-d');
                        if ($minDate === null || $dateStr < $minDate) {
                            $minDate = $dateStr;
                        }
                        if ($maxDate === null || $dateStr > $maxDate) {
                            $maxDate = $dateStr;
                        }
                    }

                    $refs = $tx->Refs ?? null;
                    $endToEnd = (string)($refs->EndToEndId ?? '');
                    $remittance = '';
                    if (isset($tx->RmtInf->Ustrd)) {
                        $remittance = trim((string)$tx->RmtInf->Ustrd);
                    }
                    $noteParts = array_filter([$remittance, $addtlInfo], fn($val) => $val !== '');
                    $note = $noteParts ? implode(' | ', $noteParts) : null;

                    $payeeName = '';
                    $parties = $tx->RltdPties ?? null;
                    if ($direction === 'expense') {
                        $payeeName = (string)($parties->Cdtr->Pty->Nm ?? $parties->UltmtCdtr->Pty->Nm ?? '');
                    } else {
                        $payeeName = (string)($parties->Dbtr->Pty->Nm ?? $parties->UltmtDbtr->Pty->Nm ?? '');
                    }
                    $payeeName = trim($payeeName);
                    $suggestedPayeeId = null;
                    $categoryId = null;
                    $tagIds = [];

                    $payeeId = null;
                    $mappingType = null;
                    if ($suggestedPayeeId) {
                        $payeeId = $suggestedPayeeId;
                    } elseif ($payeeName !== '') {
                        $mappingRule = $mappingLookupExact[mb_strtolower($payeeName, 'UTF-8')] ?? null;
                        if ($mappingRule) {
                            $mappingType = 'exact';
                        } else {
                            foreach ($mappingLookupPattern as $patternRule) {
                                if (hb_mapping_pattern_matches((string)$patternRule['pattern'], $payeeName)) {
                                    $mappingRule = $patternRule;
                                    $mappingType = 'pattern';
                                    break;
                                }
                            }
                        }
                        if ($mappingRule) {
                            $payeeId = $mappingRule['payee_id'] ?? null;
                            $categoryId = $mappingRule['category_id'] ?? null;
                            $tagIds = $mappingRule['tag_ids'] ?? [];
                            $suggestedPayeeId = $payeeId;
                        } else {
                            $mappingStmt->execute(['hid' => $household['id'], 'name' => $payeeName]);
                        }
                    }

                    $importHash = sha1(implode('|', [
                        $household['id'],
                        $account['id'],
                        $bookingDate,
                        $amountCents,
                        $entryDir,
                        $serviceRef,
                        $endToEnd,
                    ]));

                    $findTx->execute(['hid' => $household['id'], 'hash' => $importHash]);
                    if ($findTx->fetch()) {
                        $skipped++;
                        continue;
                    }
                    $findImportedGroup->execute(['hid' => $household['id'], 'hash' => $importHash]);
                    if ($findImportedGroup->fetch()) {
                        $skipped++;
                        continue;
                    }

                    $suggestedPlanId = null;
                    if ($dateObj) {
                        $windowStart = $dateObj->modify('-5 days')->format('Y-m-d');
                        $windowEnd = $dateObj->modify('+5 days')->format('Y-m-d');
                        $planWindowStmt->execute([
                            'hid' => $household['id'],
                            'start' => $windowStart,
                            'end' => $windowEnd,
                        ]);
                        $plans = $planWindowStmt->fetchAll();
                        if ($plans) {
                            $recurringById = [];
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
                            $suggestedPlan = hb_suggest_planned_payment(
                                $plans,
                                $recurringById,
                                [
                                    'booking_date' => $bookingDate,
                                    'amount_cents' => $amountCents,
                                    'type' => $direction,
                                    'account_id' => $account['id'],
                                    'payee_id' => $payeeId,
                                ]
                            );
                            if ($suggestedPlan) {
                                $suggestedPlanId = (int)$suggestedPlan['id'];
                            }
                        }
                    }

                    $matchedDraftId = null;
                    if ($dateObj) {
                        $findReceiptDraft->execute([
                            'hid' => $household['id'],
                            'type' => $direction,
                            'amount' => $amountCents,
                            'start' => $dateObj->modify('-5 days')->format('Y-m-d'),
                            'end' => $dateObj->modify('+5 days')->format('Y-m-d'),
                            'booking_date_exact' => $bookingDate,
                            'booking_date_distance' => $bookingDate,
                            'payee' => $payeeName,
                            'payee_like_counterparty' => '%' . $payeeName . '%',
                            'payee_like_payee' => '%' . $payeeName . '%',
                            'payee_like_note' => '%' . $payeeName . '%',
                        ]);
                        $matchedDraftId = (int)($findReceiptDraft->fetchColumn() ?: 0);
                    }
                    if ($matchedDraftId !== null && $matchedDraftId > 0) {
                        $matchReceiptDraft->execute([
                            'date' => $bookingDate,
                            'account_id' => $account['id'],
                            'counterparty_name' => $payeeName,
                            'external_id' => $serviceRef !== '' ? $serviceRef : $endToEnd,
                            'import_hash' => $importHash,
                            'suggested_payee_id' => $suggestedPayeeId,
                            'suggested_planned_payment_id' => $suggestedPlanId,
                            'note_empty' => $note,
                            'note_blank' => $note,
                            'note_existing' => $note,
                            'note_append' => $note,
                            'updated_at' => gmdate('Y-m-d H:i:s'),
                            'id' => $matchedDraftId,
                            'hid' => $household['id'],
                        ]);
                        foreach (hb_normalize_id_list($tagIds) as $tagId) {
                            $insertTag->execute([
                                'transaction_id' => $matchedDraftId,
                                'tag_id' => $tagId,
                            ]);
                        }
                        $matched++;
                        if ($payeeName !== '') {
                            $details[] = [
                                'date' => $bookingDate,
                                'name' => $payeeName . ' (' . hb_t('matched receipt draft') . ')',
                                'amount' => $amountCents,
                                'reason' => hb_t('Receipt draft match'),
                            ];
                        }
                        continue;
                    }

                    $matchedGroup = null;
                    if ($dateObj) {
                        $findReceiptGroup->execute([
                            'hid' => $household['id'],
                            'type' => $direction,
                            'amount' => $amountCents,
                            'start' => $dateObj->modify('-5 days')->format('Y-m-d'),
                            'end' => $dateObj->modify('+5 days')->format('Y-m-d'),
                            'booking_date_exact' => $bookingDate,
                            'booking_date_distance' => $bookingDate,
                            'payee' => $payeeName,
                            'payee_like_group' => '%' . $payeeName . '%',
                            'payee_like_payee' => '%' . $payeeName . '%',
                            'payee_like_note' => '%' . $payeeName . '%',
                        ]);
                        $matchedGroup = $findReceiptGroup->fetch() ?: null;
                    }
                    if ($matchedGroup) {
                        $matchedTransactionId = hb_dbal_insert_and_get_id($db, 'transactions', [
                            'household_id' => (int)$household['id'],
                            'type' => $direction,
                            'booking_date' => $bookingDate,
                            'amount_cents' => $amountCents,
                            'currency_code' => 'EUR',
                            'account_id' => $account['id'],
                            'category_id' => null,
                            'payee_id' => $payeeId,
                            'note' => $note,
                            'external_id' => $serviceRef !== '' ? $serviceRef : $endToEnd,
                            'import_hash' => $importHash,
                            'is_reviewed' => false,
                            'counterparty_name' => $payeeName !== '' ? $payeeName : null,
                            'suggested_payee_id' => $suggestedPayeeId,
                            'suggested_planned_payment_id' => $suggestedPlanId,
                            'receipt_id' => !empty($matchedGroup['receipt_id']) ? (int)$matchedGroup['receipt_id'] : null,
                            'split_group_id' => (int)$matchedGroup['id'],
                        ], 'id', ['is_reviewed' => \Doctrine\DBAL\ParameterType::BOOLEAN]);
                        $linkGroupSplitsToTx->execute([
                            'transaction_id' => $matchedTransactionId,
                            'updated_at' => gmdate('Y-m-d H:i:s'),
                            'hid' => $household['id'],
                            'group_id' => (int)$matchedGroup['id'],
                        ]);
                        $matchReceiptGroup->execute([
                            'account_id' => $account['id'],
                            'payee' => $payeeName,
                            'payee_id' => $payeeId,
                            'external_id' => $serviceRef !== '' ? $serviceRef : $endToEnd,
                            'import_hash' => $importHash,
                            'matched_transaction_id' => $matchedTransactionId,
                            'updated_at' => gmdate('Y-m-d H:i:s'),
                            'id' => (int)$matchedGroup['id'],
                            'hid' => $household['id'],
                        ]);
                        if (!empty($matchedGroup['receipt_id'])) {
                            $matchReceiptGroupReceipt->execute([
                                'id' => (int)$matchedGroup['receipt_id'],
                                'updated_at' => gmdate('Y-m-d H:i:s'),
                                'hid' => $household['id'],
                            ]);
                        }
                        $matched++;
                        if ($payeeName !== '') {
                            $details[] = [
                                'date' => $bookingDate,
                                'name' => $payeeName . ' (' . hb_t('matched receipt split') . ')',
                                'amount' => $amountCents,
                                'reason' => hb_t('Receipt split match'),
                            ];
                        }
                        continue;
                    }

                    $transactionId = hb_dbal_insert_and_get_id($db, 'transactions', [
                        'household_id' => (int)$household['id'],
                        'type' => $direction,
                        'booking_date' => $bookingDate,
                        'amount_cents' => $amountCents,
                        'currency_code' => 'EUR',
                        'account_id' => $account['id'],
                        'category_id' => $categoryId,
                        'payee_id' => $payeeId,
                        'note' => $note,
                        'external_id' => $serviceRef !== '' ? $serviceRef : $endToEnd,
                        'import_hash' => $importHash,
                        'is_reviewed' => false,
                        'counterparty_name' => $payeeName !== '' ? $payeeName : null,
                        'suggested_payee_id' => $suggestedPayeeId,
                        'suggested_planned_payment_id' => $suggestedPlanId,
                    ], 'id', ['is_reviewed' => \Doctrine\DBAL\ParameterType::BOOLEAN]);
                    foreach (hb_normalize_id_list($tagIds) as $tagId) {
                        $insertTag->execute([
                            'transaction_id' => $transactionId,
                            'tag_id' => $tagId,
                        ]);
                    }
                    $inserted++;
                    if ($payeeName !== '') {
                        $details[] = [
                            'date' => $bookingDate,
                            'name' => $payeeName,
                            'amount' => $amountCents,
                            'reason' => $mappingType === 'exact'
                                ? hb_t('Rule: exact payee mapping')
                                : ($mappingType === 'pattern' ? hb_t('Rule: wildcard payee mapping') : hb_t('No rule')),
                        ];
                    }
                }
            }

            $processedFiles++;
        };

        if ($isZip) {
            if (!class_exists('ZipArchive')) {
                $error = hb_t('ZIP import is not available on the server (PHP-Zip missing). Please upload XML files individually.');
            }
        }

        if ($error === null && $isZip) {
            $zip = new ZipArchive();
            if ($confirmZip) {
                if ($zip->open($uploadedPath) !== true) {
                    $error = hb_t('ZIP file could not be opened.');
                } else {
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $stat = $zip->statIndex($i);
                        $name = $stat['name'] ?? "Datei {$i}";
                        if (str_ends_with($name, '/')) {
                            continue;
                        }
                        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xml') {
                            continue;
                        }
                        $content = $zip->getFromIndex($i);
                        if ($content === false) {
                            $fileErrors[] = hb_t('File {name}: Could not be read.', null, ['name' => $name]);
                            continue;
                        }
                        $importCamt($content, $name);
                    }
                    $zip->close();
                }
            } else {
                $tmpDir = sys_get_temp_dir();
                $zipToken = bin2hex(random_bytes(8));
                $targetPath = rtrim($tmpDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'hb_import_' . $zipToken . '.zip';
                if (!move_uploaded_file($uploadedPath, $targetPath)) {
                    $error = hb_t('ZIP file could not be saved.');
                } elseif ($zip->open($targetPath) !== true) {
                    $error = hb_t('ZIP file could not be opened.');
                } else {
                    $preview = [];
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $stat = $zip->statIndex($i);
                        $name = $stat['name'] ?? "Datei {$i}";
                        if (str_ends_with($name, '/')) {
                            continue;
                        }
                        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xml') {
                            continue;
                        }
                        $preview[] = $name;
                    }
                    $zip->close();
                    $zipPreview = $preview;
                    $_SESSION['import_zip'][$zipToken] = [
                        'path' => $targetPath,
                        'name' => $uploadedName,
                        'account_id' => $accountId,
                        'files' => $zipPreview,
                        'created_at' => time(),
                    ];
                    $pendingZip = true;
                }
            }
        } else {
            $xmlContent = file_get_contents($uploadedPath);
            if ($xmlContent === false) {
                $error = hb_t('File could not be read.');
            } else {
                $importCamt($xmlContent, $uploadedName !== '' ? $uploadedName : 'XML');
            }
        }

        if ($confirmZip && isset($_SESSION['import_zip'][$resumeToken])) {
            $storedPath = $_SESSION['import_zip'][$resumeToken]['path'] ?? null;
            if (is_string($storedPath) && is_file($storedPath)) {
                unlink($storedPath);
            }
            unset($_SESSION['import_zip'][$resumeToken]);
        }

        if ($pendingZip) {
            if (!$zipPreview) {
                $error = hb_t('No valid XML files found in ZIP.');
                $pendingZip = false;
                if (isset($_SESSION['import_zip'][$zipToken])) {
                    $storedPath = $_SESSION['import_zip'][$zipToken]['path'] ?? null;
                    if (is_string($storedPath) && is_file($storedPath)) {
                        unlink($storedPath);
                    }
                    unset($_SESSION['import_zip'][$zipToken]);
                }
            }
        }

        if ($error === null && $processedFiles === 0 && !$pendingZip) {
            $error = hb_t('No valid XML files found in upload.');
        }

        if ($error === null && !$pendingZip) {
            $summary = [
                'inserted' => $inserted,
                'skipped' => $skipped,
                'blocked' => $blocked,
                'matched' => $matched,
                'details' => $details,
                'files' => $processedFiles,
            ];
            $msg = hb_t('Import completed.');

            $userLabel = $currentUser['username'] ?? $currentUser['email'] ?? 'System';
            $dataNew = [
                'account_id' => (int)$account['id'],
                'account_name' => (string)$account['name'],
                'inserted' => $inserted,
                'skipped' => $skipped,
                'blocked' => $blocked,
                'matched' => $matched,
                'files' => $processedFiles,
                'source' => 'camt.052.001.08',
                'date_from' => $minDate,
                'date_to' => $maxDate,
            ];
            $stmt = $pdo->prepare(
                'insert into audit_events (event_at, household_id, user_id, username, action, table_name, entity_id, data_new)
                 values (:event_at, :hid, :uid, :username, :action, :table_name, :entity_id, :data_new)'
            );
            $stmt->execute([
                'event_at' => gmdate('Y-m-d H:i:s'),
                'hid' => $household['id'],
                'uid' => (int)($currentUser['id'] ?? 0) ?: null,
                'username' => $userLabel,
                'action' => 'import',
                'table_name' => 'imports',
                'entity_id' => (string)$account['id'],
                'data_new' => json_encode($dataNew, JSON_UNESCAPED_UNICODE),
            ]);

            $range = null;
            if ($minDate && $maxDate) {
                $range = $minDate === $maxDate ? $minDate : ($minDate . '–' . $maxDate);
            }
            $messageParts = [
                hb_t('{user} imported {count} transactions in {account}', null, [
                    'user' => $userLabel,
                    'count' => $inserted,
                    'account' => $account['name'],
                ]),
                $range,
            ];
            $notifyPayload = [
                'type' => 'audit',
                'table' => 'imports',
                'action' => 'import',
                'entity_id' => (string)$account['id'],
                'household_id' => (int)$household['id'],
                'user_id' => (int)($currentUser['id'] ?? 0),
                'username' => $userLabel,
                'timestamp' => gmdate('c'),
                'message' => implode(' · ', array_filter($messageParts)) . '.',
            ];
            $notifyStmt = $pdo->prepare("select pg_notify('hb_audit', :payload)");
            $notifyStmt->execute(['payload' => json_encode($notifyPayload, JSON_UNESCAPED_UNICODE)]);
        }
    }
}

ob_start();
?>
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h4 mb-0"><?= htmlspecialchars(hb_t('Import (CAMT v8)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
      <div class="text-muted small"><?= htmlspecialchars(hb_t('Booked transactions from camt.052.001.08'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    </div>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-success"><?= htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-lg-5">
      <div class="card shadow-sm">
        <div class="card-body">
          <h2 class="h6 mb-3"><?= htmlspecialchars(hb_t('Upload file'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
          <form method="post" action="/import.php" enctype="multipart/form-data">
            <div class="mb-3">
              <label class="form-label"><?= htmlspecialchars(hb_t('Account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <select class="form-select" name="account_id" required>
                <option value=""><?= htmlspecialchars(hb_t('Please select'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <?php foreach ($accounts as $acc): ?>
                  <option value="<?= (int)$acc['id'] ?>"><?= htmlspecialchars($acc['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label"><?= htmlspecialchars(hb_t('CAMT.052 XML/ZIP (v8, booked)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="file" class="form-control" name="statement" accept=".xml,.zip" required>
            </div>
            <button type="submit" class="btn btn-success"><?= htmlspecialchars(hb_t('Start import'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
          </form>
        </div>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-body">
          <h2 class="h6 mb-3"><?= htmlspecialchars(hb_t('Import overview'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
          <?php if ($pendingZip): ?>
            <div class="alert alert-info">
              <div class="fw-semibold mb-1"><?= htmlspecialchars(hb_t('ZIP detected'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <div class="small"><?= htmlspecialchars(hb_t('Please review and start the import.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            </div>
            <div class="mb-3">
              <div class="fw-semibold small mb-2"><?= htmlspecialchars(hb_t('XML files in ZIP'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <ul class="mb-0">
                <?php foreach ($zipPreview as $fileName): ?>
                  <li><?= htmlspecialchars($fileName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
            <form method="post" action="/import.php" class="d-flex gap-2">
              <input type="hidden" name="account_id" value="<?= (int)$accountId ?>">
              <input type="hidden" name="zip_token" value="<?= htmlspecialchars($zipToken ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <input type="hidden" name="confirm_zip" value="1">
              <button type="submit" class="btn btn-success"><?= htmlspecialchars(hb_t('Run import'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
            </form>
          <?php elseif (!$summary): ?>
            <div class="text-muted"><?= htmlspecialchars(hb_t('No import run yet.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
          <?php else: ?>
            <div class="d-flex gap-3 mb-3">
              <span class="badge bg-success-subtle text-success"><?= htmlspecialchars(hb_t('New:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= (int)$summary['inserted'] ?></span>
              <span class="badge bg-primary-subtle text-primary"><?= htmlspecialchars(hb_t('Matched:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= (int)$summary['matched'] ?></span>
              <span class="badge bg-secondary-subtle text-secondary"><?= htmlspecialchars(hb_t('Duplicates:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= (int)$summary['skipped'] ?></span>
              <span class="badge bg-warning-subtle text-warning"><?= htmlspecialchars(hb_t('Blocked:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= (int)$summary['blocked'] ?></span>
              <span class="badge bg-info-subtle text-info"><?= htmlspecialchars(hb_t('Files:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= (int)$summary['files'] ?></span>
            </div>
            <?php if ($fileErrors): ?>
              <div class="alert alert-warning mb-3">
                <div class="fw-semibold mb-1"><?= htmlspecialchars(hb_t('Notes'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <ul class="mb-0">
                  <?php foreach ($fileErrors as $fileError): ?>
                    <li><?= htmlspecialchars($fileError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th><?= htmlspecialchars(hb_t('Date'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars(hb_t('Payee'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars(hb_t('Amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars(hb_t('Reason'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach (array_slice($summary['details'], 0, 12) as $row): ?>
                    <tr>
                      <td><?= htmlspecialchars($row['date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                      <td><?= htmlspecialchars($row['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                      <td><?= number_format($row['amount'] / 100, 2, ',', '.') ?> €</td>
                      <td><?= htmlspecialchars((string)($row['reason'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$summary['details']): ?>
                    <tr><td colspan="4" class="text-muted"><?= htmlspecialchars(hb_t('No new entries.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
