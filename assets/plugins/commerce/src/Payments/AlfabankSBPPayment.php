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
        if (!$data = $this->getQRData()) {
            return false;
        } else {
            $tpl = ci()->commerce->getUserLanguageTemplate('alfabankspb_payment_markup');

            return ci()->tpl->parseChunk($tpl, $data);
        }
    }

    public function getQRData()
    {
        try {
            $result = $this->registerOrder();

            if ($result === false || empty($result['formUrl'])) {
                throw new \Exception('Request failed!');
            }
        } catch (\Exception $e) {
            $this->modx->logEvent(0, 3, 'Order is not registered: ' . $e->getMessage(), 'Commerce Alfabank Payment');

            return false;
        }
        $orderId = $result['orderId'];
        $url = $result['formUrl'];
        try {
            $result = $this->getQRImage($orderId);

            if ($result === false || empty($result['renderedQr']) || empty($result['payload'])) {
                throw new \Exception('Request failed!');
            }
        } catch (\Exception $e) {
            $this->modx->logEvent(0, 3, 'QR image is not received: ' . $e->getMessage(), 'Commerce Alfabank Payment');

            return false;
        }

        return [
            'url'    => $url,
            'qr'     => 'data:image/png;base64, ' . $result['renderedQr'],
        ];
    }

    public function getQRImage($orderId)
    {
        $size = $this->getSetting('qrSize', 500);
        $data = [
            'mdOrder'  => $orderId,
            'qrHeight' => $size,
            'qrWidth'  => $size,
            'qrFormat' => 'image'
        ];

        return $this->request('rest/sbp/c2b/qr/dynamic/get.do', $data);
    }
}