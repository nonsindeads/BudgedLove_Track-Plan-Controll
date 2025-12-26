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
        'select t.*, p.name as payee_name, c.name as category_name, a.name as account_name
           from transactions t
           left join payees p on p.id = t.payee_id
           left join categories c on c.id = t.category_id
           left join accounts a on a.id = t.account_id
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
  <?php endif; ?>
  <?php if ($msg === 'attachment_saved'): ?>
    <div class="alert alert-success">Anhang gespeichert.</div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-lg-7">
      <div class="card shadow-sm mb-3">
        <div class="card-body">
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

      <div class="card shadow-sm">
        <div class="card-body">
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
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($transactions as $tx): ?>
                  <tr>
                    <td><?= htmlspecialchars($tx['booking_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($tx['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= number_format($tx['amount_cents'] / 100, 2, ',', '.') ?> €</td>
                    <td><?= htmlspecialchars($tx['account_name'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($tx['category_name'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($tx['payee_name'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><a class="btn btn-sm btn-outline-secondary" href="/transactions.php?action=show&id=<?= (int)$tx['id'] ?>">Details</a></td>
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

    <div class="col-lg-5">
      <div class="card shadow-sm">
        <div class="card-body">
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
          <?php else: ?>
            <form method="post" action="/transactions.php">
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
                <label class="form-label">
                  Kategorie
                  <span class="text-muted" data-bs-toggle="tooltip" title="Kann leer bleiben, wenn Splits genutzt werden.">ℹ️</span>
                </label>
                <select class="form-select" name="category_id">
                  <option value="">--</option>
                  <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int)$cat['id'] ?>" <?= ($transaction['category_id'] ?? null) == $cat['id'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($cat['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> (<?= htmlspecialchars($cat['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mt-3">
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
              <div class="mt-3">
                <label class="form-label">
                  Payee
                  <span class="text-muted" data-bs-toggle="tooltip" title="Empfänger/Zahler der Buchung. Optional.">ℹ️</span>
                </label>
                <select class="form-select" name="payee_id">
                  <option value="">--</option>
                  <?php foreach ($payees as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= ($transaction['payee_id'] ?? null) == $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mt-3">
                <label class="form-label">
                  Tags
                  <span class="text-muted" data-bs-toggle="tooltip" title="Mehrfachauswahl möglich, um Buchungen zu gruppieren/filtern.">ℹ️</span>
                </label>
                <select class="form-select" multiple name="tag_ids[]">
                  <?php
                  $currentTags = array_map(fn($t) => (int)$t['tag_id'], $transactionTags);
                  foreach ($tags as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" <?= in_array((int)$t['id'], $currentTags, true) ? 'selected' : '' ?>>
                      <?= htmlspecialchars($t['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text">Mehrfachauswahl möglich.</div>
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
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
