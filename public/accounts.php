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

if ($action === 'store' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $type = (string)($_POST['type'] ?? '');
    $currency = strtoupper(trim((string)($_POST['currency_code'] ?? $household['currency_code'] ?? 'EUR')));
    $opening = hb_parse_cents((string)($_POST['opening_balance'] ?? '0'));
    $isArchived = isset($_POST['is_archived']);

    if ($name === '') {
        $error = 'Name ist erforderlich.';
    } elseif (!in_array($type, hb_allowed_account_types(), true)) {
        $error = 'Ungültiger Kontotyp.';
    } elseif ($opening === null) {
        $error = 'Startsaldo ungültig.';
    }

    if ($error === null) {
        $stmt = $pdo->prepare(
            'insert into accounts (household_id, name, type, currency_code, opening_balance_cents, is_archived)
             values (:hid, :name, :type, :cur, :open, :archived)'
        );
        $stmt->execute([
            'hid' => $household['id'],
            'name' => $name,
            'type' => $type,
            'cur' => $currency,
            'open' => $opening,
            'archived' => $isArchived ? 1 : 0,
        ]);
        header('Location: /accounts.php?msg=account_saved');
        exit;
    }
}

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $type = (string)($_POST['type'] ?? '');
    $currency = strtoupper(trim((string)($_POST['currency_code'] ?? $household['currency_code'] ?? 'EUR')));
    $opening = hb_parse_cents((string)($_POST['opening_balance'] ?? '0'));
    $isArchived = isset($_POST['is_archived']);

    $own = $pdo->prepare('select id from accounts where id = :id and household_id = :hid');
    $own->execute(['id' => $id, 'hid' => $household['id']]);
    if (!$own->fetch()) {
        $error = 'Konto nicht gefunden.';
    } elseif ($name === '') {
        $error = 'Name ist erforderlich.';
    } elseif (!in_array($type, hb_allowed_account_types(), true)) {
        $error = 'Ungültiger Kontotyp.';
    } elseif ($opening === null) {
        $error = 'Startsaldo ungültig.';
    }

    if ($error === null) {
        $stmt = $pdo->prepare(
            'update accounts
                set name = :name,
                    type = :type,
                    currency_code = :cur,
                    opening_balance_cents = :open,
                    is_archived = :archived,
                    updated_at = now()
              where id = :id and household_id = :hid'
        );
        $stmt->execute([
            'name' => $name,
            'type' => $type,
            'cur' => $currency,
            'open' => $opening,
            'archived' => $isArchived ? 1 : 0,
            'id' => $id,
            'hid' => $household['id'],
        ]);
        header('Location: /accounts.php?msg=account_saved');
        exit;
    }
}

$editAccount = null;
if ($action === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('select * from accounts where id = :id and household_id = :hid');
    $stmt->execute(['id' => $id, 'hid' => $household['id']]);
    $editAccount = $stmt->fetch();
    if (!$editAccount) {
        $error = 'Konto nicht gefunden.';
        $action = 'list';
    }
}

$accounts = $pdo->prepare('select * from accounts where household_id = :hid order by created_at asc');
$accounts->execute(['hid' => $household['id']]);
$accountsList = $accounts->fetchAll();
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Konten | Haushaltsbuch</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <h1 class="h4 mb-0">Konten</h1>
        <div class="text-muted small">Haushalt: <?= htmlspecialchars($household['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      </div>
      <div class="d-flex gap-2">
        <a href="/household.php" class="btn btn-sm btn-outline-secondary">Haushalt wechseln</a>
        <a href="/transactions.php" class="btn btn-sm btn-outline-primary">Zu Transaktionen</a>
      </div>
    </div>

    <?php if ($msg === 'account_saved'): ?>
      <div class="alert alert-success">Konto gespeichert.</div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="row g-4">
      <div class="col-lg-7">
        <div class="card shadow-sm">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <h2 class="h6 mb-0">Übersicht</h2>
              <a class="btn btn-sm btn-primary" href="/accounts.php?action=new">Neues Konto</a>
            </div>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>Name</th>
                    <th>Typ</th>
                    <th>Währung</th>
                    <th>Startsaldo</th>
                    <th>Status</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($accountsList as $acc): ?>
                    <tr>
                      <td><?= htmlspecialchars($acc['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                      <td><?= htmlspecialchars(hb_account_type_label($acc['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                      <td><?= htmlspecialchars($acc['currency_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                      <td><?= number_format(((int)$acc['opening_balance_cents']) / 100, 2, ',', '.') ?> €</td>
                      <td>
                        <?php if ($acc['is_archived']): ?>
                          <span class="badge bg-secondary"
                                data-bs-toggle="tooltip"
                                title="Archivierte Konten bleiben historisch sichtbar, können aber nicht mehr aktiv genutzt werden.">
                            Archiviert
                          </span>
                        <?php else: ?>
                          <span class="badge bg-success">Aktiv</span>
                        <?php endif; ?>
                      </td>
                      <td><a class="btn btn-sm btn-outline-secondary" href="/accounts.php?action=edit&id=<?= (int)$acc['id'] ?>">Bearbeiten</a></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$accountsList): ?>
                    <tr><td colspan="6" class="text-muted">Keine Konten vorhanden.</td></tr>
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
            <?php
            $isEdit = $action === 'edit' && $editAccount;
            $targetAction = $isEdit ? 'update' : 'store';
            ?>
            <h2 class="h6 mb-3"><?= $isEdit ? 'Konto bearbeiten' : 'Neues Konto' ?></h2>
            <form method="post" action="/accounts.php">
              <input type="hidden" name="action" value="<?= $targetAction ?>">
              <?php if ($isEdit): ?>
                <input type="hidden" name="id" value="<?= (int)$editAccount['id'] ?>">
              <?php endif; ?>
              <div class="mb-3">
                <label for="name" class="form-label">Name</label>
                <input type="text" class="form-control" id="name" name="name" required value="<?= htmlspecialchars($editAccount['name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              </div>
              <div class="row g-3">
                <div class="col-md-6">
                  <label for="type" class="form-label">Typ</label>
                  <select class="form-select" id="type" name="type">
                    <?php foreach (hb_allowed_account_types() as $type): ?>
                      <option value="<?= htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        <?= ($editAccount['type'] ?? '') === $type ? 'selected' : '' ?>>
                        <?= htmlspecialchars(hb_account_type_label($type), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label for="currency" class="form-label">Währung</label>
                  <input type="text" class="form-control" id="currency" name="currency_code" maxlength="3" value="<?= htmlspecialchars($editAccount['currency_code'] ?? ($household['currency_code'] ?? 'EUR'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
                </div>
              </div>
              <div class="mt-3">
                <label for="opening" class="form-label">Startsaldo</label>
                <input type="text" class="form-control" id="opening" name="opening_balance" value="<?= isset($editAccount) ? number_format(((int)$editAccount['opening_balance_cents']) / 100, 2, ',', '.') : '0,00' ?>">
              </div>
              <div class="form-check mt-3">
                <input class="form-check-input" type="checkbox" id="archived" name="is_archived" <?= !empty($editAccount['is_archived']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="archived">
                  Archiviert
                  <span class="ms-1 text-muted" data-bs-toggle="tooltip" title="Archivierte Konten können nicht mehr für neue Transaktionen gewählt werden, bleiben aber in Auswertungen sichtbar.">ℹ️</span>
                </label>
                <div class="form-text">Nutze Archivieren statt Löschen, um alte Buchungen zu behalten.</div>
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
