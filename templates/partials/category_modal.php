<?php
declare(strict_types=1);

$categoryModalId = $categoryModalId ?? 'categoryModal';
?>
<div class="modal fade" id="<?= htmlspecialchars($categoryModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" tabindex="-1" aria-labelledby="<?= htmlspecialchars($categoryModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>Label" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form class="hb-category-modal-form" method="post" action="/categories.php?action=create">
        <div class="modal-header">
          <h5 class="modal-title" id="<?= htmlspecialchars($categoryModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>Label">Neue Kategorie erstellen</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Name</label>
            <input type="text" class="form-control" name="name" required>
            <div class="invalid-feedback">Name ist erforderlich.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Typ</label>
            <select class="form-select" name="type" required>
              <option value="expense" selected>Ausgabe</option>
              <option value="income">Einnahme</option>
            </select>
          </div>
          <input type="hidden" name="action" value="create">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
          <button type="submit" class="btn btn-primary">Speichern</button>
        </div>
      </form>
    </div>
  </div>
</div>
