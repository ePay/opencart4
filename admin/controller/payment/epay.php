<?php
namespace Opencart\Admin\Controller\Extension\Epay\Payment;

class Epay extends \Opencart\System\Engine\Controller {
	public function index(): void {
		$this->load->language('extension/epay/payment/epay');

		$this->document->setTitle($this->language->get('heading_title'));

		$data['breadcrumbs'] = [];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment')
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/epay/payment/epay', 'user_token=' . $this->session->data['user_token'])
		];

		$data['save'] = $this->url->link('extension/epay/payment/epay|save', 'user_token=' . $this->session->data['user_token']);
		$data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment');

		$data['payment_epay_api_key'] = $this->config->get('payment_epay_api_key');
		$data['payment_epay_point_of_sale_id'] = $this->config->get('payment_epay_point_of_sale_id');
		$data['payment_epay_webhook_auth'] = $this->config->get('payment_epay_webhook_auth');

		$data['payment_epay_order_status_id'] = (int)$this->config->get('payment_epay_order_status_id');
		$data['payment_epay_failed_status_id'] = (int)$this->config->get('payment_epay_failed_status_id');

		$this->load->model('localisation/order_status');
		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

		$data['payment_epay_geo_zone_id'] = (int)$this->config->get('payment_epay_geo_zone_id');

		$this->load->model('localisation/geo_zone');
		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

		$data['payment_epay_status'] = $this->config->get('payment_epay_status');
		$data['payment_epay_sort_order'] = $this->config->get('payment_epay_sort_order');

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/epay/payment/epay', $data));
	}

	public function save(): void {
		$this->load->language('extension/epay/payment/epay');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/epay/payment/epay')) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json && !empty($this->request->post['payment_epay_status'])) {
			if (empty($this->request->post['payment_epay_api_key'])) {
				$json['error'] = $this->language->get('error_api_key');
			} elseif (empty($this->request->post['payment_epay_point_of_sale_id'])) {
				$json['error'] = $this->language->get('error_point_of_sale_id');
			} elseif (empty($this->request->post['payment_epay_webhook_auth'])) {
				$json['error'] = $this->language->get('error_webhook_auth');
			}
		}

		if (!$json) {
			$this->load->model('setting/setting');
			$this->model_setting_setting->editSetting('payment_epay', $this->request->post);

			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function install(): void {
		$this->load->model('extension/epay/payment/epay');
		$this->model_extension_epay_payment_epay->install();

		$this->load->model('user/user_group');
		$this->model_user_user_group->addPermission($this->user->getGroupId(), 'access', 'extension/epay/payment/epay');
		$this->model_user_user_group->addPermission($this->user->getGroupId(), 'modify', 'extension/epay/payment/epay');
	}

	public function uninstall(): void {
		$this->load->model('extension/epay/payment/epay');
		$this->model_extension_epay_payment_epay->uninstall();
	}

	public function order(): string {
		$this->load->language('extension/epay/payment/epay');

		$order_id = (int)($this->request->get['order_id'] ?? 0);

		if (!$order_id) {
			return '';
		}

		$this->load->model('sale/order');
		$order_info = $this->model_sale_order->getOrder($order_id);

		if (!$order_info) {
			return '';
		}

		$this->load->model('extension/epay/payment/epay');
		$epay_order = $this->model_extension_epay_payment_epay->getOrder($order_id);

		$currency_code = $order_info['currency_code'];
		$currency_value = $order_info['currency_value'];
		$decimal_place = $this->currency->getDecimalPlace($currency_code);

		$data['transaction_id'] = $epay_order['transaction_id'] ?? '';
		$data['transaction_state'] = $epay_order['state'] ?? '';
		$data['transaction_amount'] = $this->formatMinorAmount($epay_order['amount'] ?? 0, $currency_code, $currency_value, $decimal_place);
		$data['transaction_captured'] = $this->formatMinorAmount($epay_order['captured'] ?? 0, $currency_code, $currency_value, $decimal_place);
		$data['transaction_refunded'] = $this->formatMinorAmount($epay_order['refunded'] ?? 0, $currency_code, $currency_value, $decimal_place);
		$data['transaction_voided'] = $this->formatMinorAmount($epay_order['voided'] ?? 0, $currency_code, $currency_value, $decimal_place);
		$data['transaction_currency'] = $epay_order['currency'] ?? $currency_code;
		$data['transaction_updated'] = $epay_order['date_modified'] ?? '';

		$order_total = $this->currency->format($order_info['total'], $currency_code, $currency_value, false);

		$data['order_id'] = $order_id;
		$data['capture_amount'] = $order_total;
		$data['refund_amount'] = $order_total;
		$data['void_amount'] = $order_total;

		$data['user_token'] = $this->session->data['user_token'];
		$data['capture_url'] = $this->url->link('extension/epay/payment/epay|capture', 'user_token=' . $this->session->data['user_token']);
		$data['refund_url'] = $this->url->link('extension/epay/payment/epay|refund', 'user_token=' . $this->session->data['user_token']);
		$data['void_url'] = $this->url->link('extension/epay/payment/epay|void', 'user_token=' . $this->session->data['user_token']);

		return $this->load->view('extension/epay/payment/epay_order', $data);
	}

	public function capture(): void {
		$this->load->language('extension/epay/payment/epay');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/epay/payment/epay')) {
			$json['error'] = $this->language->get('error_permission');
		}

		$order_id = (int)($this->request->post['order_id'] ?? 0);
		$amount_input = (string)($this->request->post['amount'] ?? '');
		$void_remaining = !empty($this->request->post['void_remaining']);

		if (!$json && (!$order_id || $amount_input === '')) {
			$json['error'] = $this->language->get('error_amount');
		}

		if (!$json) {
			$this->load->model('sale/order');
			$order_info = $this->model_sale_order->getOrder($order_id);

			if (!$order_info) {
				$json['error'] = $this->language->get('error_order');
			}
		}

			if (!$json) {
				$this->load->model('extension/epay/payment/epay');
				$transaction_id = $this->model_extension_epay_payment_epay->getTransactionId($order_id);
				$epay_order = $this->model_extension_epay_payment_epay->getOrder($order_id);
				$gateway_amount = (int)($epay_order['amount'] ?? 0);
				$captured = (int)($epay_order['captured'] ?? 0);
				$voided = (int)($epay_order['voided'] ?? 0);
				$remaining = max(0, $gateway_amount - $captured - $voided);

				if ($transaction_id === '') {
					$json['error'] = $this->language->get('error_transaction_missing');
				} else {
					$amount = $this->toMinorAmount($amount_input, $order_info['currency_code']);

					if ($amount < 1) {
						$json['error'] = $this->language->get('error_amount');
					} elseif ($gateway_amount > 0 && $amount > $remaining) {
						$json['error'] = $this->language->get('error_capture_exceeds');
					} else {
						$idempotency_key = 'epay-epic-' . $order_id . '-capture-' . $amount;
						$result = $this->model_extension_epay_payment_epay->captureTransaction($transaction_id, $amount, $void_remaining, $idempotency_key);

						if (!$result['success']) {
							$json['error'] = $result['message'] ?: $this->language->get('error_api');
						} else {
							$this->model_extension_epay_payment_epay->addCapture($order_id, $amount);
							$this->addOrderHistory($order_id, $order_info['order_status_id'], sprintf($this->language->get('text_capture_success'), $transaction_id));
							$json['success'] = $this->language->get('text_capture_success_title');
						}
					}
				}
			}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function refund(): void {
		$this->load->language('extension/epay/payment/epay');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/epay/payment/epay')) {
			$json['error'] = $this->language->get('error_permission');
		}

		$order_id = (int)($this->request->post['order_id'] ?? 0);
		$amount_input = (string)($this->request->post['amount'] ?? '');

		if (!$json && (!$order_id || $amount_input === '')) {
			$json['error'] = $this->language->get('error_amount');
		}

		if (!$json) {
			$this->load->model('sale/order');
			$order_info = $this->model_sale_order->getOrder($order_id);

			if (!$order_info) {
				$json['error'] = $this->language->get('error_order');
			}
		}

		if (!$json) {
			$this->load->model('extension/epay/payment/epay');
			$transaction_id = $this->model_extension_epay_payment_epay->getTransactionId($order_id);

			if ($transaction_id === '') {
				$json['error'] = $this->language->get('error_transaction_missing');
			} else {
				$amount = $this->toMinorAmount($amount_input, $order_info['currency_code']);

				if ($amount < 1) {
					$json['error'] = $this->language->get('error_amount');
				} else {
					$idempotency_key = 'epay-epic-' . $order_id . '-refund-' . $amount;
					$result = $this->model_extension_epay_payment_epay->refundTransaction($transaction_id, $amount, $idempotency_key);

						if (!$result['success']) {
							$json['error'] = $result['message'] ?: $this->language->get('error_api');
						} else {
							$this->model_extension_epay_payment_epay->addRefund($order_id, $amount);
							$this->addOrderHistory($order_id, $order_info['order_status_id'], sprintf($this->language->get('text_refund_success'), $transaction_id));
							$json['success'] = $this->language->get('text_refund_success_title');
						}
					}
				}
			}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function void(): void {
		$this->load->language('extension/epay/payment/epay');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/epay/payment/epay')) {
			$json['error'] = $this->language->get('error_permission');
		}

		$order_id = (int)($this->request->post['order_id'] ?? 0);

		if (!$json && !$order_id) {
			$json['error'] = $this->language->get('error_amount');
		}

		if (!$json) {
			$this->load->model('sale/order');
			$order_info = $this->model_sale_order->getOrder($order_id);

			if (!$order_info) {
				$json['error'] = $this->language->get('error_order');
			}
		}

		if (!$json) {
			$this->load->model('extension/epay/payment/epay');
			$transaction_id = $this->model_extension_epay_payment_epay->getTransactionId($order_id);

			if ($transaction_id === '') {
				$json['error'] = $this->language->get('error_transaction_missing');
			} else {
				$amount = -1; // always void remaining
				$idempotency_key = 'epay-epic-' . $order_id . '-void-remaining';
				$result = $this->model_extension_epay_payment_epay->voidTransaction($transaction_id, $amount, $idempotency_key);

				if (!$result['success']) {
					$json['error'] = $result['message'] ?: $this->language->get('error_api');
				} else {
					$this->model_extension_epay_payment_epay->addVoid($order_id, $amount);
					$this->addOrderHistory($order_id, $order_info['order_status_id'], sprintf($this->language->get('text_void_success'), $transaction_id));
					$json['success'] = $this->language->get('text_void_success_title');
				}
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	private function toMinorAmount(string $amount_input, string $currency_code): int {
		$amount_input = str_replace(',', '.', $amount_input);
		$amount = (float)$amount_input;
		$decimal_place = $this->currency->getDecimalPlace($currency_code);

		return (int)round($amount * pow(10, $decimal_place));
	}

	private function formatMinorAmount(int $amount, string $currency_code, float $currency_value, int $decimal_place): string {
		$major = $amount / pow(10, $decimal_place);

		return (string)$this->currency->format($major, $currency_code, 1);
	}

	private function addOrderHistory(int $order_id, int $order_status_id, string $comment): void {
		if ($order_status_id < 1) {
			$order_status_id = (int)$this->config->get('payment_epay_order_status_id');
		}

		if ($order_status_id < 1) {
			$order_status_id = (int)$this->config->get('config_order_status_id');
		}

		if ($order_status_id < 1) {
			$order_status_id = 1;
		}

		if ($order_status_id < 1) {
			return;
		}

		if (method_exists($this->model_sale_order, 'addHistory')) {
			$this->model_sale_order->addHistory($order_id, $order_status_id, $comment, true);
		} elseif (method_exists($this->model_sale_order, 'addOrderHistory')) {
			$this->model_sale_order->addOrderHistory($order_id, $order_status_id, $comment, true);
		}
	}
}
