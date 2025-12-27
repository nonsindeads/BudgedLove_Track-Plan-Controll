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
        $error = 'Bitte ein Konto auswählen.';
    } else {
        $uploadedPath = '';
        $uploadedName = '';
        $isZip = false;

        if ($confirmZip && $resumeToken !== '') {
            $stored = $_SESSION['import_zip'][$resumeToken] ?? null;
            if (!$stored || empty($stored['path']) || !is_string($stored['path'])) {
                $error = 'ZIP-Vorschau abgelaufen. Bitte erneut hochladen.';
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
                    $error = 'ZIP-Vorschau abgelaufen. Bitte erneut hochladen.';
                }
            }
        } else {
            if (empty($_FILES['statement']) || $_FILES['statement']['error'] !== UPLOAD_ERR_OK) {
                $error = 'Bitte eine gültige XML- oder ZIP-Datei hochladen.';
            } else {
                $uploadedName = (string)($_FILES['statement']['name'] ?? '');
                $extension = strtolower(pathinfo($uploadedName, PATHINFO_EXTENSION));
                $isZip = $extension === 'zip';
                if (!$isZip && $extension !== 'xml') {
                    $error = 'Bitte eine XML- oder ZIP-Datei hochladen.';
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
        $details = [];
        $processedFiles = 0;
        $minDate = null;
        $maxDate = null;

        $payeeCache = [];
        $findPayee = $pdo->prepare('select * from payees where household_id = :hid and name = :name');
        $mappingStmt = $pdo->prepare(
            'insert into payee_mappings (household_id, counterparty_name)
             values (:hid, :name)
             on conflict (household_id, counterparty_name) do nothing'
        );
        $findTx = $pdo->prepare(
            'select id from transactions where household_id = :hid and import_hash = :hash'
        );
        $insertTx = $pdo->prepare(
            'insert into transactions
                (household_id, type, booking_date, amount_cents, currency_code, account_id, category_id, payee_id, note, external_id, import_hash, is_reviewed, counterparty_name, suggested_payee_id, suggested_match_rule_id)
             values
                (:hid, :type, :date, :amount, :cur, :account_id, :category_id, :payee_id, :note, :external_id, :import_hash, :is_reviewed, :counterparty_name, :suggested_payee_id, :suggested_match_rule_id)'
        );

        $matchStmt = $pdo->prepare(
            'select id, pattern, match_type, payee_id
               from payee_match_rules
              where household_id = :hid and is_active = true
              order by priority asc, id asc'
        );
        $matchStmt->execute(['hid' => $household['id']]);
        $matchRules = $matchStmt->fetchAll() ?: [];

        $mappingLookup = [];
        $mappingQuery = $pdo->prepare(
            'select counterparty_name, payee_id
               from payee_mappings
              where household_id = :hid and payee_id is not null'
        );
        $mappingQuery->execute(['hid' => $household['id']]);
        foreach ($mappingQuery->fetchAll() as $row) {
            $mappingLookup[(string)$row['counterparty_name']] = (int)$row['payee_id'];
        }

        $importCamt = function (string $xmlContent, string $sourceLabel) use (
            $pdo,
            $household,
            $account,
            $findPayee,
            $insertPayee,
            $findTx,
            $insertTx,
            &$payeeCache,
            $matchRules,
            &$inserted,
            &$skipped,
            &$blocked,
            &$details,
            &$fileErrors,
            &$processedFiles
        ): void {
            $xml = simplexml_load_string($xmlContent);
            if (!$xml) {
                $fileErrors[] = "Datei {$sourceLabel}: XML konnte nicht geparst werden.";
                return;
            }
            $ns = $xml->getNamespaces(true);
            $nsUri = $ns[''] ?? null;
            if ($nsUri) {
                $xml->registerXPathNamespace('c', $nsUri);
            }
            $entries = $nsUri ? $xml->xpath('//c:Ntry') : [];
            if (!$entries) {
                $fileErrors[] = "Datei {$sourceLabel}: Keine Buchungen gefunden.";
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
                    $direction = $entryDir === 'CRDT' ? 'income' : 'expense';
                    $dateObj = DateTimeImmutable::createFromFormat('Y-m-d', $bookingDate);
                    if ($dateObj && hb_is_period_closed($pdo, $household['id'], $dateObj)) {
                        $blocked++;
                        continue;
                    }
                    if ($dateObj) {
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
                    $suggestedMatchRuleId = null;
                    $matchSource = $payeeName !== '' ? $payeeName : $remittance;
                    if ($matchSource !== '') {
                        foreach ($matchRules as $rule) {
                            $pattern = (string)($rule['pattern'] ?? '');
                            if ($pattern === '') {
                                continue;
                            }
                            if (stripos($matchSource, $pattern) !== false) {
                                $suggestedPayeeId = (int)$rule['payee_id'];
                                $suggestedMatchRuleId = (int)$rule['id'];
                                break;
                            }
                        }
                    }

                    $payeeId = null;
                    if ($suggestedPayeeId) {
                        $payeeId = $suggestedPayeeId;
                    } elseif ($payeeName !== '') {
                        if (isset($mappingLookup[$payeeName])) {
                            $payeeId = $mappingLookup[$payeeName];
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
                        'is_reviewed' => 0,
                        'counterparty_name' => $payeeName !== '' ? $payeeName : null,
                        'suggested_payee_id' => $suggestedPayeeId,
                        'suggested_match_rule_id' => $suggestedMatchRuleId,
                    ]);
                    $inserted++;
                    if ($payeeName !== '') {
                        $details[] = ['date' => $bookingDate, 'name' => $payeeName, 'amount' => $amountCents];
                    }
                }
            }

            $processedFiles++;
        };

        if ($isZip) {
            if (!class_exists('ZipArchive')) {
                $error = 'ZIP-Import ist auf dem Server nicht verfügbar (PHP-Zip fehlt). Bitte XML einzeln hochladen.';
            }
        }

        if ($error === null && $isZip) {
            $zip = new ZipArchive();
            if ($confirmZip) {
                if ($zip->open($uploadedPath) !== true) {
                    $error = 'ZIP-Datei konnte nicht geöffnet werden.';
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
                            $fileErrors[] = "Datei {$name}: Konnte nicht gelesen werden.";
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
                    $error = 'ZIP-Datei konnte nicht gespeichert werden.';
                } elseif ($zip->open($targetPath) !== true) {
                    $error = 'ZIP-Datei konnte nicht geöffnet werden.';
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
                $error = 'Datei konnte nicht gelesen werden.';
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
                $error = 'Keine gültigen XML-Dateien im ZIP gefunden.';
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
            $error = 'Keine gültigen XML-Dateien im Upload gefunden.';
        }

        if ($error === null && !$pendingZip) {
            $summary = [
                'inserted' => $inserted,
                'skipped' => $skipped,
                'blocked' => $blocked,
                'details' => $details,
                'files' => $processedFiles,
            ];
            $msg = 'Import abgeschlossen.';

            $userLabel = $currentUser['username'] ?? $currentUser['email'] ?? 'System';
            $dataNew = [
                'account_id' => (int)$account['id'],
                'account_name' => (string)$account['name'],
                'inserted' => $inserted,
                'skipped' => $skipped,
                'blocked' => $blocked,
                'files' => $processedFiles,
                'source' => 'camt.052.001.08',
                'date_from' => $minDate,
                'date_to' => $maxDate,
            ];
            $stmt = $pdo->prepare(
                'insert into audit_events (event_at, household_id, user_id, username, action, table_name, entity_id, data_new)
                 values (now(), :hid, :uid, :username, :action, :table_name, :entity_id, :data_new)'
            );
            $stmt->execute([
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
                sprintf('%s hat %d Buchungen in %s importiert', $userLabel, $inserted, $account['name']),
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
              <label class="form-label">CAMT.052 XML/ZIP (v8, gebucht)</label>
              <input type="file" class="form-control" name="statement" accept=".xml,.zip" required>
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
          <?php if ($pendingZip): ?>
            <div class="alert alert-info">
              <div class="fw-semibold mb-1">ZIP erkannt</div>
              <div class="small">Bitte prüfen und den Import starten.</div>
            </div>
            <div class="mb-3">
              <div class="fw-semibold small mb-2">XML-Dateien im ZIP</div>
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
              <button type="submit" class="btn btn-success">Import ausführen</button>
            </form>
          <?php elseif (!$summary): ?>
            <div class="text-muted">Noch kein Import ausgeführt.</div>
          <?php else: ?>
            <div class="d-flex gap-3 mb-3">
              <span class="badge bg-success-subtle text-success">Neu: <?= (int)$summary['inserted'] ?></span>
              <span class="badge bg-secondary-subtle text-secondary">Duplikate: <?= (int)$summary['skipped'] ?></span>
              <span class="badge bg-warning-subtle text-warning">Gesperrt: <?= (int)$summary['blocked'] ?></span>
              <span class="badge bg-info-subtle text-info">Dateien: <?= (int)$summary['files'] ?></span>
            </div>
            <?php if ($fileErrors): ?>
              <div class="alert alert-warning mb-3">
                <div class="fw-semibold mb-1">Hinweise</div>
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
