<?php
declare(strict_types=1);
require_once __DIR__.'/layout.php';require_once __DIR__.'/../API/lib/internet_common.php';require_once __DIR__.'/../API/lib/InaxClient.php';
require_login();require_permission('view');
$rows=table_exists('inax_internet_catalog')?db()->query('SELECT * FROM inax_internet_catalog ORDER BY is_active DESC,id DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC):[];
$report=[];$selected='';$scenario='success';$mobile='09123456789';
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
 verify_csrf_or_fail($_POST['csrf_token']??null);
 $selected=trim((string)($_POST['product_id']??''));$scenario=trim((string)($_POST['scenario']??'success'));$mobile=trim((string)($_POST['mobile']??''));
 $found=null;foreach($rows as $r)if($r['product_id']===$selected)$found=$r;
 $add=static function($name,$ok,$detail)use(&$report){$report[]=[$name,$ok,$detail];};
 $normalized=topup_normalize_mobile($mobile);$add('شماره موبایل',!!$normalized,'اعتبارسنجی قالب شماره'.($normalized?' موفق':' ناموفق'));
 $add('انتخاب بسته',!!$found,$found?'نام: '.$found['title']:'بسته در کاتالوگ ثبت نشده است.');
 if($found){
  $add('فعال بودن بسته',(int)$found['is_active']===1,(int)$found['is_active']===1?'فعال':'بسته در حال حاضر برای خرید واقعی غیرفعال است.');
  try{$f=service_fee_calculate('internet_package',(int)$found['amount']);$add('محاسبه کارمزد',true,'قیمت '.number_format((int)$found['amount']).' + کارمزد '.number_format($f['fee_amount']).' = '.number_format($f['total_amount']).' تومان');}
  catch(Throwable $e){$add('محاسبه کارمزد',false,'ساختار کارمزد آماده نیست.');}
 }
 $mock=match($scenario){'success'=>['code'=>1,'ref_code'=>'MOCK-NOT-REAL'],'failed'=>['code'=>-44],'pending'=>['code'=>-200],default=>['status'=>'network_timeout']};
 $add('شبیه‌سازی پاسخ آینکس',true,json_encode($mock,JSON_UNESCAPED_UNICODE));
 $add('ایمنی تست',true,'فراخوانی خرید آینکس: صفر؛ کسر موجودی: صفر؛ ثبت سفارش: صفر');
 error_log('[AFARIEX_INTERNET_DRY_RUN] '.json_encode(['product_id'=>$selected,'scenario'=>$scenario,'purchase_sent'=>false,'wallet_debited'=>false],JSON_UNESCAPED_UNICODE));
}
$credit=null;$creditError='';
// Read-only credit inquiry; never call topup or internet methods in this page.
try{$r=(new InaxClient())->credit();if((int)($r['code']??0)!==1)throw new RuntimeException('PROVIDER_RESPONSE_FAILED');$candidate=$r['credit']??($r['data']['credit']??null);if(!is_numeric($candidate))throw new RuntimeException('BALANCE_FORMAT_UNKNOWN');$credit=(int)$candidate;}
catch(Throwable $e){$creditError='استعلام خواندنی موجودی آینکس در دسترس نیست.';}
render_page_start('تست امن بسته اینترنت','topup_orders');
?>
<style>.it-hero{padding:28px;background:linear-gradient(120deg,#102a43,#0e7490);color:white;border-radius:19px}.it-card{background:white;border-radius:17px;padding:24px;margin-top:16px;border:1px solid #dbeafe}.it-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}.it-field{display:grid;gap:7px}.it-field input,.it-field select{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:11px}.it-report{padding:13px 0;border-bottom:1px solid #e2e8f0}@media(max-width:700px){.it-form{grid-template-columns:1fr}}</style>
<section class="it-hero"><h2 style="color:white">تست امن خرید اینترنت</h2>سناریوهای خرید و کارمزد را بدون ثبت سفارش یا کسر اعتبار شبیه‌سازی کنید.</section>
<div class="it-card"><strong>موجودی واقعی حساب نمایندگی آینکس (فقط استعلام)</strong><h2><?= $credit===null?e($creditError):e(number_format($credit)).' تومان' ?></h2><small>داده از متد read-only credit خوانده می‌شود، نه عملیات خرید.</small><p><a class="btn btn-light" href="inax_wallet.php">استعلام / افزایش موجودی آینکس</a></p></div>
<div class="it-card"><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><div class="it-form"><div class="it-field"><label>بسته اینترنت</label><select name="product_id" required><?php foreach($rows as $r):?><option value="<?=e($r['product_id'])?>" <?=$selected===$r['product_id']?'selected':''?>><?=e($r['title'])?> ـ <?=number_format((int)$r['amount'])?> تومان</option><?php endforeach ?></select></div><div class="it-field"><label>شماره موبایل</label><input name="mobile" value="<?=e($mobile)?>"></div><div class="it-field"><label>سناریوی پاسخ ساختگی</label><select name="scenario"><?php foreach(['success'=>'موفق آزمایشی','failed'=>'خطای قطعی','pending'=>'معلق','timeout'=>'قطع ارتباط'] as $k=>$v):?><option value="<?=e($k)?>" <?=$scenario===$k?'selected':''?>><?=e($v)?></option><?php endforeach ?></select></div></div><p><button class="btn btn-primary">اجرای تست بدون برداشت</button> <a href="internet_catalog.php">مدیریت بسته‌ها</a></p></form></div>
<?php if($report):?><div class="it-card"><h3>گزارش تست</h3><?php foreach($report as $x):?><div class="it-report"><strong><?=e($x[0])?></strong> — <span style="color:<?=$x[1]?'#15803d':'#b91c1c'?>"><?=$x[1]?'✓':'✕'?> <?=e($x[2])?></span></div><?php endforeach ?></div><?php endif ?>
<?php render_page_end() ?>
