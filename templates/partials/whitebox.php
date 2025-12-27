<?php
declare(strict_types=1);

$whiteboxTitle = $whiteboxTitle ?? null;
$whiteboxSubtitle = $whiteboxSubtitle ?? null;
$whiteboxContent = $whiteboxContent ?? '';
$whiteboxActions = $whiteboxActions ?? '';
?>
<div class="hb-whitebox mb-3">
  <?php if ($whiteboxTitle): ?>
    <div class="hb-whitebox-header d-flex justify-content-between align-items-start gap-2">
      <div>
        <div class="fw-semibold"><?= htmlspecialchars($whiteboxTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <?php if ($whiteboxSubtitle): ?>
          <div class="text-muted small"><?= htmlspecialchars($whiteboxSubtitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <?php endif; ?>
      </div>
      <?php if ($whiteboxActions): ?>
        <div><?= $whiteboxActions ?></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <div class="hb-whitebox-body">
    <?= $whiteboxContent ?>
  </div>
</div>
