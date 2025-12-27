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
          <h5 class="modal-title" id="<?= htmlspecialchars($tagModalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>Label">Neues Tag erstellen</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Name</label>
            <input type="text" class="form-control" name="name" required>
            <div class="invalid-feedback">Name ist erforderlich.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Farbe</label>
            <div class="input-group">
              <input type="text" class="form-control" name="color" placeholder="#ffcc00">
              <input type="color" class="form-control form-control-color" name="color_picker" value="#0d6efd" title="Farbe wählen">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Übergeordnetes Element</label>
            <select class="form-select" name="parent_id">
              <option value="">Keins</option>
              <?php foreach ($tagModalTags as $tag): ?>
                <option value="<?= (int)$tag['id'] ?>"><?= htmlspecialchars($tag['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <?php endforeach; ?>
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
