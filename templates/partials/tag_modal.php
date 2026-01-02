<?php
declare(strict_types=1);

$tagModalId = $tagModalId ?? 'tagModal';
$tagModalTags = $tagModalTags ?? [];
?>
<div class="modal fade" id="<?= htmlspecialchars($tagModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" tabindex="-1" aria-labelledby="<?= htmlspecialchars($tagModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>Label" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form class="hb-tag-modal-form" method="post" action="/tags.php?action=create">
        <div class="modal-header">
          <h5 class="modal-title" id="<?= htmlspecialchars($tagModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>Label"><?= htmlspecialchars(hb_t('Create new tag'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(hb_t('Close'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label"><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <input type="text" class="form-control" name="name" required>
            <div class="invalid-feedback"><?= htmlspecialchars(hb_t('Name is required.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
          </div>
          <div class="mb-3">
            <label class="form-label"><?= htmlspecialchars(hb_t('Color'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <div class="input-group">
              <input type="text" class="form-control" name="color" placeholder="#ffcc00">
              <input type="color" class="form-control form-control-color" name="color_picker" value="#0d6efd" title="<?= htmlspecialchars(hb_t('Pick color'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
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
