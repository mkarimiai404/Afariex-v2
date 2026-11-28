<?php
declare(strict_types=1);
require_once __DIR__.'/layout.php';require_once __DIR__.'/../API/lib/InaxClient.php';
require_login();require_permission('view');
$balance=null;$message='';$paymentUrl=null;$error='';
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
 require_permission('edit');verify_csrf_or_fail($_POST['csrf_token']??null);
 $raw=normalize_persian_digits((string)($_POST['amount']??''));$raw=str_replace([',','٬',' '],'',$raw);
 if(!preg_match('/\A[1-9][0-9]{3,8}\z/D',$raw))$error='مبلغ معتبر وارد کنید؛ حداقل ۱٬۰۰۰ و حداکثر ۹۹۹٬۹۹۹٬۹۹۹ تومان.';
 else{
  try {
   $response=(new InaxClient())->addFund((int)$raw);
   $url=trim((string)($response['url']??''));$parts=parse_url($url);
   $host=strtolower((string)($parts['host']??''));
   if((int)($response['code']??0)!==1||($parts['scheme']??'')!=='https'||!in_array($host,['inax.ir','www.inax.ir'],true)||isset($parts['user'])||isset($parts['pass']))throw new RuntimeException('UNEXPECTED_PROVIDER_RESPONSE');
   $paymentUrl=$url;$message='درخواست افزایش اعتبار آینکس ایجاد شد. پرداخت تنها پس از ورود خودتان به درگاه رسمی انجام می‌شود.';
  } catch(Throwable $e){error_log('[INAX_ADDFUND] '.get_class($e));$error='ایجاد لینک افزایش اعتبار انجام نشد. لطفاً تنظیمات آینکس را بررسی کنید.';}
 }
}
try{$r=(new InaxClient())->credit();$v=$r['credit']??($r['data']['credit']??null);if((int)($r['code']??0)===1 && is_numeric($v))$balance=(int)$v;else $error=$error?:'پاسخ موجودی قابل تشخیص نیست.';}
catch(Throwable $e){$error=$error?:'امکان استعلام موجودی در حال حاضر وجود ندارد.';}
render_page_start('کیف پول نمایندگی آینکس','topup_orders');
?>
<style>.iw-box{max-width:760px;margin:18px auto;padding:26px;border-radius:20px;background:#fff;border:1px solid #e2e8f0}.iw-head{background:linear-gradient(125deg,#0f172a,#155e75);border-radius:20px;color:white;padding:25px}.iw-head h2{color:white}.iw-field{width:100%;padding:14px;border:1px solid #cbd5e1;border-radius:13px;font:inherit}.iw-note{background:#f0fdfa;border-radius:12px;padding:14px;line-height:2;margin:16px 0}</style>
<div class="iw-box"><div class="iw-head"><h2>موجودی حساب نمایندگی آینکس</h2><div style="font-size:29px;font-weight:900"><?=$balance===null?'نامشخص':e(number_format($balance)).' تومان'?></div><small>استعلام خواندنی credit بدون کسر موجودی</small></div>
<?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif ?>
<div class="iw-note">برای افزایش موجودی، آینکس لینک پرداخت رسمی صادر می‌کند. صرفاً وارد کردن مبلغ یا ایجاد لینک، از حساب آینکس یا کیف پول کاربران پولی کسر نمی‌کند؛ پرداخت در صفحه رسمی و با تأیید شما انجام می‌شود.</div>
<form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><label>مبلغ افزایش موجودی (تومان)</label><input class="iw-field" name="amount" inputmode="numeric" placeholder="مثلاً ۱٬۰۰۰٬۰۰۰" required><p><button class="btn btn-primary" type="submit">دریافت لینک پرداخت رسمی آینکس</button></p></form>
<?php if($paymentUrl):?><div class="alert success"><?=e($message)?></div><p><a class="btn btn-primary" href="<?=e($paymentUrl)?>" target="_blank" rel="noopener noreferrer">ورود به درگاه رسمی آینکس</a></p><?php endif ?>
<p><a href="topup_dry_run.php">تست امن شارژ</a> · <a href="internet_dry_run.php">تست امن اینترنت</a> · <a href="topup_orders.php">بازگشت به سفارش‌ها</a></p></div>
<?php render_page_end(); ?>
