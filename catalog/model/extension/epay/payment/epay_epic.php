<?php
namespace Opencart\Catalog\Model\Extension\Epay\Payment;

class EpayEpic extends \Opencart\System\Engine\Model {

    public function getMethods(array $address = []): array {
        $this->load->language('extension/epay/payment/epay_epic');

        return [
            'epay_epic' => [
                'code'       => 'epay_epic',
                'title'      => $this->language->get('text_title'),
                'sort_order' => 0
            ]
        ];
    }
}
