<?php

namespace Commerce\Payments;

class AlfabankPayment extends Payment
{
    protected $debug = false;

    public function __construct($modx, array $params = [])
    {
        parent::__construct($modx, $params);
        $this->lang = $modx->commerce->getUserLanguage('alfabank');
        $this->debug = $this->getSetting('debug') == 1;
    }

    public function getMarkup()
    {
        if (empty($this->getSetting('token')) && (empty($this->getSetting('login')) || empty($this->getSetting('password')))) {
            return '<span class="error" style="color: red;">' . $this->lang['alfabank.error_empty_token_and_login_password'] . '</span>';
        }
    }

    public function getPaymentLink()
    {
        $processor = $this->modx->commerce->loadProcessor();
        $order = $processor->getOrder();
        $order_id = $order['id'];
        $currency = ci()->currency->getCurrency($order['currency']);

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
            'orderNumber'        => $order_id . '-' . time(),
            'amount'             => (int) round($payment['amount'] * 100),
            'currency'           => 810,
            'language'           => 'ru',
            'jsonParams'         => json_encode($params),
            'returnUrl'          => MODX_SITE_URL . 'commerce/alfabank/payment-success?paymentHash=' . $payment['hash'],
            'dynamicCallbackUrl' => MODX_SITE_URL . 'commerce/alfabank/payment-process/?' . http_build_query([
                    'paymentId'   => $payment['id'],
                    'paymentHash' => $payment['hash'],
                ]),
            'description'        => ci()->tpl->parseChunk($this->lang['payments.payment_description'], [
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
                    'positionId' => $i + 1,
                    'name'       => $item['name'],
                    'quantity'   => [
                        'value'   => $item['count'],
                        'measure' => $item['product'] ? isset($meta['measurements']) ? $meta['measurements'] : $this->lang['measures.units'] : '-',
                    ],
                    'itemAmount' => (int) round($item['total'] * 100),
                    'itemPrice'  => (int) round($item['price'] * 100),
                    'itemCode'   => $item['id'],
                ];
            }

            $data['orderBundle'] = json_encode([
                'orderCreationDate' => date('c'),
                'customerDetails'   => $customer,
                'cartItems'         => [
                    'items' => $products,
                ],
            ]);
        } else {
            if ($this->debug) {
                $this->modx->logEvent(0, 2,
                    'User credentials not found in order: <pre>' . htmlentities(print_r($order, true)) . '</pre>',
                    'Commerce Alfabank Payment Debug');
            }
        }

        try {
            $result = $this->request('rest/register.do', $data);

            if (empty($result['formUrl'])) {
                throw new \Exception('Request failed!');
            }
        } catch (\Exception $e) {
            if ($this->debug) {
                $this->modx->logEvent(0, 3, 'Link is not received: ' . $e->getMessage(), 'Commerce Alfabank Payment');
            }
            return false;
        }

        return $result['formUrl'];
    }

    public function handleCallback()
    {
        $params = ['mdOrder', 'orderNumber', 'checksum', 'operation', 'status'];
        foreach ($params as $param) {
            if(empty($_REQUEST[$param]) || !is_scalar($_REQUEST[$param])) {
                return false;
            }
        }
        if ($this->debug) {
            $this->modx->logEvent(0, 3, 'Callback start:<pre>' . print_r($_REQUEST, true) . '</pre>', 'Commerce Alfabank Payment Callback');
        }

        sort($params);
        $paramsString = '';
        foreach ($params as $param) {
            if ($param == 'checksum') continue;
            $paramsString .= $param . ';' . $_REQUEST[$param] . ';';
        }
        $checksum = $_REQUEST['checksum'];
        $hash = strtoupper(hash_hmac('sha256', $paramsString, $this->getSetting('secret_key')));

        if ($checksum !== $hash) {
            if ($this->debug) {
                $this->modx->logEvent(0, 3, 'Order status request failed', 'Commerce Alfabank Payment Callback');
            }

            return false;
        }

        if ($_REQUEST['operation'] == 'deposited' && $_REQUEST['status'] == 1) {
            try {
                $processor = $this->modx->commerce->loadProcessor();
                $payment = $processor->loadPayment($_REQUEST['paymentId']);
                return $processor->processPayment($payment['id'], $payment['amount']);
            } catch (\Exception $e) {
                if ($this->debug) {
                    $this->modx->logEvent(0, 3, 'Payment process failed: ' . $e->getMessage(),  'Commerce Alfabank Payment Callback');
                }

                return false;
            }
        }

        return false;
    }

    protected function getUrl($method)
    {
        $url = $this->getSetting('test') == 1 ? 'https://web.rbsuat.com/ab/' : 'https://pay.alfabank.ru/payment/';
        return $url . $method;
    }

    protected function request($method, $data)
    {
        $data['token'] = $this->getSetting('token');

        if (empty($data['token'])) {
            $data['userName'] = $this->getSetting('login');
            $data['password'] = $this->getSetting('password');
        }

        $url = $this->getUrl($method);
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_VERBOSE        => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Content-type: application/x-www-form-urlencoded',
                'Cache-Control: no-cache',
                'charset="utf-8"',
            ],
        ]);

        $result = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($this->debug) {
            $this->modx->logEvent(0, 1, 'URL: <pre>' . $url . '</pre><br>Data: <pre>' . htmlentities(print_r($data,
                    true)) . '</pre><br>Response: <pre>' . $code . "\n" . htmlentities(print_r($result,
                    true)) . '</pre><br>', 'Commerce Alfabank Payment');
        }

        if ($code != 200) {
            if ($this->debug) {
                $this->modx->logEvent(0, 3, 'Server is not responding', 'Commerce Alfabank Payment');
            }

            return false;
        }

        $result = json_decode($result, true);

        if (!empty($result['errorCode']) && isset($result['errorMessage'])) {
            if ($this->debug) {
                $this->modx->logEvent(0, 3, 'Server return error: ' . $result['errorMessage'], 'Commerce Alfabank Payment');
            }

            return false;
        }

        return $result;
    }

    public function getRequestPaymentHash()
    {
        if (isset($_REQUEST['paymentHash']) && is_scalar($_REQUEST['paymentHash'])) {
            return $_REQUEST['paymentHash'];
        }

        return null;
    }

}
