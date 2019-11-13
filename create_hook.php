<?php
$username = 'user@name';
$password = 'password';
$url = 'https://online.moysklad.ru/api/remap/1.1/entity/webhook/';
$ch = curl_init();
$param = [
    'url' => 'https://example.com/moysklad/create_order.php', // расположение файла create_order.php на сайте с битриксом
    'action' => 'UPDATE', // и 'action' => 'CREATE'
    'entityType' => 'retaildemand' // или 'entityType' => 'customerorder'
];
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($param));
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
$output = curl_exec($ch);
$info = curl_getinfo($ch);
$error = curl_error($ch);
curl_close($ch);
print_r($output);




function getData($url, $PUT_TO_ORDER = false, $param = 0){
    $username = 'user@name';
    $password = 'password';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER,true);
    curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
    if ($PUT_TO_ORDER) {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($param));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    $output = curl_exec($ch);
    curl_close($ch);

    return json_decode($output, true);
}
