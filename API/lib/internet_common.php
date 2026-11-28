<?php
declare(strict_types=1);
require_once __DIR__ . '/topup_common.php';

function internet_schema_ready(): bool {
    return table_exists('inax_internet_catalog') && table_exists('inax_internet_orders');
}
function internet_fail(string $code, string $message, int $http = 422): never {
    topup_json(['success'=>false,'status'=>'failed','code'=>$code,'message'=>$message],$http);
}
function internet_require_request(): array {
    topup_headers();
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') internet_fail('METHOD_NOT_ALLOWED','درخواست نامعتبر است.',405);
    $data=topup_request_data();
    $user=topup_authenticate_user($data);
    if (!internet_schema_ready()) internet_fail('INTERNET_SCHEMA_MISSING','ابتدا ساختار دیتابیس بسته اینترنت را نصب کنید.',503);
    return [$data,$user];
}
function internet_order_data(array $row): array {
    return ['order_id'=>$row['order_id'],'client_request_id'=>$row['client_request_id'],
      'product_title'=>$row['product_title'],'operator'=>$row['operator'],'mobile'=>$row['mobile'],
      'amount'=>(int)$row['amount'],'fee_amount'=>(int)$row['fee_amount'],
      'total_amount'=>(int)$row['total_cost'],'status'=>$row['status']==='completed'?'success':($row['status']==='refunded'?'failed':'pending'),
      'tracking_code'=>$row['provider_ref_code'],'created_at'=>$row['created_at']];
}
function internet_get_order(PDO $pdo,int $userId,string $uuid,bool $lock=false): ?array {
    $s=$pdo->prepare('SELECT * FROM inax_internet_orders WHERE user_id=? AND client_request_id=? LIMIT 1'.($lock?' FOR UPDATE':''));
    $s->execute([$userId,$uuid]); return $s->fetch(PDO::FETCH_ASSOC)?:null;
}
function internet_settle(PDO $pdo,int $id,array $response,string $class): array {
    $pdo->beginTransaction();
    try {
        $s=$pdo->prepare('SELECT * FROM inax_internet_orders WHERE id=? FOR UPDATE');$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('ORDER_NOT_FOUND');
        if ($row['status']!=='pending') { $pdo->commit(); return $row; }
        $code=topup_provider_code($response);
        $tid=topup_provider_trans_id($response['trans_id']??null);
        $ref=topup_provider_trans_id($response['ref_code']??null);
        if ($class==='success') {
            $s=$pdo->prepare("UPDATE inax_internet_orders SET status='completed',provider_code=?,provider_trans_id=COALESCE(?,provider_trans_id),provider_ref_code=COALESCE(?,provider_ref_code),last_checked_at=UTC_TIMESTAMP() WHERE id=? AND status='pending'");
            $s->execute([$code,$tid,$ref,$id]);
            if ($s->rowCount()===1) service_fee_update_status($pdo,'internet_package',$id,'collected');
        } elseif ($class==='failed') {
            $s=$pdo->prepare("UPDATE inax_internet_orders SET status='refunded',provider_code=?,provider_trans_id=COALESCE(?,provider_trans_id),failure_reason='PROVIDER_CONFIRMED_FAILURE',last_checked_at=UTC_TIMESTAMP() WHERE id=? AND status='pending'");
            $s->execute([$code,$tid,$id]);
            if ($s->rowCount()===1) {
                wallet_credit($pdo,(int)$row['user_id'],(int)$row['total_cost'],'Internet package provider confirmed failure',$row['order_id']);
                service_fee_update_status($pdo,'internet_package',$id,'refunded');
            }
        } else {
            $s=$pdo->prepare('UPDATE inax_internet_orders SET provider_code=?,provider_trans_id=COALESCE(?,provider_trans_id),last_checked_at=UTC_TIMESTAMP() WHERE id=?');
            $s->execute([$code,$tid,$id]);
        }
        $s=$pdo->prepare('SELECT * FROM inax_internet_orders WHERE id=?');$s->execute([$id]);$final=$s->fetch(PDO::FETCH_ASSOC);
        $pdo->commit(); return $final;
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
}
