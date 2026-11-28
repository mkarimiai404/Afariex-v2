<?php
declare(strict_types=1);
require_once __DIR__.'/layout.php';
require_once __DIR__.'/../API/lib/InaxClient.php';
require_login();require_permission('view');
$notice='';$error='';$products=[];$diagnostics=null;
// Diagnostics deliberately include structural metadata only, never raw provider values.
function ix_shape(mixed $v): string { return is_array($v) ? (array_is_list($v)?'list':'object') : get_debug_type($v); }
function ix_keys(mixed $v): array {
 if(!is_array($v)) return [];
 return array_slice(array_map(static fn($k):string => substr((string)$k,0,45),array_keys($v)),0,30);
}
function ix_product_fields(array $p): array {
 $fields=[];
 foreach(['id','product_id','code','name','title','amount','price','value','products','data','items','list'] as $k) {
  if(array_key_exists($k,$p)) $fields[$k]=ix_shape($p[$k]);
 }
 return $fields;
}

$schema=table_exists('inax_internet_catalog');
if(($_SERVER['REQUEST_METHOD']??'')==='POST') {
 require_permission('edit');verify_csrf_or_fail($_POST['csrf_token']??null);
 if(!$schema)$error='ابتدا SQL شماره ۰۲۲ را اجرا کنید.';
 elseif(($_POST['action']??'')==='refresh') {
  try {
   // Read-only products call; no INAX wallet deduction.
   $result=(new InaxClient())->products();
   if((int)($result['code']??0)!==1) throw new RuntimeException('PROVIDER_RESPONSE_FAILED');
   // INAX documents id/name/amount. Products may be a JSON list or an ID-keyed map.
   $list=$result['products']??($result['data']['products']??null);
   $structure=['response_keys'=>ix_keys($result),'products_type'=>ix_shape($result['products']??null),'data_type'=>ix_shape($result['data']??null),'data_keys'=>ix_keys($result['data']??null),'candidate_type'=>ix_shape($list),'candidate_keys'=>ix_keys($list)];
   if(is_string($list)) $list=json_decode($list,true);
   if(!is_array($list)) throw new RuntimeException('INVALID_PRODUCT_LIST');
   // INAX groups its catalog under products.internet; that value is the actual list.
   // Never interpret the category container itself as a sellable product.
   $groupedInternet = array_key_exists('internet',$list);
   if($groupedInternet) {
    $list=$list['internet'];
    if(is_string($list)) $list=json_decode($list,true);
    if(!is_array($list)) throw new RuntimeException('INVALID_INTERNET_CATEGORY');
   } elseif(!array_is_list($list)) {
    // Unknown grouping: do not accidentally import non-internet services.
    throw new RuntimeException('INTERNET_CATEGORY_NOT_FOUND');
   }
   $structure['selected_category']=$groupedInternet?'products.internet':'products';
   $structure['selected_type']=ix_shape($list);
   $structure['selected_count']=count($list);
   $diagnostics=['received'=>count($list),'imported'=>0,'not_internet'=>0,'invalid'=>0,'examples'=>[], 'invalid_reasons'=>[], 'structure'=>$structure,'first_item_type'=>'empty','first_item_keys'=>[], 'first_item_fields'=>[]];
   if($list!==[]) {
    $first=reset($list);
    $diagnostics['first_item_type']=ix_shape($first);
    $diagnostics['first_item_keys']=ix_keys($first);
    if(is_array($first)) $diagnostics['first_item_fields']=ix_product_fields($first);
   }
    // Classify conservatively: unknown metadata is never sufficient for activation.
    $insert=db()->prepare("INSERT INTO inax_internet_catalog (product_id,title,operator,internet_type,sim_type,amount,is_active,last_seen_at) VALUES (?,?,?,?,?,?,0,NOW()) ON DUPLICATE KEY UPDATE title=VALUES(title),amount=VALUES(amount),is_active=0,last_seen_at=NOW()");
    $diagnostics['excluded_non_data']=0;
    $diagnostics['needs_manual_review']=0;
    $diagnostics['price_unit_note']='مبلغ از فیلد amount دریافت می‌شود؛ واحد تومان باید با مستندات آینکس تأیید شود.';
    foreach($list as $key=>$p){
     if(!is_array($p)){$diagnostics['invalid']++;$diagnostics['invalid_reasons']['not_object']=($diagnostics['invalid_reasons']['not_object']??0)+1;continue;}
     $idValue=$p['id']??(is_string($key)?$key:'');
     $id=is_scalar($idValue)?trim((string)$idValue):'';
     $nameValue=$p['name']??'';
     $name=is_string($nameValue)?trim($nameValue):'';
     $amount=$p['amount']??null;
     $reason=null;
     if(!preg_match('/\A[a-zA-Z0-9._:-]{1,64}\z/D',$id)) $reason='invalid_id';
     elseif($name===''||mb_strlen($name)>255) $reason='invalid_name';
     elseif(!is_numeric($amount)||$amount<=0||$amount>100000000 || (float)$amount !== (float)(int)$amount) $reason='invalid_amount';
     if($reason!==null){$diagnostics['invalid']++;$diagnostics['invalid_reasons'][$reason]=($diagnostics['invalid_reasons'][$reason]??0)+1;continue;}
     // Explicitly exclude pure voice/SMS products. Mixed data bundles remain for manual review.
     $hasData=(bool)preg_match('/اینترنت|internet|دیتا|data|گیگ(?:ابایت)?|مگ(?:ابایت)?|\b(?:gb|mb)\b/iu',$name);
     $isVoiceOrSms=(bool)preg_match('/پیامک|پيامک|sms|مکالمه|تماس صوتی|تماس صوتي|دقیقه تماس|دقيقه تماس|دقیقه مکالمه|دقيقه مکالمه/iu',$name);
     if($isVoiceOrSms && !$hasData){$diagnostics['excluded_non_data']++;continue;}
     if(!$hasData){$diagnostics['not_internet']++;if(count($diagnostics['examples'])<8)$diagnostics['examples'][]=mb_substr($name,0,90);continue;}
     $opRaw=strtoupper(trim((string)($p['operator']??'')));
     $opMap=['MTN'=>'MTN','IRANCELL'=>'MTN','MCI'=>'MCI','HAMRAH_AVAL'=>'MCI','RTL'=>'RTL','RIGHTEL'=>'RTL','SHT'=>'SHT'];
     $operator=$opMap[$opRaw]??null;
     $simRaw=strtolower(trim((string)($p['sim_type']??'')));
     $simMap=['credit'=>'credit','prepaid'=>'credit','permanent'=>'permanent','postpaid'=>'permanent'];
     $sim=$simMap[$simRaw]??null;
     $typeRaw=strtolower(trim((string)($p['internet_type']??'')));
     $typeMap=['hourly'=>'hourly','daily'=>'daily','weekly'=>'weekly','monthly'=>'monthly','yearly'=>'yearly','amazing'=>'amazing','tdlte'=>'TDLTE'];
     $type=$typeMap[$typeRaw]??null;
     // DB columns require values; fallback values are placeholders ONLY, not verified metadata.
     if($operator===null||$sim===null||$type===null||$isVoiceOrSms) $diagnostics['needs_manual_review']++;
     $insert->execute([$id,$name,$operator??'MTN',$type??'monthly',$sim??'credit',(int)$amount]);
     $diagnostics['imported']++;
    }
   $added=$diagnostics['imported'];
   $notice='فهرست آینکس خوانده شد. '.$added.' محصول اینترنتی وارد/به‌روزرسانی شد. همه محصولات پردازش‌شده برای جلوگیری از فروش با مشخصات تأییدنشده غیرفعال شدند.';
  }catch(Throwable $e){error_log('[INTERNET_CATALOG_REFRESH] '.get_class($e));$error='دریافت فهرست آینکس انجام نشد؛ تنظیمات اتصال یا ساختار پاسخ را بررسی کنید.';}
 } elseif(($_POST['action']??'')==='save') {
  $id=(int)($_POST['id']??0);$operator=(string)($_POST['operator']??'');$type=(string)($_POST['internet_type']??'');$sim=(string)($_POST['sim_type']??'');
  if($id<=0||!in_array($operator,['MCI','MTN','RTL','SHT'],true)||!in_array($type,['hourly','daily','weekly','monthly','yearly','amazing','TDLTE'],true)||!in_array($sim,['credit','permanent'],true)) $error='اطلاعات بسته معتبر نیست.';
  else {
   $s=db()->prepare('UPDATE inax_internet_catalog SET operator=?,internet_type=?,sim_type=?,is_active=? WHERE id=?');
   $s->execute([$operator,$type,$sim,isset($_POST['is_active'])?1:0,$id]);$notice='تنظیمات بسته ذخیره شد.';
  }
 }
}
$rows=$schema?db()->query('SELECT * FROM inax_internet_catalog ORDER BY last_seen_at DESC,id DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC):[];
render_page_start('مدیریت بسته اینترنت آینکس','topup_orders');
?>
<style>.ix-hero{padding:24px;border-radius:20px;background:linear-gradient(120deg,#0f172a,#155e75);color:#fff}.ix-hero h2{color:#fff;margin:0 0 10px}.ix-row{border:1px solid #e2e8f0;border-radius:14px;margin:12px 0;padding:16px;background:#fff}.ix-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.ix-grid select{width:100%}.ix-actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap}@media(max-width:800px){.ix-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}</style>
<div class="ix-hero"><h2>مدیریت بسته‌های اینترنت آینکس</h2>دریافت فهرست محصولات فقط عملیات خواندنی است. بسته‌های تازه به‌صورت غیرفعال وارد می‌شوند تا قبل از فروش اپراتور، مدت و نوع سیم‌کارت را کنترل کنید.</div>
<?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif ?>
<?php if($notice):?><div class="alert success"><?=e($notice)?></div><?php endif ?>
<?php if($diagnostics!==null):?><div class="card" style="margin-top:12px"><strong>گزارش تشخیص محصولات آینکس</strong><p>دریافتی: <?=number_format($diagnostics['received'])?> · واردشده: <?=number_format($diagnostics['imported'])?> · نام نامرتبط یا نامشخص: <?=number_format($diagnostics['not_internet'])?> · اطلاعات نامعتبر: <?=number_format($diagnostics['invalid'])?> · خدمات غیراینترنتی حذف‌شده: <?=number_format($diagnostics['excluded_non_data']??0)?> · نیازمند بررسی دستی: <?=number_format($diagnostics['needs_manual_review']??0)?></p><div style="margin:14px 0;padding:14px;border:1px solid #cbd5e1;border-radius:12px;background:#f8fafc"><strong>ساختار پاسخ آینکس (بدون نمایش مقادیر)</strong><p style="font-size:12px">این گزارش فقط نام کلیدها و نوع داده‌ها را نشان می‌دهد؛ مقدار فیلدها، رمز و شناسه‌های محصولات نمایش داده نمی‌شوند.</p><pre dir="ltr" style="text-align:left;white-space:pre-wrap;overflow-wrap:anywhere;max-height:340px;overflow:auto"><?=e((string)json_encode(['structure'=>$diagnostics['structure'],'first_item_type'=>$diagnostics['first_item_type'],'first_item_keys'=>$diagnostics['first_item_keys'],'first_item_fields'=>$diagnostics['first_item_fields'],'invalid_reasons'=>$diagnostics['invalid_reasons'],'price_unit_note'=>$diagnostics['price_unit_note']??''],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE))?></pre></div><?php if($diagnostics['examples']):?><p>نمونه عنوان‌های شناسایی‌نشده (صرفاً برای بررسی؛ وارد نشده‌اند):</p><ul><?php foreach($diagnostics['examples'] as $example):?><li><?=e($example)?></li><?php endforeach ?></ul><?php endif ?><p style="font-size:12px">هیچ خریدی انجام نشده است. تمام بسته‌های جدید تا تأیید مدیر غیرفعال هستند.</p></div><?php endif ?>
<div class="card ix-actions"><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="refresh"><button class="btn btn-primary">به‌روزرسانی محصولات از آینکس (بدون خرید)</button></form><a class="btn btn-light" href="internet_dry_run.php">تست امن اینترنت</a><a class="btn btn-light" href="internet_orders.php">سفارش‌های اینترنت</a><a href="topup_orders.php">شارژ موبایل</a></div>
<?php if(!$schema):?><div class="alert error">جدول‌های دیتابیس هنوز نصب نشده‌اند.</div><?php endif ?>
<?php foreach($rows as $row):?><form class="ix-row" method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><div style="font-weight:800;margin-bottom:12px"><?=e($row['title'])?> <small dir="ltr">#<?=e($row['product_id'])?></small> · <?=number_format((int)$row['amount'])?> تومان</div><div class="ix-grid"><div><label>اپراتور</label><select class="select" name="operator"><?php foreach(['MTN'=>'ایرانسل','MCI'=>'همراه اول','RTL'=>'رایتل','SHT'=>'شاتل'] as $k=>$v):?><option value="<?=e($k)?>" <?= $row['operator']===$k?'selected':'' ?>><?=e($v)?></option><?php endforeach ?></select></div><div><label>مدت بسته</label><select class="select" name="internet_type"><?php foreach(['hourly'=>'ساعتی','daily'=>'روزانه','weekly'=>'هفتگی','monthly'=>'ماهانه','yearly'=>'سالانه','amazing'=>'شگفت‌انگیز','TDLTE'=>'TDLTE'] as $k=>$v):?><option value="<?=e($k)?>" <?= $row['internet_type']===$k?'selected':'' ?>><?=e($v)?></option><?php endforeach ?></select></div><div><label>نوع سیم‌کارت</label><select class="select" name="sim_type"><option value="credit" <?=$row['sim_type']==='credit'?'selected':''?>>اعتباری</option><option value="permanent" <?=$row['sim_type']==='permanent'?'selected':''?>>دائمی</option></select></div><div><label><input type="checkbox" name="is_active" <?=(int)$row['is_active']===1?'checked':''?>> فعال برای خرید واقعی</label><div style="margin-top:8px"><button class="btn btn-primary btn-sm">ذخیره</button></div></div></div></form><?php endforeach ?>
<?php if(!$rows):?><div class="card">فعلاً بسته‌ای وارد نشده است. از گزینه به‌روزرسانی استفاده کنید. در صورت تفاوت ساختار نام‌گذاری آینکس، لازم است طبقه‌بندی خروجی رسمی بررسی شود.</div><?php endif ?>
<?php render_page_end() ?>
