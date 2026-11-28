<?php
declare(strict_types=1);
require_once __DIR__.'/lib/internet_common.php';
[$data,$user]=internet_require_request();
$uuid=topup_validate_client_request_id($data['client_request_id']??null);
if(!$uuid) internet_fail('INVALID_CLIENT_REQUEST_ID','شناسه درخواست معتبر نیست.');
$pdo=db();$row=internet_get_order($pdo,(int)$user['id'],$uuid);
if(!$row) internet_fail('ORDER_NOT_FOUND','سفارش یافت نشد.',404);
if($row['status']==='pending') {
 // Throttle status queries; do not issue a duplicate purchase under any circumstances.
 $claim=$pdo->prepare("UPDATE inax_internet_orders SET last_checked_at=UTC_TIMESTAMP() WHERE id=? AND status='pending' AND provider_request_started_at < DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 SECOND) AND (last_checked_at IS NULL OR last_checked_at < DATE_SUB(UTC_TIMESTAMP(),INTERVAL 20 SECOND))");
 $claim->execute([(int)$row['id']]);
 if($claim->rowCount()===1) {
  try {
   db_disconnect();
   $result=(new InaxClient())->transStatus((string)$row['order_id'],($row['provider_trans_id']?:null));
   // status=1 without final_status is NOT proof of success, see topup_provider_status_class.
   $class=topup_provider_status_class($result);
   // Negative lookup codes alone cannot prove that an earlier purchase was not applied.
   // Only explicit final failure/refund evidence permits a wallet refund.
   if ($class==='failed' && !in_array(strtolower(trim((string)($result['final_status']??''))),['failed','refund_provider'],true)
       && strtolower(trim((string)($result['payment_status']??'')))!=='refund') $class='pending';
   $row=internet_settle(db(),(int)$row['id'],$result,$class);
  } catch(Throwable $e){error_log('[INTERNET_STATUS_UNCERTAIN] order_id='.$row['order_id'].' error='.get_class($e));}
 }
}
$view=internet_order_data($row);
topup_json(['success'=>true,'status'=>$view['status'],'data'=>$view]);
