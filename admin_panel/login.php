<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/layout.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // دریافت اطلاعات
    $mobile = trim($_POST['mobile'] ?? ''); 
    $password = trim($_POST['password'] ?? '');

    if ($mobile === '' || $password === '') {
        $error = 'شماره موبایل و رمز عبور را وارد کنید.';
    } else {
        $stmt = db()->prepare('SELECT id, mobile, password, role FROM users WHERE mobile = ? LIMIT 1');
        $stmt->execute([$mobile]);
        $user = $stmt->fetch();

        if ($user && in_array((string)($user['role'] ?? ''), ['admin', 'editor', 'viewer'], true) && password_verify($password, (string)$user['password'])) {
            $_SESSION['admin_id'] = (int)$user['id'];
            $_SESSION['admin_username'] = $user['mobile']; 
            $_SESSION['admin_role'] = $user['role'];
            header('Location: index.php');
            exit;
        }

        $error = 'اطلاعات ورود نامعتبر است یا دسترسی ادمین ندارید.';
    }
}

// ارسال false به عنوان پارامتر سوم برای حذف منوها و هدر پنل در صفحه ورود
render_page_start('ورود مدیر', 'login', false);
?>

<style>
  /* متغیرهای رنگی اختصاصی صفحه ورود */
  :root {
    --login-primary: #3b82f6;
    --login-primary-hover: #2563eb;
    --login-bg: #f8fafc;
    --login-card: #ffffff;
    --login-text: #1e293b;
    --login-muted: #64748b;
    --login-border: #cbd5e1;
    --login-focus: rgba(59, 130, 246, 0.15);
  }

  /* تنظیمات بدنه ورود برای وسط‌چین کردن کارت */
  .login-body {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    background: linear-gradient(135deg, #e0f2fe 0%, #f1f5f9 100%);
    padding: 20px;
    margin: -20px; /* برای خنثی کردن پدینگ‌های احتمالی layout */
    font-family: inherit;
  }

  /* کارت اصلی فرم */
  .login-card {
    background: var(--login-card);
    width: 100%;
    max-width: 420px;
    border-radius: 20px;
    box-shadow: 0 10px 40px rgba(15, 23, 42, 0.08);
    padding: 48px 40px;
    position: relative;
    overflow: hidden;
  }

  /* نوار رنگی بالای کارت */
  .login-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 5px;
    background: linear-gradient(90deg, #3b82f6, #8b5cf6);
  }

  /* لوگوی فرم */
  .login-logo {
    width: 64px;
    height: 64px;
    background: #eff6ff;
    color: var(--login-primary);
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 24px auto;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
  }

  /* هدر فرم */
  .login-header {
    text-align: center;
    margin-bottom: 32px;
  }
  .login-title {
    color: var(--login-text);
    font-size: 24px;
    font-weight: 800;
    margin: 0 0 8px 0;
  }
  .login-subtitle {
    color: var(--login-muted);
    font-size: 14px;
    font-weight: 500;
    margin: 0;
  }

  /* نمایش ارور */
  .alert.error {
    background: #fef2f2;
    color: #ef4444;
    border: 1px solid #fecaca;
    padding: 12px 16px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .alert.error::before {
    content: '⚠️';
    font-size: 16px;
  }

  /* فیلدها */
  .form-group {
    margin-bottom: 20px;
  }
  .field-label {
    display: block;
    font-size: 13px;
    font-weight: 700;
    color: var(--login-text);
    margin-bottom: 8px;
  }
  .input-control {
    width: 100%;
    padding: 14px 16px;
    border: 1px solid var(--login-border);
    border-radius: 12px;
    font-size: 15px;
    color: var(--login-text);
    background: #f8fafc;
    transition: all 0.3s ease;
    box-sizing: border-box;
    font-family: inherit;
    outline: none;
  }
  .input-control:focus {
    background: #ffffff;
    border-color: var(--login-primary);
    box-shadow: 0 0 0 4px var(--login-focus);
  }
  
  /* چپ‌چین کردن فیلدهای موبایل و پسورد */
  .ltr-input {
    direction: ltr;
    text-align: left;
  }
  .ltr-input::placeholder {
    text-align: right;
    direction: rtl;
    color: #94a3b8;
  }

  /* دکمه ورود */
  .btn-submit {
    background: var(--login-primary);
    color: #ffffff;
    border: none;
    padding: 14px 20px;
    border-radius: 12px;
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
    width: 100%;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25);
    font-family: inherit;
    margin-top: 8px;
  }
  .btn-submit:hover {
    background: var(--login-primary-hover);
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(59, 130, 246, 0.35);
  }
  .btn-submit:active {
    transform: translateY(0);
  }

  /* فوتر کارت */
  .login-footer {
    text-align: center;
    margin-top: 32px;
    font-size: 12px;
    color: #94a3b8;
  }
</style>

<div class="login-body">
  <div class="login-card">
    
    <!-- آیکون/لوگو سیستم -->
    <div class="login-logo">
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 2L2 7l10 5 10-5-10-5z"></path>
        <path d="M2 17l10 5 10-5"></path>
        <path d="M2 12l10 5 10-5"></path>
      </svg>
    </div>

    <div class="login-header">
      <h1 class="login-title">ورود مدیر</h1>
      <p class="login-subtitle">پنل مدیریت یکپارچه افاریکس</p>
    </div>

    <?php if ($error): ?>
      <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post">
      <div class="form-group">
        <label class="field-label">شماره موبایل</label>
        <input class="input-control ltr-input" type="text" name="mobile" autocomplete="username" placeholder="مثال: 09123456789" required>
      </div>
      
      <div class="form-group">
        <label class="field-label">رمز عبور</label>
        <input class="input-control ltr-input" type="password" name="password" autocomplete="current-password" placeholder="••••••••" required>
      </div>
      
      <button type="submit" class="btn-submit">ورود به سیستم</button>
    </form>

    <div class="login-footer">
      تمامی حقوق برای سیستم <b>افاریکس</b> محفوظ است &copy; <?= date('Y') ?>
    </div>
    
  </div>
</div>

<?php render_page_end(false); ?>
