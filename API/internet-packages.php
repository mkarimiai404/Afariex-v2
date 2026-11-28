<?php
declare(strict_types=1);
require_once __DIR__.'/lib/internet_common.php';
[$data,$user]=internet_require_request();
$pdo=db();
$s=$pdo->query("SELECT product_id AS id,title,operator,internet_type,sim_type,amount FROM inax_internet_catalog WHERE is_active=1 ORDER BY operator,internet_type,amount LIMIT 500");
$packages=$s->fetchAll(PDO::FETCH_ASSOC);
foreach($packages as &$p) $p['amount']=(int)$p['amount']; unset($p);
topup_json(['success'=>true,'data'=>['packages'=>$packages]]);
