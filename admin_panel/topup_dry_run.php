<?php
declare(strict_types=1);
/** Administrative, READ-ONLY simulator. Never calls Inax purchase or wallet debit. */
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../API/lib/topup_common.php';
require_login();
require_permission('view');
require_once __DIR__ . '/../API/lib/InaxClient.php';
$inaxCredit=null;$inaxCreditError=null;
try { $credit=(new InaxClient())->credit(); $value=$credit['credit']??($credit['data']['credit']??null); if((int)($credit['code']??0)===1 && is_numeric($value)) $inaxCredit=(int)$value; else $inaxCreditError='پاسخ موجودی آینکس قابل تشخیص نیست.'; } catch(Throwable $e) { $inaxCreditError='استعلام موجودی آینکس در دسترس نیست.'; }

$results = [];
$errors = [];
$input = ['mobile'=>'09123456789','operator'=>'MTN','amount'=>'22000','charge_type'=>'normal','scenario'=>'success','user_id'=>''];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf_or_fail($_POST['csrf_token'] ?? null);
    foreach ($input as $key => $value) {
        $input[$key] = trim((string)($_POST[$key] ?? $value));
    }
    $log = static function (string $stage, bool $passed, string $detail) use (&$results): void {
        $results[] = ['stage'=>$stage, 'passed'=>$passed, 'detail'=>$detail];
    };
    $mobile = topup_normalize_mobile($input['mobile']);
    $log('اعتبارسنجی شماره موبایل', $mobile !== null, $mobile !== null ? 'قالب شماره صحیح است.' : 'شماره معتبر نیست.');
    $rules = topup_operator_rules();
    $operator = strtoupper($input['operator']);
    $operatorOk = isset($rules[$operator]);
    $log('شناسایی اپراتور', $operatorOk, $operatorOk ? $rules[$operator]['title'] : 'اپراتور ناشناخته است.');
    $rawAmount = str_replace([',','٬',' '], '', normalize_persian_digits($input['amount']));
    $amountOk = preg_match('/^[1-9][0-9]{0,11}$/D', $rawAmount) === 1;
    $amount = $amountOk ? (int)$rawAmount : 0;
    $log('اعتبارسنجی مبلغ', $amountOk, $amountOk ? number_format($amount).' تومان' : 'مبلغ باید عدد صحیح مثبت باشد.');
    $type = strtolower($input['charge_type']);
    $typeOk = in_array($type,['normal','amazing','permanent'],true)
        && !($operator === 'MTN' && $type === 'amazing')
        && !($operator === 'MCI' && $type !== 'normal');
    $log('اعتبارسنجی نوع شارژ', $typeOk, $typeOk ? 'نوع شارژ با قوانین محلی سازگار است.' : 'این نوع شارژ مجاز نیست.');
    $policyOk = $operatorOk && $amountOk && $amount >= $rules[$operator]['min_amount'] && $amount <= $rules[$operator]['max_amount'];
    $log('سیاست مبلغ در نسخه فعلی سرور', $policyOk,
        $operatorOk ? 'محدوده فعلی: '.number_format($rules[$operator]['min_amount']).' تا '.number_format($rules[$operator]['max_amount']).' تومان؛ پذیرش واقعی آینکس بررسی نشده است.' : 'ابتدا اپراتور را انتخاب کنید.');
    $fee = null;
    $balance = null;
    $userId = filter_var($input['user_id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if ($userId !== false && $userId !== null) {
        try {
            $stmt = db()->prepare('SELECT balance FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $value = $stmt->fetchColumn();
            $balance = $value === false ? null : (float)$value;
            $log('خواندن موجودی کیف پول (فقط خواندنی)', $balance !== null,
                $balance === null ? 'کاربر یافت نشد.' : number_format($balance,0).' تومان؛ هیچ تغییری ثبت نشد.');
        } catch (Throwable $e) {
            $log('خواندن موجودی کیف پول',false,'دسترسی خواندن موجودی ممکن نشد.');
        }
    } else {
        $log('بررسی کیف پول',true,'شناسه کاربر وارد نشده؛ این مرحله بدون دسترسی به کیف پول شبیه‌سازی شد.');
    }
    if ($amountOk) {
        try {
            $fee = service_fee_calculate('mobile_recharge', $amount, db());
            $log('محاسبه کارمزد خدمات',true,'کارمزد: '.number_format((int)$fee['fee_amount']).'، جمع: '.number_format((int)$fee['total_amount']).' تومان (فقط محاسبه).');
            if ($balance !== null) {
                $log('کنترل کفایت موجودی', $balance >= (int)$fee['total_amount'],
                    $balance >= (int)$fee['total_amount'] ? 'موجودی برای خرید واقعی کافی است؛ کسر نشد.' : 'موجودی برای خرید واقعی کافی نیست؛ کسر نشد.');
            }
        } catch (Throwable $e) {
            $log('محاسبه کارمزد خدمات',false,'تنظیمات کارمزد یا دیتابیس در دسترس نیست.');
        }
    }
    $scenario = in_array($input['scenario'],['success','failed','pending','timeout'],true) ? $input['scenario'] : 'failed';
    $mock = match($scenario) {
        'success' => ['code'=>1,'ref_code'=>'MOCK-NOT-REAL','final_status'=>'succeeded'],
        'failed' => ['code'=>-44,'final_status'=>'failed'],
        'pending' => ['code'=>-200,'final_status'=>'pending'],
        default => ['code'=>null,'final_status'=>'unknown'],
    };
    $log('ساخت سفارش آزمایشی',true,'شناسه صرفاً نمایشی: TEST-'.date('YmdHis').'. هیچ رکوردی ذخیره نشد.');
    $log('پاسخ ساختگی آینکس',true,'سناریو: '.$scenario.'؛ پاسخ شبیه‌سازی‌شده: '.json_encode($mock,JSON_UNESCAPED_UNICODE));
    $log('شبیه‌سازی رسید و مدیریت',true,'وضعیت قابل نمایش: '.$mock['final_status'].'؛ رسید واقعی و سفارش دیتابیس ساخته نشد.');
    $log('کنترل ایمنی',true,'درخواست خرید به آینکس: صفر؛ برداشت کیف پول: صفر؛ تغییر دیتابیس: صفر.');
    error_log('[AFARIEX_TOPUP_DRY_RUN] '.json_encode([
        'at'=>date(DATE_ATOM),'operator'=>$operator,'amount'=>$amount,
        'scenario'=>$scenario,'checks'=>array_map(static fn($r)=>['stage'=>$r['stage'],'passed'=>$r['passed']],$results),
        'inax_purchase_sent'=>false,'wallet_debited'=>false,'db_written'=>false
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}
render_page_start('تست امن خرید شارژ', 'topup_orders');
?>
<style>
.dry-wrap{max-width:1100px;margin:auto;display:grid;gap:20px}.dry-hero{background:linear-gradient(120deg,#0f172a,#164e63);color:white;padding:26px;border-radius:20px}.dry-hero h2{color:white;margin:0 0 8px}.dry-hero p{margin:0;color:#d5f3fa;line-height:2}.dry-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.dry-field{display:grid;gap:6px}.dry-field label{font-weight:700;font-size:13px}.dry-field input,.dry-field select{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:11px;background:#fff}.dry-card{background:#fff;padding:24px;border:1px solid #e2e8f0;border-radius:18px}.dry-btn{border:0;background:#2563eb;color:#fff;border-radius:10px;padding:13px 25px;cursor:pointer;font:inherit;font-weight:700}.dry-row{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:12px 0;border-bottom:1px solid #edf2f7}.dry-pass{color:#15803d}.dry-fail{color:#b91c1c}.dry-note{padding:14px;border-radius:12px;background:#fff7ed;color:#9a3412;line-height:2}@media(max-width:650px){.dry-grid{grid-template-columns:1fr}.dry-row{align-items:flex-start;flex-direction:column}}
</style>
<div class="dry-wrap">
 <section class="dry-hero"><h2>آزمایش بدون کسر موجودی</h2><p>تمام مراحل داخلی خرید شارژ را به‌شکل خواندنی و شبیه‌سازی‌شده بررسی کنید. هیچ درخواست خریدی به آینکس ارسال نمی‌شود و هیچ مبلغی از کیف پول کاربر کسر نمی‌شود.</p></section>
 <p><a class="btn btn-primary" href="inax_wallet.php">افزایش موجودی حساب آینکس از درگاه رسمی</a></p>
 <section class="dry-card" style="border-right:4px solid #0891b2"><strong>موجودی واقعی کیف پول نمایندگی آینکس (فقط استعلام)</strong><div style="font-size:24px;font-weight:900;margin-top:10px"><?= $inaxCredit===null ? e($inaxCreditError??'نامشخص') : e(number_format($inaxCredit)).' تومان' ?></div><p style="color:#64748b">این عدد فقط با متد credit خوانده می‌شود؛ خرید یا کسر موجودی انجام نمی‌شود.</p></section>
 <section class="dry-card">
 <div class="dry-note">این صفحه محیط تست رسمی آینکس نیست. نتیجه موفق ساختگی به معنای پذیرش مبلغ ۲۲٬۰۰۰ تومان یا شارژ شدن سیم‌کارت نیست. قوانین مبلغ این صفحه از کد فعلی سرور خوانده می‌شود.</div>
 <form method="post" style="margin-top:18px"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
 <div class="dry-grid">
  <div class="dry-field"><label>شماره موبایل تست</label><input name="mobile" maxlength="20" value="<?= e($input['mobile']) ?>" required></div>
  <div class="dry-field"><label>اپراتور</label><select name="operator"><?php foreach(['MTN'=>'ایرانسل','MCI'=>'همراه اول','RTL'=>'رایتل'] as $k=>$v): ?><option value="<?= e($k) ?>" <?= $input['operator']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach ?></select></div>
  <div class="dry-field"><label>مبلغ (تومان)</label><input name="amount" inputmode="numeric" value="<?= e($input['amount']) ?>" required></div>
  <div class="dry-field"><label>نوع شارژ</label><select name="charge_type"><?php foreach(['normal'=>'معمولی','amazing'=>'شگفت‌انگیز','permanent'=>'دائمی'] as $k=>$v): ?><option value="<?= e($k) ?>" <?= $input['charge_type']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach ?></select></div>
  <div class="dry-field"><label>شناسه کاربر برای کنترل موجودی (اختیاری)</label><input name="user_id" inputmode="numeric" value="<?= e($input['user_id']) ?>" placeholder="بدون تغییر موجودی"></div>
  <div class="dry-field"><label>سناریوی پاسخ ساختگی</label><select name="scenario"><?php foreach(['success'=>'موفق آزمایشی','failed'=>'خطای مبلغ نامعتبر (-44)','pending'=>'در انتظار (-200)','timeout'=>'قطع ارتباط'] as $k=>$v): ?><option value="<?= e($k) ?>" <?= $input['scenario']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach ?></select></div>
 </div><p style="margin-top:20px"><button class="dry-btn" type="submit">اجرای تست امن و ثبت گزارش</button> <a href="topup_orders.php" style="margin-right:14px">بازگشت به شارژ و اینترنت</a></p></form></section>
 <?php if($results): ?><section class="dry-card"><h3>گزارش اجرای آزمایشی</h3><?php foreach($results as $r): ?><div class="dry-row"><strong><?= e($r['stage']) ?></strong><span class="<?= $r['passed']?'dry-pass':'dry-fail' ?>"><?= $r['passed']?'✓ عبور':'✕ نیاز به بررسی' ?> — <?= e($r['detail']) ?></span></div><?php endforeach ?><p class="dry-note" style="margin-top:16px">هیچ سفارش واقعی ثبت نشده است. برای مشاهده لاگ، فایل error_log سرور PHP را با عبارت AFARIEX_TOPUP_DRY_RUN جست‌وجو کنید. شماره موبایل و شناسه کاربر در این لاگ ثبت نمی‌شوند.</p></section><?php endif ?>
</div>
<?php render_page_end(); ?>
