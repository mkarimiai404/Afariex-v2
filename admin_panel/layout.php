<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function render_page_start(string $title, string $activePage, bool $showSidebar = true): void
{
    $flash = get_flash();
    $menu = [
        'index' => ['label' => 'داشبورد', 'href' => 'index.php'],
        'agencies' => ['label' => 'نمایندگی‌ها', 'href' => 'agencies.php'],
        'rates' => ['label' => 'نرخ ارز', 'href' => 'exchange_rates.php'],
        'service_fees' => ['label' => 'مدیریت کارمزد خدمات', 'href' => 'service_fees.php'],
        'fee_reports' => ['label' => 'گزارش کارمزدها', 'href' => 'service_fee_reports.php'],
        'remittances' => ['label' => 'حواله‌ها', 'href' => 'remittances.php'],
        'receipts' => ['label' => 'فیش‌های واریزی', 'href' => 'receipts.php'],
        'withdrawals' => ['label' => 'برداشت‌ها', 'href' => 'withdrawals.php'],
        'users' => ['label' => 'کاربران', 'href' => 'users.php'],
        'debts' => ['label' => 'بدهی کاربران', 'href' => 'user_debts.php'],
        'verifications' => ['label' => 'احراز هویت', 'href' => 'verifications.php'],
        'logs' => ['label' => 'گزارش فعالیت', 'href' => 'activity_logs.php'],
    ];
    ?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($title) ?> | پنل مدیریت</title>
  <style>
    @font-face {
      font-family: 'Vazirmatn';
      src: url('fonts/Vazirmatn-Regular.woff2') format('woff2');
      font-weight: 400;
      font-style: normal;
      font-display: swap;
    }
    @font-face {
      font-family: 'Vazirmatn';
      src: url('fonts/Vazirmatn-Bold.woff2') format('woff2');
      font-weight: 700;
      font-style: normal;
      font-display: swap;
    }
    
    /* پالت رنگی حرفه‌ای و مدرن */
    :root {
      --bg: #f8fafc; 
      --card: #ffffff; 
      --text: #334155; 
      --heading: #0f172a;
      --muted: #64748b; 
      --line: #e2e8f0;
      --primary: #3b82f6; 
      --primary-dark: #2563eb; 
      --danger: #ef4444; 
      --danger-dark: #dc2626;
      --success-bg: #ecfdf5; 
      --success-text: #059669; 
      --error-bg: #fef2f2; 
      --error-text: #dc2626;
      --sidebar-bg: #0f172a; 
      --sidebar-item: #1e293b;
      --sidebar-text: #94a3b8;
      --sidebar-active: #3b82f6;
    }
    
    * { box-sizing: border-box; }
    html, body, h1, h2, h3, h4, h5, h6, p, span, a, li, label, div, table, th, td, input, textarea, select, button {
      font-family: 'Vazirmatn', Tahoma, sans-serif !important;
    }
    
    body { 
      margin: 0; 
      direction: rtl; 
      background: var(--bg); 
      color: var(--text); 
      -webkit-font-smoothing: antialiased;
    }
    
    a { text-decoration: none; color: inherit; }
    
    /* ساختار اصلی */
    .app-shell { 
      height: 100vh; 
      display: flex; 
      overflow: hidden;
    }
    
    /* سایدبار سمت راست */
    .sidebar { 
      width: 280px; 
      background: var(--sidebar-bg); 
      color: #fff; 
      padding: 24px 16px; 
      display: flex;
      flex-direction: column;
      box-shadow: -4px 0 24px rgba(0,0,0,0.08); 
      z-index: 100;
      overflow-y: auto;
    }
    
    /* محتوای سمت چپ */
    .content { 
      flex: 1; 
      padding: 24px 32px; 
      overflow-y: auto;
      height: 100vh;
    }
    
    .brand { 
      font-size: 24px; 
      font-weight: 800; 
      margin: 10px 8px 36px; 
      text-align: center;
      letter-spacing: -0.5px;
      color: #ffffff;
    }
    .brand span { color: var(--primary); }
    
    /* طراحی مدرن منوها */
    .nav-list { display: grid; gap: 8px; }
    .nav-link { 
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 16px; 
      border-radius: 12px; 
      color: var(--sidebar-text); 
      font-weight: 600;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
      position: relative;
    }
    
    .nav-link::before {
      content: '';
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: transparent;
      transition: all 0.3s ease;
    }

    .nav-link:hover { 
      background: var(--sidebar-item); 
      color: #ffffff; 
      transform: translateX(-4px);
    }
    
    .nav-link.active { 
      background: rgba(59, 130, 246, 0.1); 
      color: #ffffff; 
      border: 1px solid rgba(59, 130, 246, 0.2);
    }
    
    .nav-link.active::before {
      background: var(--sidebar-active);
      box-shadow: 0 0 10px var(--sidebar-active);
    }

    .nav-link.logout { 
      background: rgba(239, 68, 68, 0.1); 
      color: #ef4444; 
      margin-top: 24px; 
      border: 1px solid rgba(239, 68, 68, 0.2);
    }
    .nav-link.logout::before { display: none; }
    .nav-link.logout:hover { 
      background: var(--danger); 
      color: #fff; 
      transform: translateY(-2px);
    }

    /* نوار بالایی مدرن (کارت شیشه‌ای) */
    .topbar { 
      display: flex; 
      align-items: center; 
      justify-content: space-between; 
      margin-bottom: 24px; 
      background: #ffffff;
      padding: 16px 24px;
      border-radius: 16px;
      box-shadow: 0 4px 16px rgba(15, 23, 42, 0.03);
      border: 1px solid var(--line);
    }
    
    .page-title { 
      margin: 0; 
      font-size: 22px; 
      font-weight: 800; 
      color: var(--heading);
    }
    
    .user-pill { 
      background: #f8fafc; 
      border: 1px solid #e2e8f0; 
      border-radius: 999px; 
      padding: 8px 16px; 
      font-size: 13px; 
      font-weight: 600;
      color: var(--text); 
      display: flex;
      align-items: center;
      gap: 8px;
    }

    /* هشدارها */
    .alert { 
      border-radius: 12px; 
      padding: 14px 16px; 
      margin-bottom: 20px; 
      font-size: 14px; 
      font-weight: 600;
      border: 1px solid transparent; 
      display: flex;
      align-items: center;
    }
    .alert.success { background: var(--success-bg); color: var(--success-text); border-color: #a7f3d0; }
    .alert.error { background: var(--error-bg); color: var(--error-text); border-color: #fecaca; }

    /* کارت‌های پایه برای صفحات مختلف */
    .card { 
      background: var(--card); 
      border-radius: 16px; 
      border: 1px solid var(--line); 
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.02); 
      padding: 24px; 
      margin-bottom: 20px; 
    }

    .grid { display: grid; gap: 16px; }
    .grid.cols-3 { grid-template-columns: repeat(3, minmax(0,1fr)); }
    
    /* فرم‌ها */
    .form-grid { display: grid; gap: 16px; grid-template-columns: repeat(12, minmax(0,1fr)); }
    .col-2 { grid-column: span 2; } .col-3 { grid-column: span 3; } .col-4 { grid-column: span 4; } .col-5 { grid-column: span 5; } .col-6 { grid-column: span 6; } .col-8 { grid-column: span 8; } .col-12 { grid-column: span 12; }
    
    .field-label { display: block; font-size: 13px; color: var(--text); margin-bottom: 8px; font-weight: 700; }
    .input, .select {
      width: 100%; height: 44px; border: 1px solid var(--line); border-radius: 10px; padding: 0 14px; font-size: 14px; background: #f8fafc; outline: none; color: var(--heading);
      transition: all .2s ease;
    }
    .input:focus, .select:focus { 
      background: #ffffff;
      border-color: var(--primary); 
      box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); 
    }

    /* دکمه‌ها */
    .btn { 
      height: 44px; border: 0; border-radius: 10px; padding: 0 20px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all .2s ease; display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    }
    .btn:active { transform: translateY(2px); }
    .btn-primary { background: var(--primary); color: #fff; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2); } 
    .btn-primary:hover { background: var(--primary-dark); box-shadow: 0 6px 16px rgba(59, 130, 246, 0.3); }
    .btn-danger { background: var(--danger); color: #fff; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2); } 
    .btn-danger:hover { background: var(--danger-dark); }
    .btn-light { background: #f1f5f9; color: #334155; border: 1px solid var(--line); } 
    .btn-light:hover { background: #e2e8f0; }

    /* جداول دیفالت */
    .table-wrap { overflow-x: auto; border-radius: 12px; border: 1px solid var(--line); box-shadow: 0 4px 10px rgba(0,0,0,0.02); }
    table { width: 100%; border-collapse: collapse; min-width: 920px; background: #fff; }
    th, td { padding: 14px 16px; text-align: right; border-bottom: 1px solid #f1f5f9; font-size: 13px; white-space: nowrap; }
    th { background: #f8fafc; color: var(--muted); font-weight: 700; text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px; }
    tr:hover td { background: #fcfdff; }
    
    .actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .inline-form { display: inline; }
    
    /* مودال */
    .modal-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,.6); backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; z-index: 1000; padding: 16px; }
    .modal-backdrop.open { display: flex; }
    .modal { width: min(960px, 98%); max-height: 90vh; overflow: auto; background: #fff; border-radius: 20px; border: 1px solid #e2e8f0; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); }
    .modal-head { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid var(--line); background: #f8fafc; border-radius: 20px 20px 0 0; }
    .modal-title { font-size: 18px; font-weight: 800; margin: 0; color: var(--heading); }
    .modal-body { padding: 24px; }
    
    .icon-btn { border: 0; background: #f1f5f9; width: 36px; height: 36px; border-radius: 10px; cursor: pointer; font-size: 18px; color: var(--text); display: flex; align-items: center; justify-content: center; transition: all 0.2s; }
    .icon-btn:hover { background: #e2e8f0; color: var(--danger); transform: rotate(90deg); }

    /* ریسپانسیو */
    @media (max-width: 1024px) {
      .grid.cols-3 { grid-template-columns: 1fr; }
      .col-2,.col-3,.col-4,.col-5,.col-6,.col-8,.col-12 { grid-column: span 12; }
    }
    @media (max-width: 840px) {
      .app-shell { flex-direction: column; overflow: auto; }
      .sidebar { width: 100%; order: 1; border-radius: 0; padding: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); height: auto; }
      .content { order: 2; padding: 16px; height: auto; overflow: visible; }
      .topbar { flex-direction: column; gap: 12px; align-items: flex-start; }
    }
  </style>
  <style>
    /* Shared visual refresh inspired by receipt views */
    .card {
      border-radius: 18px !important;
      border: 1px solid #dbe4ef !important;
      box-shadow: 0 20px 45px rgba(15, 23, 42, 0.06) !important;
      background: #ffffff !important;
    }
    .table-wrap,
    .rm-table-container,
    .receipt-table-wrap,
    .md-table-wrapper {
      border-radius: 16px !important;
      border: 1px solid #dbe4ef !important;
      box-shadow: 0 20px 45px rgba(15, 23, 42, 0.05) !important;
      background: #ffffff !important;
      overflow: hidden !important;
    }
    table {
      border-collapse: separate !important;
      border-spacing: 0 !important;
    }
    th,
    td {
      padding: 16px 18px !important;
      border-bottom: 1px solid #edf2f7 !important;
      vertical-align: middle !important;
    }
    th {
      background: #f8fafc !important;
      color: #475569 !important;
      font-weight: 800 !important;
      letter-spacing: 0.2px !important;
      text-transform: none !important;
    }
    tr:hover td {
      background: #fcfdff !important;
    }
    .btn,
    .btn-toggle-add,
    .btn-submit,
    .btn-edit,
    .btn-delete,
    .btn-receipt,
    .receipt-add-button,
    .receipt-filter-button,
    .receipt-save-button,
    .receipt-submit-button,
    .receipt-print,
    .md-btn {
      border-radius: 12px !important;
      font-weight: 800 !important;
      transition: all 160ms ease !important;
    }
    .btn-primary,
    .btn-submit,
    .btn-toggle-add,
    .receipt-add-button,
    .receipt-filter-button,
    .receipt-save-button,
    .receipt-submit-button,
    .receipt-print {
      background: linear-gradient(180deg, #3b82f6 0%, #2563eb 100%) !important;
      color: #ffffff !important;
      box-shadow: 0 12px 24px rgba(59, 130, 246, 0.18) !important;
      border: 1px solid rgba(37, 99, 235, 0.2) !important;
    }
    .btn-light,
    .btn-edit,
    .receipt-view-link,
    .btn-receipt,
    .receipt-reset-link {
      background: #ffffff !important;
      color: #334155 !important;
      border: 1px solid #dbe4ef !important;
      box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04) !important;
    }
    .btn-danger,
    .btn-delete,
    .receipt-cancel-button {
      background: linear-gradient(180deg, #ef4444 0%, #dc2626 100%) !important;
      color: #ffffff !important;
      border: 1px solid rgba(220, 38, 38, 0.2) !important;
      box-shadow: 0 12px 24px rgba(239, 68, 68, 0.18) !important;
    }
    .btn-primary:hover,
    .btn-toggle-add:hover,
    .btn-submit:hover,
    .receipt-add-button:hover,
    .receipt-filter-button:hover,
    .receipt-save-button:hover,
    .receipt-submit-button:hover,
    .receipt-print:hover,
    .md-btn:hover {
      transform: translateY(-1px) !important;
      box-shadow: 0 16px 28px rgba(59, 130, 246, 0.24) !important;
    }
    .btn-light:hover,
    .btn-edit:hover,
    .btn-receipt:hover,
    .receipt-reset-link:hover,
    .receipt-view-link:hover {
      background: #f8fafc !important;
      border-color: #cbd5e1 !important;
    }
    .actions,
    .rm-actions,
    .receipt-row-actions,
    .md-actions {
      gap: 10px !important;
      align-items: center !important;
      flex-wrap: wrap !important;
    }
    .inline-form {
      display: inline-flex !important;
    }
    .input,
    .select,
    textarea,
    .rm-input,
    .rm-select,
    .receipt-input,
    .receipt-select,
    .receipt-textarea,
    .filter-input,
    .receipt-filter-control {
      border-radius: 12px !important;
      border: 1px solid #dbe4ef !important;
      background: #ffffff !important;
      box-shadow: none !important;
    }
    .input:focus,
    .select:focus,
    textarea:focus,
    .rm-input:focus,
    .rm-select:focus,
    .receipt-input:focus,
    .receipt-select:focus,
    .receipt-textarea:focus,
    .filter-input:focus,
    .receipt-filter-control:focus {
      border-color: #3b82f6 !important;
      box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1) !important;
      background: #ffffff !important;
    }
    .pagination,
    .rm-pagination,
    .receipt-pagination {
      display: flex !important;
      justify-content: center !important;
      gap: 8px !important;
      flex-wrap: wrap !important;
    }
    .page-link,
    .receipt-page-link {
      min-width: 40px !important;
      height: 40px !important;
      border-radius: 10px !important;
      border: 1px solid #dbe4ef !important;
      background: #ffffff !important;
      box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04) !important;
    }
    .page-link.active,
    .receipt-page-link.active {
      background: linear-gradient(180deg, #3b82f6 0%, #2563eb 100%) !important;
      color: #ffffff !important;
      border-color: transparent !important;
    }
    .modal,
    .rm-modal-content,
    .receipt-modal-content {
      border-radius: 20px !important;
      border: 1px solid #dbe4ef !important;
      box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.24) !important;
    }
    .modal-head,
    .rm-modal-header,
    .receipt-modal-header {
      background: #f8fafc !important;
      border-bottom: 1px solid #e2e8f0 !important;
    }
  </style>
</head>
<body>
<?php if ($showSidebar): ?>
  <div class="app-shell">
    <aside class="sidebar">
      <div class="brand">Afariex <span>Admin</span></div>
      <nav class="nav-list">
        <?php foreach ($menu as $key => $item): ?>
          <a class="nav-link <?= $activePage === $key ? 'active' : '' ?>" href="<?= e($item['href']) ?>"><?= e($item['label']) ?></a>
        <?php endforeach; ?>
        <a class="nav-link logout" href="logout.php">خروج از سیستم</a>
      </nav>
    </aside>
    <main class="content">
      <div class="topbar">
        <h1 class="page-title"><?= e($title) ?></h1>
        <div class="user-pill">👤 کاربر: <?= e($_SESSION['admin_username'] ?? 'admin') ?> &nbsp;|&nbsp; 🛡️ نقش: <?= e(current_role()) ?></div>
      </div>
      <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= e($flash['message']) ?></div>
      <?php endif; ?>
<?php endif; ?>
<?php
}

function render_page_end(bool $showSidebar = true): void
{
    if ($showSidebar) {
        echo '</main></div>';
    }
    ?>
<script>
  (function () {
    const openButtons = document.querySelectorAll('[data-modal-open]');
    const closeButtons = document.querySelectorAll('[data-modal-close]');

    function toggleModal(id, shouldOpen) {
      const modal = document.getElementById(id);
      if (!modal) return;
      modal.classList[shouldOpen ? 'add' : 'remove']('open');
    }

    openButtons.forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-modal-open');
        if (id) toggleModal(id, true);
      });
    });

    closeButtons.forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-modal-close');
        if (id) toggleModal(id, false);
      });
    });

    document.querySelectorAll('.modal-backdrop').forEach((backdrop) => {
      backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) backdrop.classList.remove('open');
      });
    });
  })();
</script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/css/persian-datepicker.min.css">
<style>
  .datepicker-plot-area,
  .pwt-datepicker,
  .datepicker-container {
    z-index: 99999 !important;
  }
</style>
<script src="https://cdn.jsdelivr.net/npm/persian-date@1.1.0/dist/persian-date.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/js/persian-datepicker.min.js"></script>
<script>
  (function () {
    function initPersianDatepickers() {
      if (!window.jQuery || !jQuery.fn || !jQuery.fn.persianDatepicker) return;
      jQuery('.js-persian-date').each(function () {
        const $input = jQuery(this);
        if ($input.data('pdp-initialized')) return;
        $input.data('pdp-initialized', true);
        $input.persianDatepicker({
          format: 'YYYY/MM/DD',
          initialValue: false,
          autoClose: true,
          observer: true,
          toolbox: {
            enabled: false,
          },
        });
      });
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initPersianDatepickers);
    } else {
      initPersianDatepickers();
    }
  })();
</script>
</body>
</html>
<?php
}
