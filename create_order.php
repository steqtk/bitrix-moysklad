<?php

//создание заказа в битриксе из МС

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
Bitrix\Main\Loader::includeModule('sale');
Bitrix\Main\Loader::includeModule('catalog');

use Bitrix\Sale;

ini_set('display_errors', 1);

$a = json_decode(file_get_contents('php://input'));
$url = $a->events[0]->meta->href;
$action = $a->events[0]->action;
$type = $a->events[0]->meta->type;

$order = getData($url);
$order_comments = $order['description'];

toLog('mc-request', ['request' => $a, 'name' => $order['name']]);

$customer = getData($order['agent']['meta']['href']);

$user_email = $customer['email'];
$user_phone = $customer['phone'];
$user_name = $customer['name'];
$user_group = $customer['tags'][0];

$order_sum = $order['sum'];
$order_payed_sum = $order['payedSum'];

$currency = getData($order['rate']['currency']['meta']['href'])['isoCode'];

$positions = getData($order['positions']['meta']['href']);

$products = [];
foreach ($positions['rows'] as $p) {

    // цена с 2-мя лишними нулями, без разделителя копеек, может быть это глюк МС и они его потом поправят.
    array_push($products, ['name' => getData($p['assortment']['meta']['href'])['name'], 'quantity' => $p['quantity'], 'price' => $p['price'] / 100]);

}

/**
 * обновляем заказ в битриксе
 */
if (($action == 'UPDATE' && $type == 'customerorder') || ($action == 'UPDATE' && $type == 'retaildemand')) {

    if ($order['name'] !== 'bx-6130') die;

    $payment_method = getData($order['state']['meta']['href']);
toLog('payment',[$payment_method]);
    $bx_id = str_replace('bx-', '', $order['name']);
    $arOrder = CSaleOrder::GetByID($bx_id);

    if ($arOrder['COMMENTS'] == '') {

// в битриксе пустой комментарий
// вставим тот что в есть мс
        $arOrder = Sale\Order::load($bx_id);
        $arOrder->setField('COMMENTS', $order['description']);
        $arOrder->save();
    }

// сверим состав заказов
    $order = Sale\Order::load($bx_id);
    $basket = $order->getBasket();
    $basketItems = $basket->getBasketItems();
    $bx_products = [];
    foreach ($basketItems as $item) {
        $bx_products += [$item->getField('NAME') => $item->getQuantity()];
    }

    $products = [];
    foreach ($positions['rows'] as $p) {
        $products += [getData($p['assortment']['meta']['href'])['name'] => $p['quantity']];
    }

// сравниваем состав заказа мс с битрикс
    $product_compare = array_diff_assoc($products, $bx_products);
    if (count($product_compare) > 0) {
// изменился состав или кол-во в заказе в мс

// соберем массив id товаров из битрикса и кол-во из мс
        foreach ($products as $name => $quantity) {
            $arSelect = ['ID'];
            $arFilter = ['IBLOCK_ID' => 4, 'NAME' => $name, 'ACTIVE' => 'Y'];
            $res = CIBlockElement::GetList([], $arFilter, false, [], $arSelect);
            if ($ob = $res->GetNextElement()) {

                $arFields = $ob->GetFields();
            }
            $bx_products += [$arFields['ID'] => $quantity];
        }

// удалим старые позиции в заказе
        $order = Sale\Order::load($bx_id);
        $basket = $order->getBasket();
        foreach ($basket->getBasketItems() as $item) {
            $item->delete();
        }

// добавим новые позиции и количество
        foreach ($bx_products as $bx_product_id => $bx_product_quantity) {
            //Добавление товара
            $item = $basket->createItem('catalog', (int)$bx_product_id);
            $item->setFields([
                'QUANTITY' => (int)$bx_product_quantity,
                'CURRENCY' => \Bitrix\Currency\CurrencyManager::getBaseCurrency(),
                'LID' => \Bitrix\Main\Context::getCurrent()->getSite(),
                'PRODUCT_PROVIDER_CLASS' => \Bitrix\Catalog\Product\Basket::getDefaultProviderName(),
            ]);
            $item->save();

        }

// cохраним изменения
        $basket->refreshData(['PRICE']);
        $basket->save();
        $order->refreshData();
        $order->save();
    }

//    поставим метод оплаты заказа


// ставим признак совершенного обмена, чтобы исключить дублирование в МС
    $order = Sale\Order::load($bx_id);
    $order->setField('UPDATED_1C','Y');
    $order->save();

    toLog('update-order', ['name' => $order['name'], 'products' => $products]);
}

/**
 * новый заказ в битриксе
 */
if (($action == 'CREATE' && $type == 'customerorder') || ($action == 'CREATE' && $type == 'retaildemand')) {

    $bx_id = str_replace('bx-', '', $order['name']);

    if (($arOrder = CSaleOrder::GetByID($bx_id)) && $type == 'customerorder') {
        toLog('ERROR','заказ с таким id уже есть - '.$bx_id); die(); // заказ с таким id уже есть!
    }


    /**************************
     * формируем заказ в битрикс
     *************************/

    $rsUser = CUser::GetByLogin($user_email);
    if (!$arUser = $rsUser->Fetch()){
        toLog('ERROR', ['Пользователь '.$user_email.' не найден в TL']);die;
    }


// найдем ID продуктов по их названию в МС
// и соберем в корзину
// т.к. МС не группирует одинаковые товары, поэтому делаем в цикле
    $products = [];
    foreach ($positions['rows'] as $p) {

        // цена с 2-мя лишними нулями, без разделителя копеек, может быть это глюк МС и они его потом поправят.
        array_push($products, ['name' => getData($p['assortment']['meta']['href'])['name'], 'quantity' => $p['quantity'], 'price' => $p['price'] / 100]);

    }

    $basket = Bitrix\Sale\Basket::create("s1");
    toLog('create-order',$products);
    foreach ($products as $p) {

        $arSelect = ['ID'];
        $arFilter = ['IBLOCK_ID' => 4, 'NAME' => $p['name'], 'ACTIVE' => 'Y'];

        $res = CIBlockElement::GetList([], $arFilter, false, [], $arSelect);

        if ($ob = $res->GetNextElement()) {

            $arFields = $ob->GetFields();
        }
        $bx_product = array(
            array('NAME' => $p['name'], 'PRICE' => $p['price'], 'CURRENCY' => $currency, 'QUANTITY' => $p['quantity'])
        );

        $item = $basket->createItem("catalog", $arFields['ID']);

//        $item->setFields($bx_product);
        $item->setField('QUANTITY', $p['quantity']);
        $item->setField('NAME', $p['name']);
        $item->setField('PRICE', $p['price']);
        $item->setField('CURRENCY', 'RUB');
    }

// создать заказ
    $order = Bitrix\Sale\Order::create("s1", $arUser['ID']);
    $order->setPersonTypeId(1);
    $order->setBasket($basket);
    $order->setField("COMMENTS", $order_comments);


    /* shipment */
    $shipmentCollection = $order->getShipmentCollection();
    $shipment = $shipmentCollection->createItem();
    $service = Bitrix\Sale\Delivery\Services\Manager::getById(3);// самовывоз
    $delivery = $service['NAME'];
    $shipment->setFields(array(
        'DELIVERY_ID' => $service['ID'],
        'DELIVERY_NAME' => $service['NAME'],
    ));
    $shipmentItemCollection = $shipment->getShipmentItemCollection();
    foreach ($basket as $item) {
        $shipmentItem = $shipmentItemCollection->createItem($item);
        $shipmentItem->setQuantity($item->getQuantity());
    }
    /* shipment-end */
    /* payment */
    $paymentCollection = $order->getPaymentCollection();
    $payment = $paymentCollection->createItem();
    $payment->setField('SUM', $order->getPrice());

    $paySystemService = \Bitrix\Sale\PaySystem\Manager::getObjectById(4);// безнал - 4, наличка - 1
    if ($type == 'retaildemand'){
        $paySystemService = \Bitrix\Sale\PaySystem\Manager::getObjectById(11);// терминал
    }

    $payment->setFields(array(
        'PAY_SYSTEM_ID' => $paySystemService->getField("PAY_SYSTEM_ID"),
        'PAY_SYSTEM_NAME' => $paySystemService->getField("NAME"),
    ));
    /* payment-end */

    $order->doFinalAction(true);
    $result = $order->save();

// переименовываем заказ в МС с учетом ID созданного заказа в битриксе
    $id = $result->getId();
    $param = ['name' => 'bx-' . $id];
    $PUT_TO_ORDER = true;
    $result_rename = getData($url, $PUT_TO_ORDER, $param);

    if ($type == 'retaildemand'){
        CSaleOrder::StatusOrder($id,'CT');
        CSaleOrder::Update($id, ['PAYED' => 'Y']);
    }

// ставим признак совершенного обмена, чтобы исключить дублирование в МС
    $order = Sale\Order::load($id);
    $order->setField('UPDATED_1C','Y');
    $order->save();

}


function getData($url, $PUT_TO_ORDER = false, $param = 0)
{
    $username = 'user@name';
    $password = 'password';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
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

function toLog($error, $param = '')
{

    $log = date('Y-m-d H:i:s') . ' ' . $error . ' ' . print_r($param, true);
    file_put_contents(__DIR__ . '/logs/log_order.txt', $log . PHP_EOL, FILE_APPEND);
}
