<?php
declare(strict_types=1);

$categoryModalId = $categoryModalId ?? 'categoryModal';
?>
<div class="modal fade" id="<?= htmlspecialchars($categoryModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" tabindex="-1" aria-labelledby="<?= htmlspecialchars($categoryModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>Label" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form class="hb-category-modal-form" method="post" action="/categories.php?action=create">
        <div class="modal-header">
          <h5 class="modal-title" id="<?= htmlspecialchars($categoryModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>Label"><?= htmlspecialchars(hb_t('Create new category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(hb_t('Close'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label"><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <input type="text" class="form-control" name="name" required>
            <div class="invalid-feedback" data-default-message="<?= htmlspecialchars(hb_t('Name is required.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <?= htmlspecialchars(hb_t('Name is required.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label"><?= htmlspecialchars(hb_t('Type'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <select class="form-select" name="type" required>
              <option value="expense" selected><?= htmlspecialchars(hb_t('Expense'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <option value="income"><?= htmlspecialchars(hb_t('Income'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
            </select>
          </div>
          <input type="hidden" name="action" value="create">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= htmlspecialchars(hb_t('Cancel'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
          <button type="submit" class="btn btn-primary"><?= htmlspecialchars(hb_t('Save'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>
