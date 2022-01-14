//<?php
/**
 * Payment AlfabankSBP
 *
 * Alfabank payments processing
 *
 * @category    plugin
 * @version     0.1.0
 * @author      Pathologic
 * @internal    @events OnRegisterPayments,OnManagerBeforeOrderRender
 * @internal    @properties &title=Название;text; &token=Токен;text; &login=Логин;text; &password=Пароль;text; &debug=Отладка запросов;list;Нет==0||Да==1;1 &test=Тестовый доступ;list;Нет==0||Да==1;1
 * @internal    @disabled 1
 * @internal    @modx_category Commerce
 * @internal    @installset base
*/

if (empty($modx->commerce) && !defined('COMMERCE_INITIALIZED')) {
    return;
}

$isSelectedPayment = !empty($order['fields']['payment_method']) && $order['fields']['payment_method'] == 'alfabanksbp';

switch ($modx->event->name) {
    case 'OnRegisterPayments': {
        $class = new \Commerce\Payments\AlfabankSBPPayment($modx, $params);

        if (empty($params['title'])) {
            $lang = $modx->commerce->getUserLanguage('alfabank');
            $params['title'] = $lang['alfabanksbp.caption'];
        }

        $modx->commerce->registerPayment('alfabanksbp', $params['title'], $class);
        break;
    }

    case 'OnManagerBeforeOrderRender': {
        if (isset($params['groups']['payment_delivery']) && $isSelectedPayment) {
            $lang = $modx->commerce->getUserLanguage('alfabank');

            $params['groups']['payment_delivery']['fields']['payment_link'] = [
                'title'   => $lang['alfabank.link_caption'],
                'content' => function($data) use ($modx) {
                    return $modx->commerce->loadProcessor()->populateOrderPaymentLink('@CODE:<a href="[+link+]" target="_blank">[+link+]</a>');
                },
                'sort' => 50,
            ];
        }

        break;
    }
}
