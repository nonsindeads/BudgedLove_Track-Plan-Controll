<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../app/domain.php';

hb_require_login();
$pdo = hb_get_pdo();
$household = hb_require_household($pdo);
$currentHousehold = $household;
$currentUser = hb_current_user($pdo);

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accountId = (int)($_POST['account_id'] ?? 0);
    $account = null;
    foreach ($accounts as $acc) {
        if ((int)$acc['id'] === $accountId) {
            $account = $acc;
            break;
        }
    }

    if (!$account) {
        $error = 'Bitte ein Konto auswählen.';
    } elseif (empty($_FILES['statement']) || $_FILES['statement']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Bitte eine gültige XML-Datei hochladen.';
    } else {
        $xmlContent = file_get_contents($_FILES['statement']['tmp_name']);
        if ($xmlContent === false) {
            $error = 'Datei konnte nicht gelesen werden.';
        } else {
            $xml = simplexml_load_string($xmlContent);
            if (!$xml) {
                $error = 'XML konnte nicht geparst werden.';
            }
        }
    }

    if ($error === null) {
        $ns = $xml->getNamespaces(true);
        $nsUri = $ns[''] ?? null;
        if ($nsUri) {
            $xml->registerXPathNamespace('c', $nsUri);
        }
        $entries = $nsUri ? $xml->xpath('//c:Ntry') : [];
        if (!$entries) {
            $error = 'Keine Buchungen gefunden.';
        } else {
            $inserted = 0;
            $skipped = 0;
            $blocked = 0;
            $details = [];

            $payeeCache = [];
            $findPayee = $pdo->prepare('select * from payees where household_id = :hid and name = :name');
            $insertPayee = $pdo->prepare(
                'insert into payees (household_id, name) values (:hid, :name) returning *'
            );
            $findTx = $pdo->prepare(
                'select id from transactions where household_id = :hid and import_hash = :hash'
            );
            $insertTx = $pdo->prepare(
                'insert into transactions
                    (household_id, type, booking_date, amount_cents, currency_code, account_id, category_id, payee_id, note, external_id, import_hash)
                 values
                    (:hid, :type, :date, :amount, :cur, :account_id, :category_id, :payee_id, :note, :external_id, :import_hash)'
            );

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
                    $amountCents = hb_parse_cents($txAmt);
                    if ($amountCents === null) {
                        $skipped++;
                        continue;
                    }
                    $direction = $entryDir === 'CRDT' ? 'income' : 'expense';
                    $dateObj = DateTimeImmutable::createFromFormat('Y-m-d', $bookingDate);
                    if ($dateObj && hb_is_period_closed($pdo, $household['id'], $dateObj)) {
                        $blocked++;
                        continue;
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
                    $payeeId = null;
                    if ($payeeName !== '') {
                        if (!isset($payeeCache[$payeeName])) {
                            $findPayee->execute(['hid' => $household['id'], 'name' => $payeeName]);
                            $payee = $findPayee->fetch();
                            if (!$payee) {
                                $insertPayee->execute(['hid' => $household['id'], 'name' => $payeeName]);
                                $payee = $insertPayee->fetch();
                            }
                            $payeeCache[$payeeName] = $payee['id'] ?? null;
                        }
                        $payeeId = $payeeCache[$payeeName];
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

                    $insertTx->execute([
                        'hid' => $household['id'],
                        'type' => $direction,
                        'date' => $bookingDate,
                        'amount' => $amountCents,
                        'cur' => 'EUR',
                        'account_id' => $account['id'],
                        'category_id' => null,
                        'payee_id' => $payeeId,
                        'note' => $note,
                        'external_id' => $serviceRef !== '' ? $serviceRef : $endToEnd,
                        'import_hash' => $importHash,
                    ]);
                    $inserted++;
                    if ($payeeName !== '') {
                        $details[] = ['date' => $bookingDate, 'name' => $payeeName, 'amount' => $amountCents];
                    }
                }
            }

            $summary = [
                'inserted' => $inserted,
                'skipped' => $skipped,
                'blocked' => $blocked,
                'details' => $details,
            ];
            $msg = 'Import abgeschlossen.';
        }
    }
}

ob_start();
?>
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h4 mb-0">Import (CAMT v8)</h1>
      <div class="text-muted small">Gebuchte Umsätze aus camt.052.001.08</div>
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
          <h2 class="h6 mb-3">Datei hochladen</h2>
          <form method="post" action="/import.php" enctype="multipart/form-data">
            <div class="mb-3">
              <label class="form-label">Konto</label>
              <select class="form-select" name="account_id" required>
                <option value="">Bitte wählen</option>
                <?php foreach ($accounts as $acc): ?>
                  <option value="<?= (int)$acc['id'] ?>"><?= htmlspecialchars($acc['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">CAMT.052 XML (v8, gebucht)</label>
              <input type="file" class="form-control" name="statement" accept=".xml" required>
            </div>
            <button type="submit" class="btn btn-success">Import starten</button>
          </form>
        </div>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-body">
          <h2 class="h6 mb-3">Import-Übersicht</h2>
          <?php if (!$summary): ?>
            <div class="text-muted">Noch kein Import ausgeführt.</div>
          <?php else: ?>
            <div class="d-flex gap-3 mb-3">
              <span class="badge bg-success-subtle text-success">Neu: <?= (int)$summary['inserted'] ?></span>
              <span class="badge bg-secondary-subtle text-secondary">Duplikate: <?= (int)$summary['skipped'] ?></span>
              <span class="badge bg-warning-subtle text-warning">Gesperrt: <?= (int)$summary['blocked'] ?></span>
            </div>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>Datum</th>
                    <th>Payee</th>
                    <th>Betrag</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach (array_slice($summary['details'], 0, 12) as $row): ?>
                    <tr>
                      <td><?= htmlspecialchars($row['date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                      <td><?= htmlspecialchars($row['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                      <td><?= number_format($row['amount'] / 100, 2, ',', '.') ?> €</td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$summary['details']): ?>
                    <tr><td colspan="3" class="text-muted">Keine neuen Einträge.</td></tr>
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
