<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../app/domain.php';

hb_require_login();
$pdo = hb_get_pdo();
$currentUser = hb_current_user($pdo);
$currentHousehold = hb_current_household($pdo);

$pageTitle = 'Profil';
$activeNav = 'profile';
$breadcrumbs = [
    ['label' => 'Profil', 'href' => '/profile.php'],
];

$msg = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first = trim((string)($_POST['first_name'] ?? ''));
    $last = trim((string)($_POST['last_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));

    if ($first === '' || $last === '' || $email === '') {
        $error = 'Vorname, Nachname und E-Mail sind Pflicht.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Ungültige E-Mail.';
    }

    if ($error === null) {
        $stmt = $pdo->prepare(
            'update users set first_name = :first, last_name = :last, email = :email, address = :address where id = :id'
        );
        $stmt->execute([
            'first' => $first,
            'last' => $last,
            'email' => $email,
            'address' => $address !== '' ? $address : null,
            'id' => $currentUser['id'],
        ]);
        $_SESSION['username'] = $first !== '' ? $first : $_SESSION['username'];
        $msg = 'Profil gespeichert.';
        $currentUser = hb_current_user($pdo); // refresh cache
    }
}

ob_start();
?>
<div class="container" style="max-width: 720px;">
  <div class="card shadow-sm">
    <div class="card-body">
      <h1 class="h5 mb-3">Profil</h1>
      <?php if ($msg): ?>
        <div class="alert alert-success"><?= htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <?php endif; ?>
      <form method="post" action="/profile.php">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Vorname</label>
            <input type="text" class="form-control" name="first_name" value="<?= htmlspecialchars($currentUser['first_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Nachname</label>
            <input type="text" class="form-control" name="last_name" value="<?= htmlspecialchars($currentUser['last_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
          </div>
        </div>
        <div class="mt-3">
          <label class="form-label">E-Mail</label>
          <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($currentUser['email'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
        </div>
        <div class="mt-3">
          <label class="form-label">Adresse</label>
          <textarea class="form-control" name="address" rows="2"><?= htmlspecialchars($currentUser['address'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
        </div>
        <button class="btn btn-success mt-3" type="submit">Speichern</button>
      </form>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
$layoutCompact = false;
require __DIR__ . '/../templates/layout.php';
