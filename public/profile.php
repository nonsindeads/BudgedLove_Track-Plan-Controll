<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../app/domain.php';

hb_require_login();
$pdo = hb_get_pdo();
$currentUser = hb_current_user($pdo);
$currentHousehold = hb_current_household($pdo);

$pageTitle = 'Profile';
$activeNav = 'profile';
$breadcrumbs = [
    ['label' => 'Profile', 'href' => '/profile.php'],
];

$msg = null;
$error = null;
$conflict = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first = trim((string)($_POST['first_name'] ?? ''));
    $last = trim((string)($_POST['last_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $street = trim((string)($_POST['address_street'] ?? ''));
    $houseNumber = trim((string)($_POST['address_house_number'] ?? ''));
    $postalCode = trim((string)($_POST['address_postal_code'] ?? ''));
    $city = trim((string)($_POST['address_city'] ?? ''));
    $state = trim((string)($_POST['address_state'] ?? ''));
    $extra = trim((string)($_POST['address_extra'] ?? ''));
    $color = trim((string)($_POST['color_hex'] ?? ''));
    $language = hb_normalize_locale($_POST['language'] ?? ($currentUser['language'] ?? 'de'));
    $rowVersion = (int)($_POST['row_version'] ?? 0);

    if ($first === '' || $last === '' || $email === '') {
        $error = hb_t('First name, last name, and email are required.');
    } elseif ($street === '' || $houseNumber === '' || $postalCode === '' || $city === '') {
        $error = hb_t('Street, house number, postal code, and city are required.');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = hb_t('Invalid email address.');
    } elseif ($color !== '' && !preg_match('/^#?[0-9a-fA-F]{6}$/', $color)) {
        $error = hb_t('Invalid color value.');
    }

    if ($error === null) {
        if ($color !== '' && $color[0] !== '#') {
            $color = '#' . $color;
        }
        $address = hb_build_address_string($street, $houseNumber, $postalCode, $city, $state ?: null, $extra ?: null);
        $stmt = $pdo->prepare(
            'update users
                set first_name = :first,
                    last_name = :last,
                    email = :email,
                    address = :address,
                    address_street = :street,
                    address_house_number = :house_number,
                    address_postal_code = :postal_code,
                    address_city = :city,
                    address_state = :state,
                    address_extra = :extra,
                    color_hex = :color,
                    language = :language
              where id = :id and row_version = :row_version'
        );
        $stmt->execute([
            'first' => $first,
            'last' => $last,
            'email' => $email,
            'address' => $address,
            'street' => $street,
            'house_number' => $houseNumber,
            'postal_code' => $postalCode,
            'city' => $city,
            'state' => $state !== '' ? $state : null,
            'extra' => $extra !== '' ? $extra : null,
            'color' => $color !== '' ? $color : null,
            'language' => $language,
            'id' => $currentUser['id'],
            'row_version' => $rowVersion,
        ]);
        if ($stmt->rowCount() === 0) {
            $currentUser = hb_current_user($pdo, true);
            $conflictRows = hb_build_conflict_rows(
                [
                    'first_name' => hb_t('First name'),
                    'last_name' => hb_t('Last name'),
                    'email' => hb_t('Email'),
                    'address_street' => hb_t('Street'),
                    'address_house_number' => hb_t('House number'),
                    'address_postal_code' => hb_t('Postal code'),
                    'address_city' => hb_t('City'),
                    'address_state' => hb_t('State'),
                    'address_extra' => hb_t('Additional details'),
                    'color_hex' => hb_t('Color'),
                    'language' => hb_t('Language'),
                ],
                $currentUser ?? [],
                [
                    'first_name' => $first,
                    'last_name' => $last,
                    'email' => $email,
                    'address_street' => $street,
                    'address_house_number' => $houseNumber,
                    'address_postal_code' => $postalCode,
                    'address_city' => $city,
                    'address_state' => $state,
                    'address_extra' => $extra,
                    'color_hex' => $color,
                    'language' => $language,
                ]
            );
            $conflict = hb_render_conflict_table($conflictRows);
            $currentUser = array_merge($currentUser ?? [], [
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'address_street' => $street,
                'address_house_number' => $houseNumber,
                'address_postal_code' => $postalCode,
                'address_city' => $city,
                'address_state' => $state,
                'address_extra' => $extra,
                'color_hex' => $color,
                'language' => $language,
            ]);
        } else {
            $_SESSION['username'] = $first !== '' ? $first : $_SESSION['username'];
            hb_set_locale($language);
            $msg = hb_t('Profile saved.');
            $currentUser = hb_current_user($pdo, true); // refresh cache
        }
    }
}

ob_start();
$colorValue = trim((string)($currentUser['color_hex'] ?? '')) ?: '#0d6efd';
$languageValue = hb_normalize_locale($currentUser['language'] ?? 'de');
$languageOptions = hb_available_locales();
?>
<div class="container" style="max-width: 720px;">
  <div class="card shadow-sm">
    <div class="card-body">
      <h1 class="h5 mb-3"><?= htmlspecialchars(hb_t('Profile'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
      <?php if (!empty($conflict)): ?>
        <?= $conflict ?>
      <?php endif; ?>
      <?php if ($msg): ?>
        <div class="alert alert-success"><?= htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <?php endif; ?>
      <form method="post" action="/profile.php">
        <input type="hidden" name="row_version" value="<?= (int)($currentUser['row_version'] ?? 0) ?>">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label"><?= htmlspecialchars(hb_t('First name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <input type="text" class="form-control" name="first_name" value="<?= htmlspecialchars($currentUser['first_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label"><?= htmlspecialchars(hb_t('Last name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <input type="text" class="form-control" name="last_name" value="<?= htmlspecialchars($currentUser['last_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
          </div>
        </div>
        <div class="mt-3">
          <label class="form-label"><?= htmlspecialchars(hb_t('Email'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($currentUser['email'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
        </div>
        <div class="mt-3">
          <label class="form-label"><?= htmlspecialchars(hb_t('Address'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label" for="profile-street"><?= htmlspecialchars(hb_t('Street'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="text" class="form-control" id="profile-street" name="address_street" value="<?= htmlspecialchars($currentUser['address_street'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="profile-house-number"><?= htmlspecialchars(hb_t('House number'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="text" class="form-control" id="profile-house-number" name="address_house_number" value="<?= htmlspecialchars($currentUser['address_house_number'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
            </div>
          </div>
          <div class="row g-3 mt-1">
            <div class="col-md-4">
              <label class="form-label" for="profile-postal"><?= htmlspecialchars(hb_t('Postal code'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="text" class="form-control" id="profile-postal" name="address_postal_code" value="<?= htmlspecialchars($currentUser['address_postal_code'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
            </div>
            <div class="col-md-8">
              <label class="form-label" for="profile-city"><?= htmlspecialchars(hb_t('City'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="text" class="form-control" id="profile-city" name="address_city" value="<?= htmlspecialchars($currentUser['address_city'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
            </div>
          </div>
          <div class="row g-3 mt-1">
            <div class="col-md-6">
              <label class="form-label" for="profile-state"><?= htmlspecialchars(hb_t('State (optional)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="text" class="form-control" id="profile-state" name="address_state" value="<?= htmlspecialchars($currentUser['address_state'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="profile-extra"><?= htmlspecialchars(hb_t('Additional details'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="text" class="form-control" id="profile-extra" name="address_extra" value="<?= htmlspecialchars($currentUser['address_extra'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
          </div>
        </div>
        <div class="mt-3">
          <label class="form-label" for="profile-color"><?= htmlspecialchars(hb_t('Color'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <div class="input-group">
            <input type="text"
                   class="form-control"
                   id="profile-color"
                   name="color_hex"
                   value="<?= htmlspecialchars($colorValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                   maxlength="7"
                   pattern="^#?[0-9a-fA-F]{6}$">
            <input type="color"
                   class="form-control form-control-color"
                   id="profile-color-picker"
                   value="<?= htmlspecialchars($colorValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                   aria-label="<?= htmlspecialchars(hb_t('Pick color'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          </div>
          <div class="form-text"><?= htmlspecialchars(hb_t('Color is used for the live feed and chat.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        </div>
        <div class="mt-3">
          <label class="form-label" for="profile-language"><?= htmlspecialchars(hb_t('Language'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <select class="form-select" id="profile-language" name="language">
            <?php foreach ($languageOptions as $lang => $label): ?>
              <option value="<?= htmlspecialchars($lang, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $languageValue === $lang ? 'selected' : '' ?>>
                <?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="btn btn-success mt-3" type="submit"><?= htmlspecialchars(hb_t('Save'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
      </form>
    </div>
  </div>
</div>
<script>
  const hbColorInput = document.getElementById('profile-color');
  const hbColorPicker = document.getElementById('profile-color-picker');
  if (hbColorInput && hbColorPicker) {
    hbColorPicker.addEventListener('input', () => {
      hbColorInput.value = hbColorPicker.value;
    });
    hbColorInput.addEventListener('input', () => {
      const val = hbColorInput.value.trim();
      if (/^#?[0-9a-fA-F]{6}$/.test(val)) {
        hbColorPicker.value = val.startsWith('#') ? val : `#${val}`;
      }
    });
  }
</script>
<?php
$content = ob_get_clean();
$layoutCompact = false;
require __DIR__ . '/../templates/layout.php';
