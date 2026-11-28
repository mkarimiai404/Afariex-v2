<?php
declare(strict_types=1);
require_once __DIR__.'/lib/internet_common.php';
[$data,$user]=internet_require_request();
$uid=(int)$user['id']; $pdo=db();
$uuid=topup_validate_client_request_id($data['client_request_id']??null);
if($uuid===null) internet_fail('INVALID_CLIENT_REQUEST_ID','شناسه درخواست معتبر نیست.');
$mobile=topup_normalize_mobile($data['mobile']??'');
if($mobile===null) internet_fail('INVALID_MOBILE','شماره موبایل نامعتبر است.');
$productId=trim((string)($data['package_id']??''));
if(!preg_match('/\A[a-zA-Z0-9._:-]{1,64}\z/D',$productId)) internet_fail('INVALID_PACKAGE','شناسه بسته نامعتبر است.');
$operator=strtoupper(trim((string)($data['operator']??'')));
$simType=strtolower(trim((string)($data['sim_type']??'credit')));
if(!in_array($simType,['credit','permanent'],true)) internet_fail('INVALID_SIM_TYPE','نوع سیم‌کارت معتبر نیست.');
if(in_array(strtolower((string)($data['payment_method']??'wallet')),['online','gateway'],true)) internet_fail('WALLET_ONLY','خرید با کیف پول انجام می‌شود.');
$s=$pdo->prepare('SELECT * FROM inax_internet_catalog WHERE product_id=? AND is_active=1 LIMIT 1');$s->execute([$productId]);$package=$s->fetch(PDO::FETCH_ASSOC);
if(!$package||$package['operator']!==$operator||$package['sim_type']!==$simType) internet_fail('PACKAGE_UNAVAILABLE','بسته برای این اپراتور یا نوع سیم‌کارت فعال نیست.');
// An idempotent retry never calls INAX again.
$existing=internet_get_order($pdo,$uid,$uuid);
if($existing){
 if($existing['product_id']!==$productId||$existing['mobile']!==$mobile||$existing['operator']!==$operator) internet_fail('IDEMPOTENCY_CONFLICT','این شناسه قبلاً برای خرید دیگری استفاده شده است.',409);
 topup_json(['success'=>$existing['status']==='completed','status'=>internet_order_data($existing)['status'],'data'=>internet_order_data($existing)]);
}
try {
 $pdo->beginTransaction();
 $lock=$pdo->prepare('SELECT balance FROM users WHERE id=? FOR UPDATE');$lock->execute([$uid]);
 if($lock->fetchColumn()===false) throw new RuntimeException('USER_NOT_FOUND');
 $existing=internet_get_order($pdo,$uid,$uuid);
 if($existing){$pdo->commit();topup_json(['success'=>$existing['status']==='completed','status'=>internet_order_data($existing)['status'],'data'=>internet_order_data($existing)]);}
 $s=$pdo->prepare('SELECT * FROM inax_internet_catalog WHERE product_id=? AND is_active=1');$s->execute([$productId]);$package=$s->fetch(PDO::FETCH_ASSOC);
 if(!$package||$package['operator']!==$operator||$package['sim_type']!==$simType) throw new RuntimeException('PACKAGE_CHANGED');
 $fee=service_fee_calculate('internet_package',(int)$package['amount'],$pdo);
 $total=(int)$fee['total_amount'];
 $orderId=topup_order_id();
 $wallet=wallet_debit($pdo,$uid,$total,'Internet package',$orderId);
 $s=$pdo->prepare("INSERT INTO inax_internet_orders (user_id,client_request_id,order_id,product_id,product_title,operator,internet_type,sim_type,mobile,amount,fee_amount,total_cost,status,provider_request_started_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'pending',UTC_TIMESTAMP())");
 $s->execute([$uid,$uuid,$orderId,$productId,$package['title'],$operator,$package['internet_type'],$simType,$mobile,(int)$package['amount'],(int)$fee['fee_amount'],$total]);
 $id=(int)$pdo->lastInsertId();
 service_fee_record($pdo,$uid,$fee,'internet_package',$id,'pending');
 $pdo->commit();
} catch (WalletInsufficientFundsException $e){if($pdo->inTransaction())$pdo->rollBack();internet_fail('INSUFFICIENT_BALANCE','موجودی کیف پول برای مبلغ بسته و کارمزد کافی نیست.');}
catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('[INTERNET_RESERVE] '.get_class($e));internet_fail('RESERVATION_FAILED','ثبت سفارش انجام نشد. لطفاً سفارش‌های خود را بررسی کنید.',503);}
// Only this request sends purchase. Replayed client_request_id cannot send twice.
ignore_user_abort(true);
try {
 db_disconnect();
 $response=(new InaxClient())->internet($productId,$operator,$mobile,(string)$package['internet_type'],$simType,$orderId);
 $code=topup_provider_code($response);
 $class=topup_provider_result_class($code);
 // Bare code=1 on the purchase endpoint is an explicit successful credit purchase per INAX docs.
 $row=internet_settle(db(),$id,$response,$class);
} catch(Throwable $e){
 // Network uncertainty: NEVER refund or repeat provider purchase automatically.
 error_log('[INTERNET_PROVIDER_UNCERTAIN] order_id='.$orderId.' error='.get_class($e));
 $s=db()->prepare('SELECT * FROM inax_internet_orders WHERE id=?');$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);
}
$view=internet_order_data($row);
topup_json(['success'=>$view['status']==='success','status'=>$view['status'],'message'=>match($view['status']){'success'=>'بسته اینترنت با موفقیت خریداری شد.','failed'=>'خرید ناموفق بود؛ مبلغ به کیف پول برگشت داده شد.',default=>'نتیجه نهایی هنوز مشخص نیست؛ تا تعیین وضعیت دوباره خرید نکنید.'},'data'=>$view]);
