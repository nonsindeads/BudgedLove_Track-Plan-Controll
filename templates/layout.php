<?php
declare(strict_types=1);

$liveLog = [];
$liveChat = [];
$quickAddAccounts = [];
$quickAddCategories = [];
$quickAddPayees = [];
$quickAddDefaults = [
  'type' => 'expense',
  'account_id' => null,
  'category_id' => null,
  'payee_id' => null,
];
$tableLabels = [
  'users' => 'Users',
  'households' => 'Households',
  'household_members' => 'Members',
  'accounts' => 'Accounts',
  'transactions' => 'Transactions',
  'transaction_splits' => 'Splits',
  'transaction_tags' => 'Transaction tags',
  'categories' => 'Categories',
  'tags' => 'Tags',
  'payees' => 'Payees',
  'payee_mappings' => 'Payee mapping',
  'recurring_payments' => 'Recurring payments',
  'planned_payments' => 'Monthly plan',
  'open_cases' => 'Open cases',
  'month_closures' => 'Month close',
  'attachments' => 'Attachments',
  'chat_messages' => 'Chat',
  'imports' => 'Imports',
];
$actionLabels = [
  'insert' => 'Created',
  'update' => 'Updated',
  'delete' => 'Deleted',
];
$liveTranslationMap = [];
if (function_exists('hb_t')) {
  $liveTranslationKeys = array_merge(array_values($tableLabels), array_values($actionLabels), ['System']);
  foreach (array_unique($liveTranslationKeys) as $key) {
    $liveTranslationMap[$key] = hb_t($key);
  }
}
if (!empty($currentHousehold['id']) && function_exists('hb_get_pdo')) {
  try {
    $pdo = hb_get_pdo();
    $limit = 50;
    $quickAccountsStmt = $pdo->prepare('select id, name from accounts where household_id = :hid and is_archived = false order by name asc');
    $quickAccountsStmt->execute(['hid' => (int)$currentHousehold['id']]);
    $quickAddAccounts = $quickAccountsStmt->fetchAll() ?: [];

    $quickCategoriesStmt = $pdo->prepare('select id, name, type from categories where household_id = :hid and is_active = true order by type asc, sort_order asc, name asc');
    $quickCategoriesStmt->execute(['hid' => (int)$currentHousehold['id']]);
    $quickAddCategories = $quickCategoriesStmt->fetchAll() ?: [];

    $quickPayeesStmt = $pdo->prepare('select id, name from payees where household_id = :hid order by name asc limit 100');
    $quickPayeesStmt->execute(['hid' => (int)$currentHousehold['id']]);
    $quickAddPayees = $quickPayeesStmt->fetchAll() ?: [];

    $quickDefaultStmt = $pdo->prepare(
      "select type, account_id, category_id, payee_id
         from transactions
        where household_id = :hid
          and is_reviewed = true
          and type in ('income', 'expense')
        order by booking_date desc, id desc
        limit 1"
    );
    $quickDefaultStmt->execute(['hid' => (int)$currentHousehold['id']]);
    $quickDefaultRow = $quickDefaultStmt->fetch();
    if ($quickDefaultRow) {
      $quickAddDefaults = array_merge($quickAddDefaults, $quickDefaultRow);
    }
    $truncate = static function (string $value, int $max = 48): string {
      $value = trim($value);
      $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
      if ($value === '' || $length <= $max) {
        return $value;
      }
      if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max - 1) . '…';
      }
      return substr($value, 0, $max - 1) . '…';
    };
    $decodeJson = static function ($value): array {
      if (is_array($value)) {
        return $value;
      }
      if (is_string($value) && $value !== '') {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
          return $decoded;
        }
      }
      return [];
    };
    $extractTitle = static function (array $data): string {
      $fields = ['title', 'name', 'counterparty_name', 'note', 'description', 'subject', 'label'];
      foreach ($fields as $field) {
        $value = trim((string)($data[$field] ?? ''));
        if ($value !== '') {
          return $value;
        }
      }
      return '';
    };
    $tableRoutes = [
      'transactions' => '/transactions.php?action=show&id=',
      'planned_payments' => '/plan.php',
      'recurring_payments' => '/recurring.php',
      'open_bookings' => '/open_bookings.php',
      'open_cases' => '/open_cases.php',
      'categories' => '/categories.php',
      'tags' => '/tags.php',
      'payees' => '/payees.php',
      'payee_mappings' => '/payee_mapping.php',
      'accounts' => '/accounts.php',
      'imports' => '/import.php',
      'month_closures' => '/month_close.php',
    ];
    $importantTables = ['imports' => true, 'month_closures' => true];
    $auditStmt = $pdo->prepare(
      'select e.event_at, e.username, e.user_id, e.action, e.table_name, e.entity_id, e.data_new, e.data_old,
              u.username as user_name, u.color_hex
         from audit_events e
         left join users u on u.id = e.user_id
        where e.household_id = :hid
        order by e.event_at desc
        limit :limit'
    );
    $auditStmt->bindValue(':hid', (int)$currentHousehold['id'], PDO::PARAM_INT);
    $auditStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $auditStmt->execute();
    foreach ($auditStmt->fetchAll() as $row) {
      $tableName = (string)($row['table_name'] ?? '');
      $label = $tableLabels[$tableName] ?? $tableName;
      $actionKey = (string)($row['action'] ?? '');
      $action = $actionLabels[$actionKey] ?? $actionKey;
      $dataNew = $decodeJson($row['data_new'] ?? null);
      $dataOld = $decodeJson($row['data_old'] ?? null);
      $title = $extractTitle($dataNew) ?: $extractTitle($dataOld);
      $title = $truncate($title, 52);
      $entityId = trim((string)($row['entity_id'] ?? ''));
      $user = $row['username'] ?: ($row['user_name'] ?? '') ?: hb_t('System');
      $color = trim((string)($row['color_hex'] ?? ''));
      $route = $tableRoutes[$tableName] ?? '';
      $url = '';
      if ($route !== '') {
        if ($tableName === 'transactions' && $entityId !== '') {
          $url = $route . urlencode($entityId);
        } else {
          $url = $route;
        }
      }
      $liveLog[] = [
        'user' => $user,
        'color' => $color !== '' ? $color : null,
        'timestamp' => $row['event_at'],
        'action' => $actionKey,
        'table' => $tableName,
        'important' => isset($importantTables[$tableName]),
        'label' => $label,
        'title' => $title,
        'entity' => $entityId,
        'action_label' => $action,
        'url' => $url,
      ];
    }
    $chatStmt = $pdo->prepare(
      'select c.message, c.created_at, u.username, u.color_hex
         from chat_messages c
         join users u on u.id = c.user_id
        where c.household_id = :hid
        order by c.created_at desc
        limit :limit'
    );
    $chatStmt->bindValue(':hid', (int)$currentHousehold['id'], PDO::PARAM_INT);
    $chatStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $chatStmt->execute();
    foreach ($chatStmt->fetchAll() as $row) {
      $user = $row['username'] ?: 'System';
      $color = trim((string)($row['color_hex'] ?? ''));
      $liveChat[] = [
        'user' => $user,
        'color' => $color !== '' ? $color : null,
        'timestamp' => $row['created_at'],
        'message' => (string)($row['message'] ?? ''),
      ];
    }
  } catch (Throwable $e) {
    $liveLog = [];
    $liveChat = [];
  }
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars(hb_get_locale(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars(hb_t($pageTitle ?? 'BudgetLove'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
  <link rel="icon" href="/assets/logo.svg" type="image/svg+xml">
  <link rel="manifest" href="/manifest.webmanifest">
  <meta name="theme-color" content="#1d4ed8">
  <link rel="apple-touch-icon" href="/assets/logo.svg">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="BudgetLove">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <script src="https://unpkg.com/htmx.org@1.9.12"></script>
  <style>
    body {
      background-color: #f6f8fb;
      min-height: 100dvh;
    }
    body.hb-auth {
      background: radial-gradient(circle at top left, #fdf9f2 0%, #f2f7fb 45%, #eef7f0 100%);
    }
    .hb-shell {
      display: grid;
      grid-template-columns: 260px 1fr;
      min-height: 100dvh;
    }
    .hb-shell > .d-flex.flex-column {
      min-width: 0;
    }
    .hb-auth-shell {
      position: relative;
      margin: -1.5rem;
      padding: 2.5rem 1.5rem 3rem;
      min-height: calc(100dvh - 0px);
      overflow: hidden;
    }
    .hb-auth-orb {
      position: absolute;
      width: 520px;
      height: 520px;
      border-radius: 999px;
      z-index: -1;
    }
    .hb-auth-orb-primary {
      top: -200px;
      right: -160px;
      background: radial-gradient(circle, rgba(14, 165, 233, 0.22) 0%, rgba(14, 165, 233, 0) 70%);
    }
    .hb-auth-orb-secondary {
      bottom: -200px;
      left: -160px;
      background: radial-gradient(circle, rgba(22, 163, 74, 0.18) 0%, rgba(22, 163, 74, 0) 70%);
    }
    .hb-auth-card {
      border-radius: 24px;
      border: 1px solid rgba(148, 163, 184, 0.2);
      box-shadow: 0 26px 60px rgba(15, 23, 42, 0.08);
    }
    .hb-auth-brand {
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }
    .hb-auth-logo {
      width: 44px;
      height: 44px;
    }
    .hb-sidebar {
      background: #0f172a;
      color: #f8f9fa;
      width: 260px;
      position: sticky;
      top: 0;
      height: 100dvh;
      overflow-y: auto;
      border-right: 1px solid rgba(255,255,255,0.08);
    }
    .hb-sidebar-header {
      background: linear-gradient(135deg, #1d4ed8 0%, #0ea5e9 100%);
    }
    .hb-logo {
      width: 42px;
      height: 42px;
    }
    .hb-sidebar-section {
      padding: 0 0.25rem 0.75rem;
    }
    .hb-sidebar-section-title {
      color: rgba(255,255,255,0.55);
      font-weight: 600;
      letter-spacing: 0.08em;
      padding: 0 1.25rem 0.4rem;
    }
    .hb-sidebar .nav-link {
      color: #e9ecef;
      padding: 0.65rem 1.25rem;
      border-radius: 12px;
      margin: 0 0.75rem 0.35rem 0.75rem;
    }
    .hb-sidebar .nav-link:hover {
      background-color: rgba(255,255,255,0.08);
      color: #fff;
    }
    .hb-sidebar .nav-link.active {
      background-color: #fff;
      color: #0f172a;
      font-weight: 600;
      box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }
    .hb-avatar {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: #0d6efd;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 0.9rem;
    }
    .hb-header {
      position: sticky;
      top: 0;
      z-index: 1030;
      display: flex;
      flex-direction: column;
    }
    .hb-header-row {
      display: flex;
      align-items: center;
      gap: 0.6rem;
      padding: 0.55rem 1rem;
      min-width: 0;
    }
    .hb-header-row-primary {
      gap: 0.6rem;
    }
    .hb-header-row-secondary {
      padding-top: 0;
      gap: 0.5rem;
      color: #6c757d;
      border-top: 1px solid rgba(15, 23, 42, 0.05);
      padding-block: 0.4rem;
    }
    .hb-header-burger {
      flex: 0 0 auto;
      width: 38px;
      height: 38px;
      padding: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .hb-header-icon {
      flex: 0 0 auto;
      width: 38px;
      height: 38px;
      padding: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .hb-header-title {
      flex: 1 1 auto;
      min-width: 0;
      margin: 0;
      font-size: 1.05rem;
      font-weight: 600;
      line-height: 1.2;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .hb-header-account {
      flex: 0 1 auto;
      min-width: 0;
      max-width: 11rem;
      margin: 0;
    }
    .hb-header-account .form-select {
      min-width: 0;
      width: 100%;
    }
    .hb-header-crumbs {
      flex: 1 1 auto;
      min-width: 0;
      overflow: hidden;
    }
    .hb-header-crumbs .breadcrumb {
      flex-wrap: nowrap;
      margin: 0;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .hb-header-crumbs .breadcrumb-item,
    .hb-header-crumbs .breadcrumb-item a {
      color: inherit;
    }
    .hb-header-crumbs .breadcrumb-item.active {
      color: #0f172a;
      font-weight: 500;
    }
    .hb-header-crumbs .breadcrumb-item + .breadcrumb-item::before {
      color: rgba(15, 23, 42, 0.3);
    }
    .hb-header-household {
      flex: 0 0 auto;
      align-items: center;
      gap: 0.3rem;
      max-width: 14rem;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .hb-header-lang {
      flex: 0 0 auto;
      margin-left: auto;
    }
    .hb-header-lang .dropdown-item {
      border-radius: 8px;
    }
    .hb-header-lang-code {
      font-weight: 600;
      letter-spacing: 0.04em;
      font-size: 0.75rem;
    }
    body.hb-sidebar-collapsed .hb-shell {
      grid-template-columns: 84px 1fr;
    }
    body.hb-sidebar-collapsed .hb-sidebar {
      width: 84px;
    }
    body.hb-sidebar-collapsed .hb-sidebar .hb-sidebar-brand,
    body.hb-sidebar-collapsed .hb-sidebar .hb-sidebar-section-title,
    body.hb-sidebar-collapsed .hb-sidebar .hb-sidebar-profile-text,
    body.hb-sidebar-collapsed .hb-sidebar .hb-sidebar-action-text {
      display: none;
    }
    body.hb-sidebar-collapsed .hb-sidebar .nav-link {
      justify-content: center;
      padding: 0.65rem 0.5rem;
    }
    body.hb-sidebar-collapsed .hb-sidebar .nav-link span {
      display: none;
    }
    body.hb-sidebar-collapsed .hb-sidebar .hb-logo {
      width: 36px;
      height: 36px;
    }
    body.hb-sidebar-collapsed .hb-sidebar .hb-avatar {
      margin-right: 0;
    }
    body.hb-sidebar-collapsed .hb-sidebar-footer {
      padding-left: 0.5rem;
      padding-right: 0.5rem;
    }
    body.hb-sidebar-collapsed .hb-sidebar-actions .btn {
      padding: 0.35rem;
    }
    body.hb-sidebar-collapsed .hb-sidebar-actions .btn i {
      margin-right: 0;
    }
    .offcanvas {
      height: 100dvh;
    }
    .hb-offcanvas {
      width: 85vw;
      max-width: 320px;
    }
    .hb-offcanvas-nav .offcanvas-body {
      padding: 0;
      overflow-y: auto;
    }
    .hb-offcanvas-live {
      width: 90vw;
      max-width: 360px;
    }
    .hb-offcanvas-live .offcanvas-body {
      padding: 0;
      overflow: hidden;
    }
    .offcanvas .hb-sidebar {
      width: 100%;
      height: auto;
      position: static;
      border-right: none;
    }
    body.hb-sidebar-collapsed .offcanvas .hb-sidebar {
      width: 100%;
    }
    .hb-live-body {
      display: flex;
      flex-direction: column;
      min-height: 0;
      height: 100%;
      gap: 0.75rem;
      padding: 0.75rem 1rem 1rem;
    }
    .hb-live-log,
    .hb-live-chat {
      border: 1px solid rgba(0,0,0,0.08);
      border-radius: 12px;
      background: rgba(248,249,250,0.7);
    }
    .hb-live-log {
      flex: 1 1 auto;
      min-height: 140px;
      overflow-y: auto;
      padding: 0.5rem 0.75rem;
    }
    .hb-live-chat {
      max-height: 200px;
      overflow-y: auto;
      padding: 0.5rem 0.75rem;
    }
    .hb-live-panel {
      display: flex;
      width: 360px;
      max-width: 90vw;
      background: #fff;
      border-left: 1px solid rgba(0,0,0,0.08);
      height: 100dvh;
      position: fixed;
      top: 0;
      right: 0;
      z-index: 1040;
      flex-direction: column;
      transform: translateX(100%);
      transition: transform 0.2s ease;
      pointer-events: none;
      box-shadow: -12px 0 32px rgba(15,23,42,0.18);
    }
    .hb-live-panel .hb-live-body {
      flex: 1 1 auto;
      min-height: 0;
      padding: 0.75rem 1rem 1rem;
    }
    .hb-live-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0.75rem 1rem;
      border-bottom: 1px solid rgba(0,0,0,0.08);
    }
    .hb-live-section {
      display: flex;
      flex-direction: column;
      min-height: 0;
      gap: 0.5rem;
    }
    .hb-live-section-grow {
      flex: 1 1 auto;
    }
    .hb-live-entry,
    .hb-chat-entry {
      display: flex;
      flex-direction: column;
      gap: 0.15rem;
      padding: 0.45rem 0;
      border-bottom: 1px solid rgba(15,23,42,0.08);
    }
    .hb-live-entry:last-child,
    .hb-chat-entry:last-child {
      border-bottom: none;
    }
    .hb-live-meta,
    .hb-chat-meta {
      display: flex;
      align-items: center;
      gap: 0.35rem;
      font-size: 0.75rem;
      color: #6c757d;
    }
    .hb-live-name,
    .hb-chat-name {
      font-weight: 600;
    }
    .hb-live-text,
    .hb-chat-text {
      font-size: 0.85rem;
    }
    .hb-live-text a {
      color: #0d6efd;
      text-decoration: none;
    }
    .hb-live-backdrop {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.35);
      z-index: 1030;
    }
    .hb-live-filters {
      display: none;
      gap: 0.5rem;
      flex-wrap: wrap;
    }
    .hb-live-filters.show {
      display: flex;
    }
    body.hb-live-open {
      overflow: hidden;
    }
    body.hb-live-open .hb-live-panel {
      transform: translateX(0);
      pointer-events: auto;
    }
    body.hb-live-open .hb-live-backdrop {
      display: block;
    }
    .hb-live-footer {
      margin-top: auto;
    }
    .offcanvas-header {
      padding: 0.75rem 1rem;
    }
    .hb-content {
      padding: 1.5rem;
    }
    .hb-forecast-chart {
      height: 240px;
    }
    .hb-tag-field {
      min-height: 38px;
      cursor: text;
    }
    .hb-tag-input {
      min-width: 120px;
      outline: none;
      background: transparent;
    }
    .hb-tag-chip {
      display: inline-flex;
      align-items: center;
      gap: 0.25rem;
    }
    .hb-tag-chip .btn-close {
      filter: brightness(1.4);
    }
    .hb-tag-dot {
      width: 10px;
      height: 10px;
      border-radius: 999px;
      background: #adb5bd;
      margin-right: 0.35rem;
      flex-shrink: 0;
    }
    .hb-tag-dropdown {
      max-height: 240px;
      overflow-y: auto;
      min-width: 220px;
    }
    .hb-whitebox {
      background: #fff;
      border: 1px solid rgba(15, 23, 42, 0.08);
      border-radius: 16px;
      box-shadow: 0 12px 24px rgba(15, 23, 42, 0.06);
      overflow: hidden;
    }
    .hb-whitebox-header {
      padding: 1rem 1.25rem 0;
    }
    .hb-whitebox-body {
      padding: 1rem 1.25rem 1.25rem;
    }
    .hb-whitebox .hb-tag-selector {
      background: #fff;
    }
    .hb-mobile-card {
      border: 1px solid rgba(15, 23, 42, 0.08);
      border-radius: 16px;
      background: #fff;
      box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
    }
    .hb-mobile-card + .hb-mobile-card {
      margin-top: 0.9rem;
    }
    .hb-mobile-card-row {
      display: flex;
      justify-content: space-between;
      gap: 1rem;
      align-items: flex-start;
    }
    .hb-mobile-meta {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 0.75rem 1rem;
    }
    .hb-mobile-meta-label {
      display: block;
      margin-bottom: 0.15rem;
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: #6c757d;
    }
    .hb-mobile-actions {
      display: grid;
      gap: 0.5rem;
    }
    .hb-mobile-actions .btn,
    .hb-mobile-actions form,
    .hb-mobile-actions form .btn {
      width: 100%;
    }
    .hb-mobile-actions-inline {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 0.5rem;
    }
    .hb-quickadd-sheet {
      height: auto;
      max-height: min(92dvh, 760px);
      border-top-left-radius: 24px;
      border-top-right-radius: 24px;
    }
    .hb-quickadd-sheet .offcanvas-body {
      overflow-y: auto;
      padding-bottom: calc(1rem + env(safe-area-inset-bottom));
    }
    .hb-quickadd-form .form-label {
      font-weight: 600;
      color: #475569;
    }
    @media (max-width: 991.98px) {
      .hb-shell {
        grid-template-columns: 1fr;
      }
      .hb-sidebar {
        display: none;
      }
      .hb-live-panel {
        width: 90vw;
        max-width: 360px;
      }
    }
    @media (min-width: 768px) {
      .hb-offcanvas {
        width: 320px;
        max-width: 360px;
      }
      .hb-offcanvas-live {
        width: 380px;
        max-width: 420px;
      }
    }
    @media (min-width: 992px) {
      .hb-forecast-chart {
        height: 280px;
      }
    }
    @media (max-width: 575.98px) {
      .hb-content {
        padding: 1rem;
      }
      .hb-content .row > [class^="col-"],
      .hb-content .row > [class*=" col-"] {
        min-width: 0;
      }
      .hb-header-row {
        padding-inline: 0.75rem;
      }
      .hb-header-title {
        font-size: 1rem;
      }
      .hb-header-account {
        max-width: 8.5rem;
      }
      .hb-header-row-secondary .breadcrumb {
        font-size: 0.78rem;
      }
      .hb-header-household {
        display: none !important;
      }
      .hb-content .d-flex.justify-content-between.align-items-center.mb-3,
      .hb-content .d-flex.justify-content-between.align-items-center.mb-2 {
        flex-direction: column;
        align-items: stretch !important;
        gap: 0.75rem;
      }
      .hb-content .d-flex.justify-content-between.align-items-center.mb-3 > *,
      .hb-content .d-flex.justify-content-between.align-items-center.mb-2 > * {
        min-width: 0;
      }
      .hb-content .d-flex.justify-content-between.align-items-center.mb-3 .btn,
      .hb-content .d-flex.justify-content-between.align-items-center.mb-2 .btn,
      .hb-content .d-flex.justify-content-between.align-items-center.mb-3 .btn-group,
      .hb-content .d-flex.justify-content-between.align-items-center.mb-2 .btn-group {
        width: 100%;
      }
      .hb-content .card-body,
      .hb-content .hb-whitebox-body {
        padding: 1rem;
      }
      .hb-content .table-responsive {
        margin-inline: -0.25rem;
        padding-inline: 0.25rem;
        padding-bottom: 0.25rem;
      }
      .hb-content .table {
        font-size: 0.9rem;
      }
      .hb-content .btn {
        white-space: normal;
      }
      .hb-content .form-control,
      .hb-content .form-select {
        min-width: 0;
      }
      .hb-mobile-card {
        border-radius: 14px;
      }
      .hb-mobile-card-row {
        flex-direction: column;
        gap: 0.5rem;
      }
      .hb-mobile-meta {
        grid-template-columns: minmax(0, 1fr);
      }
      .hb-mobile-actions-inline {
        grid-template-columns: minmax(0, 1fr);
      }
      .hb-sidebar .border-bottom {
        padding-top: 0.5rem;
        padding-bottom: 0.5rem;
      }
      .hb-sidebar .hb-logo svg {
        width: 28px;
        height: 28px;
      }
      .hb-sidebar .fw-semibold {
        font-size: 0.95rem;
      }
      .hb-sidebar .text-muted.small {
        font-size: 0.7rem;
      }
    }
    .hb-fab {
      position: fixed;
      right: 1rem;
      bottom: calc(1rem + env(safe-area-inset-bottom, 0px));
      z-index: 1035;
      width: 56px;
      height: 56px;
      border-radius: 50%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, #1d4ed8 0%, #0ea5e9 100%);
      color: #fff;
      box-shadow: 0 10px 25px -8px rgba(15, 23, 42, 0.45), 0 4px 10px -3px rgba(15, 23, 42, 0.25);
      font-size: 1.5rem;
      line-height: 1;
      text-decoration: none;
      border: 0;
      transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .hb-fab:hover,
    .hb-fab:focus {
      color: #fff;
      transform: translateY(-2px);
      box-shadow: 0 14px 30px -10px rgba(15, 23, 42, 0.5), 0 6px 14px -4px rgba(15, 23, 42, 0.3);
    }
    .hb-fab:active {
      transform: translateY(0);
    }
    @media (min-width: 992px) {
      .hb-fab {
        right: 1.5rem;
        bottom: 1.5rem;
      }
    }
  </style>
</head>
<?php
$currentUser = $currentUser ?? null;
$currentHousehold = $currentHousehold ?? null;
$wsUrl = getenv('HB_WS_URL') ?: '';
$wsToken = hb_ws_token($currentUser, $currentHousehold);
$csrfToken = hb_csrf_token();
?>
<body class="<?= empty($layoutCompact) ? '' : 'hb-auth' ?>"
      data-ws-url="<?= htmlspecialchars($wsUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
      data-ws-token="<?= htmlspecialchars($wsToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
      data-live-translations="<?= htmlspecialchars(json_encode($liveTranslationMap), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
      data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<?php if (empty($layoutCompact)): ?>
  <div class="hb-shell">
    <div class="offcanvas offcanvas-start hb-offcanvas hb-offcanvas-nav d-lg-none" tabindex="-1" id="hbSidebar" aria-labelledby="hbSidebarLabel">
      <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="hbSidebarLabel"><?= htmlspecialchars(hb_t('Navigation'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="<?= htmlspecialchars(hb_t('Close'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></button>
      </div>
      <div class="offcanvas-body">
        <?php require __DIR__ . '/partials/sidebar.php'; ?>
      </div>
    </div>
    <div class="d-none d-lg-block">
      <?php require __DIR__ . '/partials/sidebar.php'; ?>
    </div>
    <div class="d-flex flex-column">
      <?php require __DIR__ . '/partials/header.php'; ?>
      <main class="hb-content">
        <?= $content ?? '' ?>
      </main>
    </div>
    <?php if (!empty($currentUser) && !empty($currentHousehold)): ?>
      <aside class="hb-live-panel" id="hbLivePanel" aria-labelledby="hbLivePanelLabel">
        <div class="hb-live-header">
          <div>
            <div class="fw-semibold" id="hbLivePanelLabel"><?= htmlspecialchars(hb_t('Live'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <div class="small text-muted"><?= htmlspecialchars(hb_t('Activity & Chat'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
          </div>
          <button type="button" class="btn-close" aria-label="<?= htmlspecialchars(hb_t('Close'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-hb-live-close></button>
        </div>
        <div class="hb-live-body">
          <section class="hb-live-section hb-live-section-grow">
            <div class="d-flex align-items-center justify-content-between">
              <div class="fw-semibold"><?= htmlspecialchars(hb_t('Activity'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <button type="button" class="btn btn-sm btn-outline-secondary" data-hb-filter-toggle><?= htmlspecialchars(hb_t('Filter'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
            </div>
            <div class="hb-live-filters mt-2" data-hb-filter-panel>
              <div class="d-flex flex-wrap gap-2">
                <div class="form-check form-check-inline mb-0">
                  <input class="form-check-input" type="checkbox" id="hb-filter-insert" data-hb-filter="insert" checked>
                  <label class="form-check-label small" for="hb-filter-insert"><?= htmlspecialchars(hb_t('Insert'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                </div>
                <div class="form-check form-check-inline mb-0">
                  <input class="form-check-input" type="checkbox" id="hb-filter-update" data-hb-filter="update">
                  <label class="form-check-label small" for="hb-filter-update"><?= htmlspecialchars(hb_t('Update'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                </div>
                <div class="form-check form-check-inline mb-0">
                  <input class="form-check-input" type="checkbox" id="hb-filter-delete" data-hb-filter="delete" checked>
                  <label class="form-check-label small" for="hb-filter-delete"><?= htmlspecialchars(hb_t('Delete'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                </div>
                <div class="form-check form-check-inline mb-0">
                  <input class="form-check-input" type="checkbox" id="hb-filter-important" data-hb-filter-important checked>
                  <label class="form-check-label small" for="hb-filter-important"><?= htmlspecialchars(hb_t('Important'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                </div>
              </div>
              <div class="mt-2">
                <label class="form-label small mb-1" for="hb-filter-range"><?= htmlspecialchars(hb_t('Time range'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <select class="form-select form-select-sm" id="hb-filter-range" data-hb-filter-range>
                  <option value="1h" selected><?= htmlspecialchars(hb_t('Last 1 hour'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <option value="6h"><?= htmlspecialchars(hb_t('Last 6 hours'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <option value="24h"><?= htmlspecialchars(hb_t('Last 24 hours'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <option value="7d"><?= htmlspecialchars(hb_t('Last 7 days'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <option value="all"><?= htmlspecialchars(hb_t('All'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                </select>
              </div>
            </div>
            <div id="hb-live-log" class="hb-live-log mt-2" aria-live="polite">
              <?php if ($liveLog): ?>
                <?php foreach (array_reverse($liveLog) as $item): ?>
                  <?php
                  $action = $item['action'] ?? '';
                  $table = $item['table'] ?? '';
                  $important = !empty($item['important']) ? '1' : '0';
                  $timestamp = (string)($item['timestamp'] ?? '');
                  $date = $timestamp ? new DateTimeImmutable($timestamp) : null;
                  $timeLabel = $date ? $date->format('H:i') : '';
                  $user = $item['user'] ?? 'System';
                  $color = $item['color'] ?? '';
                  $colorValue = $color !== '' ? $color : '#0d6efd';
                  $label = $item['label'] ?? '';
                  $title = $item['title'] ?? '';
                  $actionLabel = $item['action_label'] ?? '';
                  $url = $item['url'] ?? '';
                  ?>
                  <div class="hb-live-entry"
                       data-action="<?= htmlspecialchars($action, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                       data-table="<?= htmlspecialchars($table, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                       data-important="<?= $important ?>"
                       data-timestamp="<?= htmlspecialchars($date ? $date->format(DateTimeInterface::ATOM) : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <div class="hb-live-meta">
                      <span class="hb-live-name" style="color:<?= htmlspecialchars($colorValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>;">
                        <?= htmlspecialchars($user, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      </span>
                      <span class="hb-live-time"><?= htmlspecialchars($timeLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    </div>
                    <div class="hb-live-text">
                      <?= htmlspecialchars(hb_t($label), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>:
                      <?php if ($title !== ''): ?>
                        <?php if ($url !== ''): ?>
                          <a href="<?= htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                            <?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                          </a>
                        <?php else: ?>
                          <?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        <?php endif; ?>
                      <?php endif; ?>
                      <?= $title !== '' ? ' ' : '' ?>
                      <?= htmlspecialchars(hb_t($actionLabel), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="text-muted small"><?= htmlspecialchars(hb_t('No activity yet.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <?php endif; ?>
            </div>
          </section>
          <section class="hb-live-section">
            <div class="fw-semibold"><?= htmlspecialchars(hb_t('Chat'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <div id="hb-live-chat" class="hb-live-chat" aria-live="polite">
              <?php if ($liveChat): ?>
                <?php foreach (array_reverse($liveChat) as $line): ?>
                  <?php
                  $timestamp = (string)($line['timestamp'] ?? '');
                  $date = $timestamp ? new DateTimeImmutable($timestamp) : null;
                  $timeLabel = $date ? $date->format('H:i') : '';
                  $user = $line['user'] ?? 'System';
                  $color = $line['color'] ?? '';
                  $colorValue = $color !== '' ? $color : '#0d6efd';
                  ?>
                  <div class="hb-chat-entry">
                    <div class="hb-chat-meta">
                      <span class="hb-chat-name" style="color:<?= htmlspecialchars($colorValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>;">
                        <?= htmlspecialchars($user, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      </span>
                      <span class="hb-chat-time"><?= htmlspecialchars($timeLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    </div>
                    <div class="hb-chat-text"><?= htmlspecialchars((string)($line['message'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
            <form id="hb-chat-form" class="hb-live-footer d-flex gap-2">
              <input type="text" class="form-control form-control-sm" id="hb-chat-input" placeholder="<?= htmlspecialchars(hb_t('Message...'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <button type="submit" class="btn btn-sm btn-primary"><?= htmlspecialchars(hb_t('Send'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
            </form>
          </section>
        </div>
      </aside>
    <?php endif; ?>
  </div>
<?php else: ?>
  <main class="hb-content">
    <?= $content ?? '' ?>
  </main>
<?php endif; ?>
<?php if (!empty($currentUser) && !empty($currentHousehold)): ?>
  <div class="hb-live-backdrop" data-hb-live-close></div>
  <button class="hb-fab border-0" type="button" data-bs-toggle="offcanvas" data-bs-target="#hbQuickAddSheet"
     aria-label="<?= htmlspecialchars(hb_t('Quick add transaction'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
     title="<?= htmlspecialchars(hb_t('Quick add transaction'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <i class="bi bi-plus-lg" aria-hidden="true"></i>
  </button>
  <div class="offcanvas offcanvas-bottom hb-quickadd-sheet" tabindex="-1" id="hbQuickAddSheet" aria-labelledby="hbQuickAddSheetLabel">
    <div class="offcanvas-header">
      <div>
        <h5 class="offcanvas-title" id="hbQuickAddSheetLabel"><?= htmlspecialchars(hb_t('Quick add transaction'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h5>
        <div class="small text-muted"><?= htmlspecialchars(hb_t('Uses your last transaction as defaults.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      </div>
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="<?= htmlspecialchars(hb_t('Close'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></button>
    </div>
    <div class="offcanvas-body">
      <?php if ($quickAddAccounts && $quickAddCategories): ?>
        <form method="post" action="/transactions.php" class="hb-quickadd-form">
          <?= hb_csrf_field() ?>
          <input type="hidden" name="action" value="store">
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label small" for="hb-quick-type"><?= htmlspecialchars(hb_t('Type'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <select class="form-select" id="hb-quick-type" name="type">
                <option value="expense" <?= ($quickAddDefaults['type'] ?? 'expense') === 'expense' ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Expense'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <option value="income" <?= ($quickAddDefaults['type'] ?? '') === 'income' ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Income'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label small" for="hb-quick-date"><?= htmlspecialchars(hb_t('Date'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input class="form-control" id="hb-quick-date" type="date" name="booking_date" value="<?= htmlspecialchars((new DateTimeImmutable('today'))->format('Y-m-d'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
            </div>
            <div class="col-12">
              <label class="form-label small" for="hb-quick-amount"><?= htmlspecialchars(hb_t('Amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input class="form-control form-control-lg" id="hb-quick-amount" type="text" name="amount" inputmode="decimal" placeholder="<?= htmlspecialchars(hb_t('e.g. 12,34'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
            </div>
            <div class="col-12">
              <label class="form-label small" for="hb-quick-account"><?= htmlspecialchars(hb_t('Account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <select class="form-select" id="hb-quick-account" name="account_id" required>
                <?php foreach ($quickAddAccounts as $account): ?>
                  <option value="<?= (int)$account['id'] ?>" <?= (int)($quickAddDefaults['account_id'] ?? 0) === (int)$account['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string)$account['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label small" for="hb-quick-category"><?= htmlspecialchars(hb_t('Category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <select class="form-select" id="hb-quick-category" name="category_id" required>
                <?php foreach ($quickAddCategories as $category): ?>
                  <option value="<?= (int)$category['id'] ?>"
                          data-hb-category-type="<?= htmlspecialchars((string)$category['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                          <?= (int)($quickAddDefaults['category_id'] ?? 0) === (int)$category['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string)$category['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label small" for="hb-quick-payee"><?= htmlspecialchars(hb_t('Payee'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <select class="form-select" id="hb-quick-payee" name="payee_id">
                <option value=""><?= htmlspecialchars(hb_t('None'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <?php foreach ($quickAddPayees as $payee): ?>
                  <option value="<?= (int)$payee['id'] ?>" <?= (int)($quickAddDefaults['payee_id'] ?? 0) === (int)$payee['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string)$payee['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label small" for="hb-quick-note"><?= htmlspecialchars(hb_t('Note'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <textarea class="form-control" id="hb-quick-note" name="note" rows="2"></textarea>
            </div>
          </div>
          <div class="d-grid gap-2 mt-3">
            <button class="btn btn-success btn-lg" type="submit"><?= htmlspecialchars(hb_t('Save'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
            <a class="btn btn-outline-secondary" href="/transactions.php?action=new"><?= htmlspecialchars(hb_t('Full transaction form'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
          </div>
        </form>
      <?php else: ?>
        <div class="alert alert-warning mb-3"><?= htmlspecialchars(hb_t('Create an account and category before using quick add.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <div class="d-grid gap-2">
          <a class="btn btn-primary" href="/accounts.php"><?= htmlspecialchars(hb_t('Accounts'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
          <a class="btn btn-outline-secondary" href="/categories.php"><?= htmlspecialchars(hb_t('Categories'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <?= $extraScripts ?? '' ?>
  <script>
    const hbCsrfToken = document.body.dataset.csrfToken || '';
    const hbInjectCsrf = (root) => {
      if (!hbCsrfToken || !root) return;
      root.querySelectorAll('form').forEach((form) => {
        const method = (form.getAttribute('method') || '').toLowerCase();
        const hasHxPost = form.hasAttribute('hx-post') || form.hasAttribute('data-hx-post');
        if (method !== 'post' && !hasHxPost) return;
        if (form.querySelector('input[name="csrf_token"]')) return;
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'csrf_token';
        input.value = hbCsrfToken;
        form.appendChild(input);
      });
    };
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(t => new bootstrap.Tooltip(t));
    const hbHandleRedirect = (root) => {
      const target = root.querySelector('[data-redirect-url]');
      if (!target) return;
      const url = target.getAttribute('data-redirect-url');
      const delay = parseInt(target.getAttribute('data-redirect-delay') || '0', 10);
      if (!url) return;
      window.setTimeout(() => {
        window.location.href = url;
      }, Number.isFinite(delay) ? delay : 0);
    };
    document.addEventListener('DOMContentLoaded', () => {
      hbHandleRedirect(document);
      hbInjectCsrf(document);
    });
    document.body.addEventListener('htmx:afterSwap', (event) => {
      if (!event || !event.target) return;
      hbHandleRedirect(event.target);
      hbInjectCsrf(event.target);
    });
    document.body.addEventListener('htmx:configRequest', (event) => {
      if (!hbCsrfToken) return;
      event.detail.headers['X-CSRF-Token'] = hbCsrfToken;
    });

    const hbQuickAddType = document.getElementById('hb-quick-type');
    const hbQuickAddCategory = document.getElementById('hb-quick-category');
    const hbSyncQuickAddCategories = () => {
      if (!hbQuickAddType || !hbQuickAddCategory) return;
      const type = hbQuickAddType.value || 'expense';
      let selectedVisible = false;
      hbQuickAddCategory.querySelectorAll('option').forEach((option) => {
        const matches = (option.dataset.hbCategoryType || '') === type;
        option.hidden = !matches;
        option.disabled = !matches;
        if (matches && option.selected) {
          selectedVisible = true;
        }
      });
      if (!selectedVisible) {
        const first = Array.from(hbQuickAddCategory.options).find((option) => !option.disabled);
        if (first) first.selected = true;
      }
    };
    hbQuickAddType?.addEventListener('change', hbSyncQuickAddCategories);
    hbSyncQuickAddCategories();

    const hbWsUrl = document.body.dataset.wsUrl || '';
    const hbWsToken = document.body.dataset.wsToken || '';
    let hbLiveTranslations = {};
    try {
      hbLiveTranslations = JSON.parse(document.body.dataset.liveTranslations || '{}');
    } catch (e) {
      hbLiveTranslations = {};
    }
    const hbTranslate = (key) => hbLiveTranslations[key] || key;
    const hbLocale = document.documentElement.lang || 'de';
    const hbLiveLog = document.getElementById('hb-live-log');
    const hbLiveChat = document.getElementById('hb-live-chat');
    const hbChatForm = document.getElementById('hb-chat-form');
    const hbChatInput = document.getElementById('hb-chat-input');
    const hbFilterRange = document.querySelector('[data-hb-filter-range]');
    let hbSocket = null;

    const hbFormatTime = (iso) => {
      if (!iso) return '';
      const date = new Date(iso);
      if (Number.isNaN(date.getTime())) return '';
      return date.toLocaleTimeString(hbLocale, { hour: '2-digit', minute: '2-digit' });
    };

    const hbCreateMetaLine = (username, color, timestamp, isChat = false) => {
      const meta = document.createElement('div');
      meta.className = isChat ? 'hb-chat-meta' : 'hb-live-meta';
      const name = document.createElement('span');
      name.className = isChat ? 'hb-chat-name' : 'hb-live-name';
      name.textContent = username || hbTranslate('System');
      name.style.color = color || '#0d6efd';
      const time = document.createElement('span');
      time.className = isChat ? 'hb-chat-time' : 'hb-live-time';
      time.textContent = hbFormatTime(timestamp);
      meta.appendChild(name);
      meta.appendChild(time);
      return meta;
    };

    const hbAppendLiveEntry = (payload) => {
      if (!hbLiveLog) return;
      const entry = document.createElement('div');
      entry.className = 'hb-live-entry';
      if (payload.action) entry.dataset.action = payload.action;
      if (payload.table) entry.dataset.table = payload.table;
      if (payload.important) entry.dataset.important = '1';
      if (payload.timestamp) entry.dataset.timestamp = payload.timestamp;
      entry.appendChild(hbCreateMetaLine(payload.username, payload.color, payload.timestamp));
      const text = document.createElement('div');
      text.className = 'hb-live-text';
      if (payload.label) {
        const label = document.createElement('span');
        label.textContent = `${hbTranslate(payload.label)}: `;
        text.appendChild(label);
      }
      if (payload.title) {
        if (payload.url) {
          const link = document.createElement('a');
          link.href = payload.url;
          link.textContent = payload.title;
          text.appendChild(link);
        } else {
          const title = document.createElement('span');
          title.textContent = payload.title;
          text.appendChild(title);
        }
        text.appendChild(document.createTextNode(' '));
      }
      if (payload.action_label) {
        text.appendChild(document.createTextNode(hbTranslate(payload.action_label)));
      }
      entry.appendChild(text);
      hbLiveLog.appendChild(entry);
      hbLiveLog.scrollTop = hbLiveLog.scrollHeight;
    };

    const hbAppendChatEntry = (payload) => {
      if (!hbLiveChat) return;
      const entry = document.createElement('div');
      entry.className = 'hb-chat-entry';
      entry.appendChild(hbCreateMetaLine(payload.username, payload.color, payload.timestamp, true));
      const text = document.createElement('div');
      text.className = 'hb-chat-text';
      text.textContent = payload.message || '';
      entry.appendChild(text);
      hbLiveChat.appendChild(entry);
      hbLiveChat.scrollTop = hbLiveChat.scrollHeight;
    };

    const hbGetFilters = () => {
      const actions = new Set();
      document.querySelectorAll('[data-hb-filter]').forEach((input) => {
        if (input.checked) actions.add(input.dataset.hbFilter);
      });
      const important = document.querySelector('[data-hb-filter-important]')?.checked ?? false;
      const range = hbFilterRange?.value ?? '1h';
      return { actions, important, range };
    };

    const hbApplyFilters = () => {
      const { actions, important, range } = hbGetFilters();
      if (!hbLiveLog) return;
      let maxAge = 0;
      if (range === '1h') maxAge = 3600 * 1000;
      if (range === '6h') maxAge = 6 * 3600 * 1000;
      if (range === '24h') maxAge = 24 * 3600 * 1000;
      if (range === '7d') maxAge = 7 * 24 * 3600 * 1000;
      const cutoff = maxAge > 0 ? Date.now() - maxAge : 0;
      hbLiveLog.querySelectorAll('[data-action]').forEach((line) => {
        const action = line.dataset.action || '';
        const isImportant = line.dataset.important === '1';
        const matches = actions.has(action) || (important && isImportant);
        let matchesTime = true;
        if (cutoff > 0 && line.dataset.timestamp) {
          const ts = Date.parse(line.dataset.timestamp);
          if (!Number.isNaN(ts)) {
            matchesTime = ts >= cutoff;
          }
        }
        line.classList.toggle('d-none', !(matches && matchesTime));
      });
    };

    const hbInitSocket = () => {
      if (!hbWsUrl || !hbWsToken) return;
      hbSocket = new WebSocket(hbWsUrl);
      hbSocket.addEventListener('open', () => {
        hbSocket.send(JSON.stringify({ type: 'hello', token: hbWsToken }));
      });
      hbSocket.addEventListener('message', (event) => {
        try {
          const payload = JSON.parse(event.data);
          if (payload.type === 'audit' && hbLiveLog) {
            hbAppendLiveEntry(payload);
            hbApplyFilters();
          }
          if (payload.type === 'chat' && hbLiveChat) {
            hbAppendChatEntry(payload);
          }
        } catch (err) {
          // ignore malformed messages
        }
      });
      hbSocket.addEventListener('close', () => {
        setTimeout(hbInitSocket, 3000);
      });
    };

    hbInitSocket();
    hbApplyFilters();

    document.querySelectorAll('[data-hb-filter], [data-hb-filter-important], [data-hb-filter-range]').forEach((input) => {
      input.addEventListener('change', hbApplyFilters);
    });

    document.querySelectorAll('[data-hb-filter-toggle]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const panel = document.querySelector('[data-hb-filter-panel]');
        panel?.classList.toggle('show');
      });
    });

    const hbToggleLive = () => {
      const isOpen = document.body.classList.toggle('hb-live-open');
      try {
        localStorage.setItem('hbLiveOpen', isOpen ? '1' : '0');
      } catch (e) {
        // ignore storage errors
      }
    };
    const hbToggleSidebar = () => {
      const isCollapsed = document.body.classList.toggle('hb-sidebar-collapsed');
      try {
        localStorage.setItem('hbSidebarCollapsed', isCollapsed ? '1' : '0');
      } catch (e) {
        // ignore storage errors
      }
    };
    document.querySelectorAll('[data-hb-live-toggle]').forEach((btn) => {
      btn.addEventListener('click', hbToggleLive);
    });
    document.querySelectorAll('[data-hb-sidebar-toggle]').forEach((btn) => {
      btn.addEventListener('click', hbToggleSidebar);
    });
    document.querySelectorAll('[data-hb-live-close]').forEach((btn) => {
      btn.addEventListener('click', () => {
        document.body.classList.remove('hb-live-open');
        try {
          localStorage.setItem('hbLiveOpen', '0');
        } catch (e) {
          // ignore storage errors
        }
      });
    });

    try {
      if (localStorage.getItem('hbLiveOpen') === '1') {
        document.body.classList.add('hb-live-open');
      }
    } catch (e) {
      // ignore storage errors
    }
    try {
      if (localStorage.getItem('hbSidebarCollapsed') === '1') {
        document.body.classList.add('hb-sidebar-collapsed');
      }
    } catch (e) {
      // ignore storage errors
    }

    if (hbChatForm && hbChatInput) {
      hbChatForm.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!hbSocket || hbSocket.readyState !== WebSocket.OPEN) return;
        const msg = hbChatInput.value.trim();
        if (!msg) return;
        hbSocket.send(JSON.stringify({ type: 'chat', message: msg }));
        hbChatInput.value = '';
      });
    }

    if ('serviceWorker' in navigator && window.location.protocol === 'https:') {
      window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
      });
    }
  </script>
</body>
</html>
