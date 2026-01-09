<?php
namespace Opencart\Catalog\Model\Extension\Epay\Payment;

class Epay extends \Opencart\System\Engine\Model {
	public function getMethods(array $address = [], float $total = 0.0): array {
		$this->load->language('extension/epay/payment/epay');

		if (!$this->config->get('payment_epay_status')) {
			return [];
		}

		if (!$this->config->get('payment_epay_api_key') || !$this->config->get('payment_epay_point_of_sale_id')) {
			return [];
		}

		if (!$this->config->get('config_checkout_payment_address')) {
			$status = true;
		} elseif (!$this->config->get('payment_epay_geo_zone_id')) {
			$status = true;
		} else {
			$this->load->model('localisation/geo_zone');

			$results = $this->model_localisation_geo_zone->getGeoZone(
				(int)$this->config->get('payment_epay_geo_zone_id'),
				(int)($address['country_id'] ?? 0),
				(int)($address['zone_id'] ?? 0)
			);

			$status = (bool)$results;
		}

		if (!$status) {
			return [];
		}

        $option_data['epay'] = [
            'code' => 'epay.epay',
            'name' => $this->language->get('text_title')
        ];

        return [
            'code' => 'epay',
            'name' => $this->language->get('text_title'),
            'title' => $this->language->get('text_title'),
            'option' => $option_data,
            'sort_order' => $this->config->get('payment_epay_sort_order')
        ];
	}

	public function createPaymentSession(array $order_info, array $urls): array {
		$this->load->language('extension/epay/payment/epay');

		$api_key = trim((string)$this->config->get('payment_epay_api_key'));
		$point_of_sale_id = trim((string)$this->config->get('payment_epay_point_of_sale_id'));

		if ($api_key === '' || $point_of_sale_id === '') {
			return ['error' => $this->language->get('error_configuration')];
		}

		if (!function_exists('curl_init')) {
			return ['error' => $this->language->get('error_api')];
		}

		$decimal_place = $this->currency->getDecimalPlace($order_info['currency_code']);
		$total = (float)$order_info['total'];
		$currency_value = (float)($order_info['currency_value'] ?? 0);

		if ($currency_value > 0) {
			$total *= $currency_value;
		} else {
			$total = $this->currency->convert($total, $this->config->get('config_currency'), $order_info['currency_code']);
		}

		$amount = (int)round($total * pow(10, $decimal_place));

		$text_on_statement = trim((string)$this->config->get('config_name'));
		if ($text_on_statement !== '') {
			$text_on_statement = substr($text_on_statement, 0, 39);
		}

		$payload = [
			'pointOfSaleId' => $point_of_sale_id,
			'reference' => (string)$order_info['order_id'],
			'amount' => $amount,
			'currency' => $order_info['currency_code'],
			'successUrl' => $urls['success'],
			'failureUrl' => $urls['failure'],
			'returnUrl' => $urls['return']
		];

		if (!empty($order_info['customer_id'])) {
			$payload['customerId'] = (string)$order_info['customer_id'];
		}

		$payload['notificationUrl'] = $urls['notification'];
		$payload['reportFailure'] = true;

		if ($text_on_statement !== '') {
			$payload['textOnStatement'] = $text_on_statement;
		}

		$customer = $this->buildCustomerPayload($order_info);
		if ($customer) {
			$payload['customer'] = $customer;
		}

		$payload['attributes'] = [
			'order_id' => (string)$order_info['order_id']
		];

		$headers = [
			'Content-Type: application/json',
			'Authorization: Bearer ' . $api_key,
			// 'Idempotency-Key: epay-' . (string)$order_info['order_id']
		];

		$ch = curl_init('https://payments.epay.eu/public/api/v1/cit');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

		$response = curl_exec($ch);
		$error = $response === false ? curl_error($ch) : '';
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($response === false) {
			return ['error' => $error ?: $this->language->get('error_api')];
		}

		$data = json_decode($response, true);

		if ($status < 200 || $status >= 300) {
			$message = '';
			if (is_array($data) && isset($data['message'])) {
				$message = $data['message'];
			}

			return ['error' => $message ?: $this->language->get('error_api')];
		}

		if (!is_array($data)) {
			return ['error' => $this->language->get('error_api')];
		}

		if (!empty($data['session']['id'])) {
			$this->storeSessionData((int)$order_info['order_id'], $data['session']);
		}

		return $data;
	}

	public function updateTransactionFromWebhook(int $order_id, array $transaction): void {
		$this->ensureTable();

		$transaction_id = $transaction['id'] ?? ($transaction['transactionId'] ?? '');
		$state = $transaction['state'] ?? '';
		$amount = (int)($transaction['amount'] ?? 0);
		$currency = $transaction['currency'] ?? '';
		$session_id = $transaction['sessionId'] ?? ($transaction['session_id'] ?? '');

		$query = $this->db->query("SELECT `epay_order_id`, `transaction_id`, `session_id` FROM `" . DB_PREFIX . "epay_order` WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1");

		if ($query->num_rows) {
			if ($transaction_id === '' && $query->row['transaction_id'] !== '') {
				$transaction_id = $query->row['transaction_id'];
			}

			if ($session_id === '' && $query->row['session_id'] !== '') {
				$session_id = $query->row['session_id'];
			}

			$this->db->query("UPDATE `" . DB_PREFIX . "epay_order` SET `transaction_id` = '" . $this->db->escape($transaction_id) . "', `session_id` = '" . $this->db->escape($session_id) . "', `state` = '" . $this->db->escape($state) . "', `amount` = '" . (int)$amount . "', `currency` = '" . $this->db->escape($currency) . "', `date_modified` = NOW() WHERE `order_id` = '" . (int)$order_id . "'");
		} else {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "epay_order` SET `order_id` = '" . (int)$order_id . "', `transaction_id` = '" . $this->db->escape($transaction_id) . "', `session_id` = '" . $this->db->escape($session_id) . "', `state` = '" . $this->db->escape($state) . "', `amount` = '" . (int)$amount . "', `currency` = '" . $this->db->escape($currency) . "', `captured` = 0, `refunded` = 0, `voided` = 0, `date_added` = NOW(), `date_modified` = NOW()");
		}
	}

	public function getOrderIdBySessionId(string $session_id): int {
		$this->ensureTable();

		if ($session_id === '') {
			return 0;
		}

		$query = $this->db->query("SELECT `order_id` FROM `" . DB_PREFIX . "epay_order` WHERE `session_id` = '" . $this->db->escape($session_id) . "' LIMIT 1");

		return $query->num_rows ? (int)$query->row['order_id'] : 0;
	}

	private function storeSessionData(int $order_id, array $session): void {
		$this->ensureTable();

		$session_id = (string)($session['id'] ?? '');
		$amount = (int)($session['amount'] ?? 0);
		$currency = (string)($session['currency'] ?? '');
		$state = (string)($session['state'] ?? '');

		if ($session_id === '') {
			return;
		}

		$query = $this->db->query("SELECT `epay_order_id` FROM `" . DB_PREFIX . "epay_order` WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1");

		if ($query->num_rows) {
			$this->db->query("UPDATE `" . DB_PREFIX . "epay_order` SET `session_id` = '" . $this->db->escape($session_id) . "', `state` = '" . $this->db->escape($state) . "', `amount` = '" . (int)$amount . "', `currency` = '" . $this->db->escape($currency) . "', `date_modified` = NOW() WHERE `order_id` = '" . (int)$order_id . "'");
		} else {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "epay_order` SET `order_id` = '" . (int)$order_id . "', `session_id` = '" . $this->db->escape($session_id) . "', `state` = '" . $this->db->escape($state) . "', `amount` = '" . (int)$amount . "', `currency` = '" . $this->db->escape($currency) . "', `captured` = 0, `refunded` = 0, `voided` = 0, `date_added` = NOW(), `date_modified` = NOW()");
		}
	}

	private function ensureTable(): void {
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "epay_order` (
			`epay_order_id` INT NOT NULL AUTO_INCREMENT,
			`order_id` INT NOT NULL,
			`transaction_id` VARCHAR(64) NOT NULL DEFAULT '',
			`session_id` VARCHAR(64) NOT NULL DEFAULT '',
			`state` VARCHAR(32) NOT NULL DEFAULT '',
			`amount` INT NOT NULL DEFAULT 0,
			`currency` CHAR(3) NOT NULL DEFAULT '',
			`captured` INT NOT NULL DEFAULT 0,
			`refunded` INT NOT NULL DEFAULT 0,
			`voided` INT NOT NULL DEFAULT 0,
			`date_added` DATETIME NOT NULL,
			`date_modified` DATETIME NOT NULL,
			PRIMARY KEY (`epay_order_id`),
			UNIQUE KEY `order_id` (`order_id`)
		) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

		$session_column = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "epay_order` LIKE 'session_id'");
		if (!$session_column->num_rows) {
			$this->db->query("ALTER TABLE `" . DB_PREFIX . "epay_order` ADD `session_id` VARCHAR(64) NOT NULL DEFAULT '' AFTER `transaction_id`");
		}
	}

	private function buildCustomerPayload(array $order_info): array {
		$customer = [
			'firstName' => $order_info['firstname'] ?? '',
			'lastName' => $order_info['lastname'] ?? '',
			'email' => $order_info['email'] ?? ''
		];

		$phone = $order_info['telephone'] ?? '';
		if ($phone !== '' && preg_match('/^\\+\\d{1,3} \\d+$/', $phone)) {
			$customer['phoneNumber'] = $phone;
		}

		$billing = $this->buildAddressPayload(
			$order_info['payment_address_1'] ?? '',
			$order_info['payment_address_2'] ?? '',
			$order_info['payment_city'] ?? '',
			$order_info['payment_postcode'] ?? '',
			$order_info['payment_iso_code_2'] ?? ''
		);

		if ($billing) {
			$customer['billingAddress'] = $billing;
		}

		$shipping = $this->buildAddressPayload(
			$order_info['shipping_address_1'] ?? '',
			$order_info['shipping_address_2'] ?? '',
			$order_info['shipping_city'] ?? '',
			$order_info['shipping_postcode'] ?? '',
			$order_info['shipping_iso_code_2'] ?? ''
		);

		if ($shipping) {
			$customer['shippingAddress'] = $shipping;
		}

		$customer = array_filter($customer, static function ($value): bool {
			return $value !== '' && $value !== null;
		});

		return $customer;
	}

	private function buildAddressPayload(string $line1, string $line2, string $city, string $postcode, string $country_code): array {
		$address = [
			'line1' => $line1,
			'line2' => $line2,
			'city' => $city,
			'postalCode' => $postcode,
			'countryCode' => $country_code
		];

		$address = array_filter($address, static function ($value): bool {
			return $value !== '' && $value !== null;
		});

		return $address;
	}
}
