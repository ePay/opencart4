<?php
namespace Opencart\Admin\Model\Extension\Epay\Payment;

class Epay extends \Opencart\System\Engine\Model {
	public function install(): void {
		$this->ensureTable();
	}

	public function uninstall(): void {
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "epay_order`");
	}

	public function getOrder(int $order_id): array {
		$this->ensureTable();

		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "epay_order` WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1");

		return $query->num_rows ? $query->row : [];
	}

	public function getTransactionId(int $order_id): string {
		$order = $this->getOrder($order_id);

		return $order['transaction_id'] ?? '';
	}

	public function addCapture(int $order_id, int $amount): void {
		$this->ensureTable();

		$this->db->query("UPDATE `" . DB_PREFIX . "epay_order` SET `captured` = `captured` + '" . (int)$amount . "', `date_modified` = NOW() WHERE `order_id` = '" . (int)$order_id . "'");
	}

	public function addRefund(int $order_id, int $amount): void {
		$this->ensureTable();

		$this->db->query("UPDATE `" . DB_PREFIX . "epay_order` SET `refunded` = `refunded` + '" . (int)$amount . "', `date_modified` = NOW() WHERE `order_id` = '" . (int)$order_id . "'");
	}

	public function addVoid(int $order_id, int $amount): void {
		$this->ensureTable();

		if ($amount < 1) {
			$this->db->query("UPDATE `" . DB_PREFIX . "epay_order` SET `date_modified` = NOW() WHERE `order_id` = '" . (int)$order_id . "'");
		} else {
			$this->db->query("UPDATE `" . DB_PREFIX . "epay_order` SET `voided` = `voided` + '" . (int)$amount . "', `date_modified` = NOW() WHERE `order_id` = '" . (int)$order_id . "'");
		}
	}

	public function captureTransaction(string $transaction_id, int $amount, bool $void_remaining, string $idempotency_key): array {
		return $this->request(
			'/public/api/v1/transactions/' . rawurlencode($transaction_id) . '/capture',
			[
				'amount' => $amount,
				'voidRemaining' => $void_remaining
			],
			$idempotency_key
		);
	}

	public function refundTransaction(string $transaction_id, int $amount, string $idempotency_key): array {
		return $this->request(
			'/public/api/v1/transactions/' . rawurlencode($transaction_id) . '/refund',
			[
				'amount' => $amount
			],
			$idempotency_key
		);
	}

	public function voidTransaction(string $transaction_id, int $amount, string $idempotency_key): array {
		return $this->request(
			'/public/api/v1/transactions/' . rawurlencode($transaction_id) . '/void',
			[
				'amount' => $amount
			],
			$idempotency_key
		);
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

	private function request(string $path, array $payload, string $idempotency_key): array {
		$api_key = trim((string)$this->config->get('payment_epay_api_key'));

		if ($api_key === '') {
			return ['success' => false, 'message' => 'Missing API key'];
		}

		if (!function_exists('curl_init')) {
			return ['success' => false, 'message' => 'cURL is not available'];
		}

		$headers = [
			'Content-Type: application/json',
			'Authorization: Bearer ' . $api_key,
			'Idempotency-Key: ' . $idempotency_key
		];

		$ch = curl_init('https://payments.epay.eu' . $path);
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
			return ['success' => false, 'message' => $error];
		}

		$data = json_decode($response, true);

		if ($status < 200 || $status >= 300) {
			$message = '';
			if (is_array($data)) {
				if (!empty($data['errorCode']['message'])) {
					$message = $data['errorCode']['message'];
				} elseif (!empty($data['message'])) {
					$message = $data['message'];
				}
			}

			return ['success' => false, 'message' => $message ?: 'API error'];
		}

		if (!is_array($data)) {
			return ['success' => false, 'message' => 'Invalid API response'];
		}

		$success = $data['success'] ?? null;

		if ($success !== true) {
			$message = '';
			if (!empty($data['errorCode']['message'])) {
				$message = $data['errorCode']['message'];
			} elseif (!empty($data['operations'][0]['errorCode']['message'])) {
				$message = $data['operations'][0]['errorCode']['message'];
			}

			return ['success' => false, 'message' => $message ?: 'Operation failed'];
		}

		return ['success' => true, 'data' => $data];
	}
}
