<?php

namespace Commerce\Payments;

class AlfabankSBPPayment extends AlfabankPayment
{
    public function getPaymentLink()
    {
        return false;
    }

    public function getPaymentMarkup()
    {
        if (empty($this->getSetting('token')) && (empty($this->getSetting('login')) || empty($this->getSetting('password')))) {
            return '<span class="error" style="color: red;">' . $this->lang['sberbank.error_empty_token_and_login_password'] . '</span>';
        }
        $processor = $this->modx->commerce->loadProcessor();
        $order     = $processor->getOrder();
        $order_id  = $order['id'];
        $currency  = ci()->currency->getCurrency($order['currency']);

        $amount = ci()->currency->convert($order['amount'], $currency['code'], 'RUB');

        try {
            $payment = $this->createPayment($order['id'], $amount);
        } catch (\Exception $e) {
            if ($this->debug) {
                $this->modx->logEvent(0, 3,
                    'Failed to create payment: ' . $e->getMessage() . '<br>Data: <pre>' . htmlentities(print_r($order,
                        true)) . '</pre>', 'Commerce Alfabank Payment');
            }
            
            return false;
        }

        $customer = [];

        if (!empty($order['email']) && filter_var($order['email'], FILTER_VALIDATE_EMAIL)) {
            $customer['email'] = $order['email'];
        }

        if (!empty($order['phone'])) {
            $phone = preg_replace('/[^0-9]+/', '', $order['phone']);
            $phone = preg_replace('/^8/', '7', $phone);

            if (preg_match('/^7\d{10}$/', $phone)) {
                $customer['phone'] = $phone;
            }
        }

        $params = [
            'CMS' => 'Evolution CMS ' . $this->modx->getConfig('settings_version'),
        ];

        foreach (['email', 'phone'] as $field) {
            if (isset($customer[$field])) {
                $params[$field] = $customer[$field];
            }
        }

        $data = [
            'orderNumber' => $order_id . '-' . time(),
            'amount'      => (int) round($payment['amount'] * 100),
            'currency'    => 810,
            'language'    => 'ru',
            'jsonParams'  => json_encode($params),
            'returnUrl'          => MODX_SITE_URL . 'commerce/alfabanksbp/payment-success?paymentHash=' . $payment['hash'],
            'dynamicCallbackUrl' => MODX_SITE_URL . 'commerce/alfabanksbp/payment-process/?' . http_build_query([
                    'paymentId'   => $payment['id'],
                    'paymentHash' => $payment['hash'],
                ]),
            'description' => ci()->tpl->parseChunk($this->lang['payments.payment_description'], [
                'order_id'  => $order_id,
                'site_name' => $this->modx->getConfig('site_name'),
            ]),
        ];

        if (!empty($customer)) {
            $cart = $processor->getCart();
            $items = $this->prepareItems($cart, $currency['code'], 'RUB');

            $isPartialPayment = abs($amount - $payment['amount']) > 0.01;

            if ($isPartialPayment) {
                $items = $this->decreaseItemsAmount($items, $amount, $payment['amount']);
            }

            $products = [];

            foreach ($items as $i => $item) {
                $products[] = [
                    'positionId'  => $i + 1,
                    'name'        => $item['name'],
                    'quantity'    => [
                        'value'   => $item['count'],
                        'measure' => $item['product'] ? isset($meta['measurements']) ? $meta['measurements'] : $this->lang['measures.units'] : '-',
                    ],
                    'itemAmount'  => (int) round($item['total'] * 100),
                    'itemPrice'   => (int) round($item['price'] * 100),
                    'itemCode'    => $item['id'],
                ];
            }

            $data['orderBundle'] = json_encode([
                'orderCreationDate' => date('c'),
                'customerDetails'   => $customer,
                'cartItems' => [
                    'items' => $products,
                ],
            ]);
        } else if ($this->debug) {
            $this->modx->logEvent(0, 2, 'User credentials not found in order: <pre>' . htmlentities(print_r($order, true)) . '</pre>', 'Commerce Alfabank Payment Debug');
        }

        try {
            $result = $this->request('rest/register.do', $data);

            if (empty($result['formUrl'])) {
                throw new \Exception('Request failed!');
            }
        } catch (\Exception $e) {
            if ($this->debug) {
                $this->modx->logEvent(0, 3, 'Order id is not received: ' . $e->getMessage(),
                    'Commerce Alfabank Payment');
            }
            
            return false;
        }
        $orderId = $result['orderId'];
        $size = $this->getSetting('qrSize', 500);
        $data = [
            'mdOrder'  => $orderId,
            'qrHeight' => $size,
            'qrWidth'  => $size,
            'qrFormat' => 'image'
        ];
        try {
            $result = $this->request('rest/sbp/c2b/qr/dynamic/get.do', $data);

            if (empty($result['renderedQr'])) {
                throw new \Exception('Request failed!');
            }
        } catch (\Exception $e) {
            if ($this->debug) {
                $this->modx->logEvent(0, 3, 'QR is not received: ' . $e->getMessage(), 'Commerce Alfabank Payment');
            }
            
            return false;
        }

        return 'data:image/png;base64, ' . $result['renderedQr'];
    }
}
