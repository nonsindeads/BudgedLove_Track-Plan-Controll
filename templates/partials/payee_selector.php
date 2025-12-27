<?php
declare(strict_types=1);

$payeeSelectorId = $payeeSelectorId ?? ('payee-selector-' . uniqid());
$payeeSelectorName = $payeeSelectorName ?? 'payee_id';
$payeeSelectorPlaceholder = $payeeSelectorPlaceholder ?? 'Payee suchen...';
$payeeSelectorDisabled = !empty($payeeSelectorDisabled);
$payeeSelectorReadonly = !empty($payeeSelectorReadonly);
$payeeSelectorPayees = $payeeSelectorPayees ?? [];
$payeeSelectorSelected = $payeeSelectorSelected ?? null;
$payeeModalTarget = $payeeModalTarget ?? '#payeeModal';
$payeeSelectorShowAdd = $payeeSelectorShowAdd ?? true;
$disabledAttr = $payeeSelectorDisabled ? 'disabled' : '';
$readonlyAttr = $payeeSelectorReadonly ? 'readonly' : '';
?>
<div class="hb-payee-selector input-group"
     data-chip-selector
     data-selector-id="<?= htmlspecialchars($payeeSelectorId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
     data-selector-name="<?= htmlspecialchars($payeeSelectorName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
     data-selector-multi="false">
  <div class="hb-tag-field form-control d-flex flex-wrap align-items-center gap-1" tabindex="0" role="combobox" aria-expanded="false">
    <?php if ($payeeSelectorSelected): ?>
      <?php
      $selectedId = (int)$payeeSelectorSelected;
      $selected = null;
      foreach ($payeeSelectorPayees as $payee) {
          if ((int)$payee['id'] === $selectedId) {
              $selected = $payee;
              break;
          }
      }
      ?>
      <?php if ($selected): ?>
        <span class="badge hb-tag-chip" data-tag-id="<?= (int)$selected['id'] ?>" style="background-color: #e9ecef; color: #212529;">
          <?= htmlspecialchars($selected['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          <button type="button" class="btn-close ms-1 hb-tag-remove" aria-label="Entfernen" <?= $disabledAttr ?>></button>
        </span>
      <?php endif; ?>
    <?php endif; ?>
    <input type="text"
           class="hb-tag-input border-0 flex-grow-1"
           placeholder="<?= htmlspecialchars($payeeSelectorPlaceholder, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
           <?= $disabledAttr ?> <?= $readonlyAttr ?>>
  </div>
  <?php if ($payeeSelectorShowAdd): ?>
    <button class="btn btn-outline-secondary hb-payee-add" type="button" data-bs-toggle="modal" data-bs-target="<?= htmlspecialchars($payeeModalTarget, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $disabledAttr ?>>+</button>
  <?php endif; ?>
  <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" <?= $disabledAttr ?>></button>
  <div class="dropdown-menu p-2 hb-tag-dropdown">
    <div class="hb-tag-options">
      <?php foreach ($payeeSelectorPayees as $payee): ?>
        <?php
        $payeeId = (int)$payee['id'];
        $active = $payeeSelectorSelected && (int)$payeeSelectorSelected === $payeeId ? 'active' : '';
        ?>
        <button type="button"
                class="dropdown-item d-flex align-items-center hb-tag-option <?= $active ?>"
                data-tag-id="<?= $payeeId ?>"
                data-tag-name="<?= htmlspecialchars((string)$payee['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                data-tag-color="">
          <span class="hb-tag-dot"></span>
          <span><?= htmlspecialchars($payee['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="hb-tag-values">
    <?php if ($payeeSelectorSelected): ?>
      <input type="hidden" name="<?= htmlspecialchars($payeeSelectorName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" value="<?= (int)$payeeSelectorSelected ?>">
    <?php endif; ?>
  </div>
</div>
