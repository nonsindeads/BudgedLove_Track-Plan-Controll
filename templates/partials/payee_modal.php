<?php
declare(strict_types=1);

$payeeModalId = $payeeModalId ?? 'payeeModal';
?>
<div class="modal fade" id="<?= htmlspecialchars($payeeModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" tabindex="-1" aria-labelledby="<?= htmlspecialchars($payeeModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>-label" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content hb-whitebox">
      <form class="hb-payee-modal-form">
        <div class="modal-header">
          <h5 class="modal-title" id="<?= htmlspecialchars($payeeModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>-label">Neuer Empfänger</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label" for="<?= htmlspecialchars($payeeModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>-name">Name</label>
            <input type="text" class="form-control" id="<?= htmlspecialchars($payeeModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>-name" name="name" required>
            <div class="invalid-feedback">Name ist erforderlich.</div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="<?= htmlspecialchars($payeeModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>-address">Adresse</label>
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
            <label class="form-label" for="<?= htmlspecialchars($payeeModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>-notes">Notizen</label>
            <textarea class="form-control" id="<?= htmlspecialchars($payeeModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>-notes" name="notes" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
          <button type="submit" class="btn btn-primary">Speichern</button>
        </div>
      </form>
    </div>
  </div>
</div>
