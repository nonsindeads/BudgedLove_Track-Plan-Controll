<?php
declare(strict_types=1);

$payeeModalId = $payeeModalId ?? 'payeeModal';
?>
<div class="modal fade" id="<?= htmlspecialchars($payeeModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" tabindex="-1" aria-labelledby="<?= htmlspecialchars($payeeModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>-label" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content hb-whitebox">
      <form class="hb-payee-modal-form">
        <div class="modal-header">
          <h5 class="modal-title" id="<?= htmlspecialchars($payeeModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>-label"><?= htmlspecialchars(hb_t('Create new payee'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(hb_t('Close'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label" for="<?= htmlspecialchars($payeeModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>-name"><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <input type="text" class="form-control" id="<?= htmlspecialchars($payeeModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>-name" name="name" required>
            <div class="invalid-feedback" data-default-message="<?= htmlspecialchars(hb_t('Name is required.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <?= htmlspecialchars(hb_t('Name is required.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="<?= htmlspecialchars($payeeModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>-address"><?= htmlspecialchars(hb_t('Address'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <textarea class="form-control" id="<?= htmlspecialchars($payeeModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>-address" name="address_text" rows="2"></textarea>
          </div>
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label" for="<?= htmlspecialchars($payeeModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>-iban">IBAN</label>
              <input type="text" class="form-control" id="<?= htmlspecialchars($payeeModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>-iban" name="iban">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="<?= htmlspecialchars($payeeModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>-bic">BIC</label>
              <input type="text" class="form-control" id="<?= htmlspecialchars($payeeModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>-bic" name="bic">
            </div>
          </div>
          <div class="mt-3">
            <label class="form-label" for="<?= htmlspecialchars($payeeModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>-notes"><?= htmlspecialchars(hb_t('Notes'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <textarea class="form-control" id="<?= htmlspecialchars($payeeModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>-notes" name="notes" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= htmlspecialchars(hb_t('Cancel'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
          <button type="submit" class="btn btn-primary"><?= htmlspecialchars(hb_t('Save'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>
