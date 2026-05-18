<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

hb_require_login();
$pdo = hb_get_pdo();
$household = hb_require_household($pdo);
$currentHousehold = $household;
$currentUser = hb_current_user($pdo);
$pageTitle = 'Saving goals';
$activeNav = 'saving_goals';
$breadcrumbs = [
    ['label' => 'Saving goals', 'href' => '/saving_goals.php'],
];

$msg = (string)($_GET['msg'] ?? '');
$error = null;

function hb_money(int $cents): string
{
    return number_format($cents / 100, 2, ',', '.') . ' €';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create_goal') {
        $name = trim((string)($_POST['name'] ?? ''));
        $target = hb_parse_cents((string)($_POST['target_amount'] ?? ''));
        $sourceAccountId = (int)($_POST['source_account_id'] ?? 0);
        if ($name === '') {
            $error = hb_t('Name is required.');
        } elseif ($target === null || $target <= 0) {
            $error = hb_t('Amount is invalid.');
        } elseif ($sourceAccountId < 1) {
            $error = hb_t('Account is required.');
        } else {
            $check = $pdo->prepare('select id from accounts where household_id = :hid and id = :id');
            $check->execute(['hid' => $household['id'], 'id' => $sourceAccountId]);
            if (!$check->fetch()) {
                $error = hb_t('Account not found.');
            } else {
                $ins = $pdo->prepare(
                    "insert into saving_goals
                        (household_id, name, target_amount_cents, current_amount_cents, source_account_id, storage_type, priority, status, is_optional, created_at, updated_at)
                     values
                        (:hid, :name, :target, 0, :source, 'virtual', 'normal', 'active', true, :now, :now)"
                );
                $ins->execute([
                    'hid' => $household['id'],
                    'name' => $name,
                    'target' => $target,
                    'source' => $sourceAccountId,
                    'now' => gmdate('Y-m-d H:i:s'),
                ]);
                header('Location: /saving_goals.php?msg=created');
                exit;
            }
        }
    } elseif ($action === 'add_contribution') {
        $goalId = (int)($_POST['saving_goal_id'] ?? 0);
        $amount = hb_parse_cents((string)($_POST['amount'] ?? ''));
        $date = trim((string)($_POST['contribution_date'] ?? ''));
        if ($goalId < 1) {
            $error = hb_t('Saving goal not found.');
        } elseif ($amount === null || $amount === 0) {
            $error = hb_t('Amount is invalid.');
        } elseif ($date === '') {
            $error = hb_t('Date is required.');
        } else {
            $check = $pdo->prepare('select id from saving_goals where household_id = :hid and id = :id');
            $check->execute(['hid' => $household['id'], 'id' => $goalId]);
            if (!$check->fetch()) {
                $error = hb_t('Saving goal not found.');
            } else {
                $db = hb_dbal_household();
                $db->beginTransaction();
                try {
                    $db->insert('saving_goal_contributions', [
                        'household_id' => $household['id'],
                        'saving_goal_id' => $goalId,
                        'amount_cents' => $amount,
                        'contribution_date' => $date,
                        'note' => trim((string)($_POST['note'] ?? '')) ?: null,
                    ]);
                    $db->executeStatement(
                        'update saving_goals
                            set current_amount_cents = current_amount_cents + :amount,
                                updated_at = :now
                          where household_id = :hid and id = :id',
                        [
                            'amount' => $amount,
                            'now' => gmdate('Y-m-d H:i:s'),
                            'hid' => $household['id'],
                            'id' => $goalId,
                        ]
                    );
                    $db->commit();
                } catch (Throwable $e) {
                    $db->rollBack();
                    throw $e;
                }
                header('Location: /saving_goals.php?msg=contribution_added');
                exit;
            }
        }
    }
}

$accountsStmt = $pdo->prepare('select id, name from accounts where household_id = :hid and is_archived = false order by name asc');
$accountsStmt->execute(['hid' => $household['id']]);
$accounts = $accountsStmt->fetchAll();

$goalsStmt = $pdo->prepare(
    'select sg.id, sg.name, sg.target_amount_cents, sg.current_amount_cents, sg.status, sg.priority, a.name as account_name
       from saving_goals sg
  left join accounts a on a.id = sg.source_account_id
      where sg.household_id = :hid
      order by sg.created_at desc, sg.id desc'
);
$goalsStmt->execute(['hid' => $household['id']]);
$goals = $goalsStmt->fetchAll();

ob_start();
?>
<div class="container py-3 py-md-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h3 m-0"><?= htmlspecialchars(hb_t('Saving goals'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
  </div>
  <?php if ($msg !== ''): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Saved.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($error !== null): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="card mb-3">
    <div class="card-header"><?= htmlspecialchars(hb_t('Create saving goal'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <div class="card-body">
      <form method="post" class="row g-2">
        <?= hb_csrf_field() ?>
        <input type="hidden" name="action" value="create_goal">
        <div class="col-12 col-md-4">
          <label class="form-label"><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input type="text" name="name" class="form-control" required>
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label"><?= htmlspecialchars(hb_t('Target amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input type="text" name="target_amount" class="form-control" placeholder="180,00" required>
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label"><?= htmlspecialchars(hb_t('Account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <select name="source_account_id" class="form-select" required>
            <option value=""><?= htmlspecialchars(hb_t('Please choose'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
            <?php foreach ($accounts as $acc): ?>
              <option value="<?= (int)$acc['id'] ?>"><?= htmlspecialchars((string)$acc['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-2 d-grid">
          <label class="form-label d-none d-md-block">&nbsp;</label>
          <button class="btn btn-primary" type="submit"><?= htmlspecialchars(hb_t('Create'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><?= htmlspecialchars(hb_t('Saving goals'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-striped mb-0">
          <thead>
            <tr>
              <th><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(hb_t('Account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(hb_t('Saved'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(hb_t('Target'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(hb_t('Status'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(hb_t('Contribution'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($goals as $goal): ?>
              <tr>
                <td><?= htmlspecialchars((string)$goal['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($goal['account_name'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td><?= htmlspecialchars(hb_money((int)$goal['current_amount_cents']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td><?= htmlspecialchars(hb_money((int)$goal['target_amount_cents']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)$goal['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td>
                  <form method="post" class="d-flex gap-1">
                    <?= hb_csrf_field() ?>
                    <input type="hidden" name="action" value="add_contribution">
                    <input type="hidden" name="saving_goal_id" value="<?= (int)$goal['id'] ?>">
                    <input type="text" name="amount" class="form-control form-control-sm" placeholder="10,00" required>
                    <input type="date" name="contribution_date" class="form-control form-control-sm" value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
                    <button type="submit" class="btn btn-sm btn-outline-primary"><?= htmlspecialchars(hb_t('Add'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$goals): ?>
              <tr><td colspan="6" class="text-muted text-center py-3"><?= htmlspecialchars(hb_t('No entries.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php
$content = (string)ob_get_clean();
require __DIR__ . '/../templates/layout.php';
