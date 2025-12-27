<?php
declare(strict_types=1);

$tagSelectorId = $tagSelectorId ?? ('tag-selector-' . uniqid());
$tagSelectorName = $tagSelectorName ?? 'tag_ids[]';
$tagSelectorPlaceholder = $tagSelectorPlaceholder ?? 'Tag suchen...';
$tagSelectorDisabled = !empty($tagSelectorDisabled);
$tagSelectorReadonly = !empty($tagSelectorReadonly);
$tagSelectorTags = $tagSelectorTags ?? [];
$tagSelectorSelected = $tagSelectorSelected ?? [];
$tagModalTarget = $tagModalTarget ?? '#tagModal';
$selectedLookup = array_fill_keys(array_map('intval', $tagSelectorSelected), true);
$disabledAttr = $tagSelectorDisabled ? 'disabled' : '';
$readonlyAttr = $tagSelectorReadonly ? 'readonly' : '';
?>
<div class="hb-tag-selector input-group"
     data-tag-selector
     data-selector-id="<?= htmlspecialchars($tagSelectorId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
     data-selector-name="<?= htmlspecialchars($tagSelectorName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
  <div class="hb-tag-field form-control d-flex flex-wrap align-items-center gap-1" tabindex="0" role="combobox" aria-expanded="false">
    <?php foreach ($tagSelectorTags as $tag): ?>
      <?php if (!isset($selectedLookup[(int)$tag['id']])) { continue; } ?>
      <?php $color = trim((string)($tag['color'] ?? '')); ?>
      <span class="badge hb-tag-chip" data-tag-id="<?= (int)$tag['id'] ?>" style="<?= $color !== '' ? 'background-color:' . htmlspecialchars($color, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ';' : '' ?>">
        <?= htmlspecialchars($tag['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        <button type="button" class="btn-close btn-close-white ms-1 hb-tag-remove" aria-label="Entfernen" <?= $disabledAttr ?>></button>
      </span>
    <?php endforeach; ?>
    <input type="text"
           class="hb-tag-input border-0 flex-grow-1"
           placeholder="<?= htmlspecialchars($tagSelectorPlaceholder, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
           <?= $disabledAttr ?> <?= $readonlyAttr ?>>
  </div>
  <button class="btn btn-outline-secondary hb-tag-add" type="button" data-bs-toggle="modal" data-bs-target="<?= htmlspecialchars($tagModalTarget, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $disabledAttr ?>>+</button>
  <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" <?= $disabledAttr ?>></button>
  <div class="dropdown-menu p-2 hb-tag-dropdown">
    <div class="hb-tag-options">
      <?php foreach ($tagSelectorTags as $tag): ?>
        <?php
        $tagId = (int)$tag['id'];
        $tagName = (string)$tag['name'];
        $tagColor = trim((string)($tag['color'] ?? ''));
        $active = isset($selectedLookup[$tagId]) ? 'active' : '';
        ?>
        <button type="button"
                class="dropdown-item d-flex align-items-center hb-tag-option <?= $active ?>"
                data-tag-id="<?= $tagId ?>"
                data-tag-name="<?= htmlspecialchars($tagName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                data-tag-color="<?= htmlspecialchars($tagColor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <span class="hb-tag-dot" style="<?= $tagColor !== '' ? 'background-color:' . htmlspecialchars($tagColor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ';' : '' ?>"></span>
          <span><?= htmlspecialchars($tagName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="hb-tag-values">
    <?php foreach ($tagSelectorTags as $tag): ?>
      <?php if (!isset($selectedLookup[(int)$tag['id']])) { continue; } ?>
      <input type="hidden" name="<?= htmlspecialchars($tagSelectorName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" value="<?= (int)$tag['id'] ?>">
    <?php endforeach; ?>
  </div>
</div>
