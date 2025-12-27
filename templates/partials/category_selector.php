<?php
declare(strict_types=1);

$categorySelectorId = $categorySelectorId ?? ('category-selector-' . uniqid());
$categorySelectorName = $categorySelectorName ?? 'category_id';
$categorySelectorPlaceholder = $categorySelectorPlaceholder ?? 'Kategorie suchen...';
$categorySelectorDisabled = !empty($categorySelectorDisabled);
$categorySelectorReadonly = !empty($categorySelectorReadonly);
$categorySelectorCategories = $categorySelectorCategories ?? [];
$categorySelectorSelected = $categorySelectorSelected ?? null;
$categoryModalTarget = $categoryModalTarget ?? '#categoryModal';
$categorySelectorShowAdd = $categorySelectorShowAdd ?? true;
$disabledAttr = $categorySelectorDisabled ? 'disabled' : '';
$readonlyAttr = $categorySelectorReadonly ? 'readonly' : '';
?>
<div class="hb-category-selector input-group"
     data-chip-selector
     data-selector-id="<?= htmlspecialchars($categorySelectorId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
     data-selector-name="<?= htmlspecialchars($categorySelectorName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
     data-selector-multi="false">
  <div class="hb-tag-field form-control d-flex flex-wrap align-items-center gap-1" tabindex="0" role="combobox" aria-expanded="false">
    <?php if ($categorySelectorSelected): ?>
      <?php
      $selectedId = (int)$categorySelectorSelected;
      $selected = null;
      foreach ($categorySelectorCategories as $cat) {
          if ((int)$cat['id'] === $selectedId) {
              $selected = $cat;
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
           placeholder="<?= htmlspecialchars($categorySelectorPlaceholder, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
           <?= $disabledAttr ?> <?= $readonlyAttr ?>>
  </div>
  <?php if ($categorySelectorShowAdd): ?>
    <button class="btn btn-outline-secondary hb-category-add" type="button" data-bs-toggle="modal" data-bs-target="<?= htmlspecialchars($categoryModalTarget, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $disabledAttr ?>>+</button>
  <?php endif; ?>
  <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" <?= $disabledAttr ?>></button>
  <div class="dropdown-menu p-2 hb-tag-dropdown">
    <div class="hb-tag-options">
      <?php foreach ($categorySelectorCategories as $cat): ?>
        <?php
        $catId = (int)$cat['id'];
        $active = $categorySelectorSelected && (int)$categorySelectorSelected === $catId ? 'active' : '';
        ?>
        <button type="button"
                class="dropdown-item d-flex align-items-center hb-tag-option <?= $active ?>"
                data-tag-id="<?= $catId ?>"
                data-tag-name="<?= htmlspecialchars((string)$cat['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                data-tag-color="">
          <span class="hb-tag-dot"></span>
          <span><?= htmlspecialchars($cat['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <span class="text-muted small ms-auto"><?= htmlspecialchars($cat['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="hb-tag-values">
    <?php if ($categorySelectorSelected): ?>
      <input type="hidden" name="<?= htmlspecialchars($categorySelectorName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" value="<?= (int)$categorySelectorSelected ?>">
    <?php endif; ?>
  </div>
</div>
