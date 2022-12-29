# Платежный плагин Альфабанка для Commerce

Перед началом работы необходимо обратиться в поддержку и запросить включение уведомлений (событие - "Успешное списание"), а также получить ключ для проверки запросов от банка. Поддержка может уточнить URL для отправки уведомлений - плагин отправляет его в запросе, поэтому можете сообщить поддержке просто URL сайта.

При оплате QR-кодом, код показывается пользователю после оформления заказа. Если на сайте оплата происходит после подтверждения заказа менеджером, необходим дополнительный плагин. Пример плагина, который отправляет qr-код в письме при изменении статуса заказа на 7 ("ожидание"):
```
if ($params['status_id'] != 7 || !isset($params['order']['fields']['payment_method']) || ($params['order']['fields']['payment_method'] !== 'alfabanksbp')) return;
$processor = ci()->commerce->loadProcessor();

try {
    $payment = ci()->commerce->getPayment('alfabanksbp');
} catch (\Exception $e) {
    $params['prevent'] = true;

    return;
}
$paymentProcessor = $payment['processor'];
if ($qr = $paymentProcessor->getPaymentMarkup()) {
    $params['data']['qr_code'] = $qr;
    $params['body'] = ci()->commerce->getUserLanguageTemplate('order_ready_to_pay_sbp');
} else {
    $params['prevent'] = true;

    return;
}

$params['subject'] = '@CODE:заказ #[+order.id+] готов к оплате';
```

Файл order_ready_to_pay_sbp.tpl (размещается в папке с шаблонами commerce, подпапка с названием языка):
```
<p>Заказ #[+order.id+] на сайте [(site_url)] подготовлен к оплате!</p>

<p>Размер оплаты: [[PriceFormat? &price=`[+order.amount+]` &convert=`0`]]</p>

<p>Для оплаты заказа отсканируйте QR-код:<br> <img src="[+qr_code+]"/></p>
```
