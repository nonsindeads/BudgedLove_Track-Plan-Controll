<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../app/domain.php';

hb_require_login();
$pdo = hb_get_pdo();
$household = hb_require_household($pdo);

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$msg = $_GET['msg'] ?? null;
$error = null;

if (in_array($action, ['store', 'update'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $address = trim((string)($_POST['address_text'] ?? ''));
    $iban = trim((string)($_POST['iban'] ?? ''));
    $bic = trim((string)($_POST['bic'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $id = (int)($_POST['id'] ?? 0);

    if ($name === '') {
        $error = 'Name ist erforderlich.';
    }

    if ($error === null) {
        if ($action === 'store') {
            $stmt = $pdo->prepare(
                'insert into payees (household_id, name, address_text, iban, bic, notes)
                 values (:hid, :name, :address, :iban, :bic, :notes)'
            );
            $stmt->execute([
                'hid' => $household['id'],
                'name' => $name,
                'address' => $address !== '' ? $address : null,
                'iban' => $iban !== '' ? $iban : null,
                'bic' => $bic !== '' ? $bic : null,
                'notes' => $notes !== '' ? $notes : null,
            ]);
        } else {
            $own = $pdo->prepare('select id from payees where id = :id and household_id = :hid');
            $own->execute(['id' => $id, 'hid' => $household['id']]);
            if (!$own->fetch()) {
                $error = 'Empfänger nicht gefunden.';
            } else {
                $stmt = $pdo->prepare(
                    'update payees
                        set name = :name,
                            address_text = :address,
                            iban = :iban,
                            bic = :bic,
                            notes = :notes,
                            updated_at = now()
                      where id = :id and household_id = :hid'
                );
                $stmt->execute([
                    'name' => $name,
                    'address' => $address !== '' ? $address : null,
                    'iban' => $iban !== '' ? $iban : null,
                    'bic' => $bic !== '' ? $bic : null,
                    'notes' => $notes !== '' ? $notes : null,
                    'id' => $id,
                    'hid' => $household['id'],
                ]);
            }
        }
        if ($error === null) {
            header('Location: /payees.php?msg=saved');
            exit;
        }
    }
}

$editPayee = null;
if ($action === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('select * from payees where id = :id and household_id = :hid');
    $stmt->execute(['id' => $id, 'hid' => $household['id']]);
    $editPayee = $stmt->fetch();
    if (!$editPayee) {
        $error = 'Empfänger nicht gefunden.';
        $action = 'list';
    }
}

$payeeStmt = $pdo->prepare('select * from payees where household_id = :hid order by name asc');
$payeeStmt->execute(['hid' => $household['id']]);
$payees = $payeeStmt->fetchAll();
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Empfänger | Haushaltsbuch</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <h1 class="h4 mb-0">Empfänger</h1>
        <div class="text-muted small">Haushalt: <?= htmlspecialchars($household['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      </div>
      <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-secondary" href="/categories.php">Kategorien</a>
        <a class="btn btn-sm btn-outline-primary" href="/transactions.php">Transaktionen</a>
      </div>
    </div>

    <?php if ($msg === 'saved'): ?>
      <div class="alert alert-success">Empfänger gespeichert.</div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="row g-4">
      <div class="col-lg-7">
        <div class="card shadow-sm">
          <div class="card-body">
            <h2 class="h6">Liste</h2>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>Name</th>
                    <th>IBAN</th>
                    <th>Notizen</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($payees as $p): ?>
                    <tr>
                      <td><?= htmlspecialchars($p['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                      <td><?= htmlspecialchars($p['iban'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                      <td class="small"><?= htmlspecialchars($p['notes'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                      <td><a class="btn btn-sm btn-outline-secondary" href="/payees.php?action=edit&id=<?= (int)$p['id'] ?>">Bearbeiten</a></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$payees): ?>
                    <tr><td colspan="4" class="text-muted">Keine Empfänger vorhanden.</td></tr>
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
            <?php $isEdit = $action === 'edit' && $editPayee; ?>
            <h2 class="h6 mb-3"><?= $isEdit ? 'Empfänger bearbeiten' : 'Neuer Empfänger' ?></h2>
            <form method="post" action="/payees.php">
              <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'store' ?>">
              <?php if ($isEdit): ?>
                <input type="hidden" name="id" value="<?= (int)$editPayee['id'] ?>">
              <?php endif; ?>
              <div class="mb-3">
                <label for="name" class="form-label">Name</label>
                <input type="text" class="form-control" id="name" name="name" required value="<?= htmlspecialchars($editPayee['name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              </div>
              <div class="mb-3">
                <label for="address" class="form-label">Adresse</label>
                <textarea class="form-control" id="address" name="address_text" rows="2"><?= htmlspecialchars($editPayee['address_text'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
              </div>
              <div class="row g-3">
                <div class="col-md-6">
                  <label for="iban" class="form-label">
                    IBAN
                    <span class="text-muted" data-bs-toggle="tooltip" title="Optional; hilft bei späteren Imports/Abgleichen.">ℹ️</span>
                  </label>
                  <input type="text" class="form-control" id="iban" name="iban" value="<?= htmlspecialchars($editPayee['iban'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                </div>
                <div class="col-md-6">
                  <label for="bic" class="form-label">
                    BIC
                    <span class="text-muted" data-bs-toggle="tooltip" title="Optional; für Banküberweisungen.">ℹ️</span>
                  </label>
                  <input type="text" class="form-control" id="bic" name="bic" value="<?= htmlspecialchars($editPayee['bic'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                </div>
              </div>
              <div class="mt-3">
                <label for="notes" class="form-label">Notizen</label>
                <textarea class="form-control" id="notes" name="notes" rows="2"><?= htmlspecialchars($editPayee['notes'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
              </div>
              <button type="submit" class="btn btn-success mt-3">Speichern</button>
            </form>
          </div>
        </div>
      </div>
</div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(t => new bootstrap.Tooltip(t));
  </script>
</body>
</html>
