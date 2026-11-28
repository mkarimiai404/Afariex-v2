<?php
declare(strict_types=1);
require_once __DIR__.'/layout.php';require_login();require_permission('view');
$ready=table_exists('inax_internet_orders');$rows=[];
if($ready){$rows=db()->query('SELECT * FROM inax_internet_orders ORDER BY id DESC LIMIT 150')->fetchAll(PDO::FETCH_ASSOC);}
render_page_start('سفارش‌های بسته اینترنت','topup_orders');
?>
<style>.io-head{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap}.io-table{width:100%;min-width:980px;border-collapse:collapse}.io-table td,.io-table th{padding:13px;text-align:right;border-bottom:1px solid #e2e8f0}.io-table th{background:#f8fafc}.io-pill{padding:6px 10px;border-radius:9px;background:#f1f5f9}.io-note{background:#fffbeb;color:#92400e;border-radius:12px;padding:14px;margin:15px 0}</style>
<div class="card io-head"><h2>سفارش‌های بسته اینترنت</h2><div><a class="btn btn-light" href="internet_catalog.php">مدیریت بسته‌ها</a> <a class="btn btn-primary" href="internet_dry_run.php">تست امن اینترنت</a></div></div>
<div class="io-note">سفارش معلق ممکن است در آینکس انجام شده باشد؛ تا پیش از استعلام قطعی از آینکس، آن را دستی تکرار یا مسترد نکنید.</div>
<div class="card" style="overflow:auto"><?php if(!$ready):?>ابتدا SQL نصب را اجرا کنید.<?php else:?><table class="io-table"><thead><tr><th>سفارش</th><th>کاربر</th><th>بسته</th><th>شماره</th><th>مبلغ</th><th>کارمزد</th><th>کل</th><th>وضعیت</th><th>پیگیری آینکس</th><th>تاریخ</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e($r['order_id'])?></td><td><?= (int)$r['user_id']?></td><td><?=e($r['product_title'])?></td><td dir="ltr"><?=e($r['mobile'])?></td><td><?=number_format((int)$r['amount'])?></td><td><?=number_format((int)$r['fee_amount'])?></td><td><?=number_format((int)$r['total_cost'])?></td><td><span class="io-pill"><?=e(match($r['status']){'completed'=>'موفق','refunded'=>'مستردشده',default=>'در انتظار بررسی'})?></span></td><td><?=e($r['provider_ref_code']?:($r['provider_trans_id']?:'—'))?></td><td><?=e($r['created_at'])?></td></tr><?php endforeach ?></tbody></table><?php endif ?></div>
<?php render_page_end() ?>
