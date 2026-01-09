<?php
namespace Opencart\Catalog\Controller\Extension\Epay\Payment;

class Epay extends \Opencart\System\Engine\Controller {
	public function index(): string {
		$this->load->language('extension/epay/payment/epay');

		$data['button_confirm'] = $this->language->get('button_confirm');
		$data['language'] = $this->config->get('config_language');

		return $this->load->view('extension/epay/payment/epay', $data);
	}

	public function confirm(): void {
		$this->load->language('extension/epay/payment/epay');

		$json = [];

		if (!isset($this->session->data['order_id'])) {
			$json['error'] = $this->language->get('error_order');
		}

		if (!isset($this->session->data['payment_method']) || $this->session->data['payment_method']['code'] != 'epay.epay') {
			$json['error'] = $this->language->get('error_payment_method');
		}

		if (!$json) {
			$this->load->model('checkout/order');
			$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

			if (!$order_info) {
				$json['error'] = $this->language->get('error_order');
			}
		}

		if (!$json) {
			$this->load->model('extension/epay/payment/epay');

			$urls = [
				'success' => $this->url->link('checkout/success', 'language=' . $this->config->get('config_language'), true),
				'failure' => $this->url->link('checkout/failure', 'language=' . $this->config->get('config_language'), true),
				'return' => $this->url->link('checkout/checkout', 'language=' . $this->config->get('config_language'), true),
                'notification' => $this->url->link('extension/epay/payment/epay.webhook', '', true)
			];

			$result = $this->model_extension_epay_payment_epay->createPaymentSession($order_info, $urls);

			if (isset($result['error'])) {
				$json['error'] = $result['error'];
			} elseif (empty($result['paymentWindowUrl'])) {
				$json['error'] = $this->language->get('error_api');
			} else {
				$order_status_id = (int)$this->config->get('payment_epay_order_status_id');

				if ($order_status_id) {
					$this->model_checkout_order->addHistory(
						$order_info['order_id'],
						$order_status_id,
						$this->language->get('text_session_initialized'),
						true
					);
				}

				$json['redirect'] = $result['paymentWindowUrl'];
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function webhook(): void {
		$this->load->language('extension/epay/payment/epay');

		$expected = trim((string)$this->config->get('payment_epay_webhook_auth'));
		$auth = $this->getAuthorizationHeader();

		if ($expected === '' || $auth === '' || !hash_equals($expected, $auth)) {
			$this->respond(['status' => 'error', 'message' => 'Unauthorized'], 401);
			return;
		}

		$payload = file_get_contents('php://input');
		$data = json_decode($payload ?: '', true);

		if (!is_array($data)) {
			$this->respond(['status' => 'error', 'message' => 'Invalid JSON'], 400);
			return;
		}

		if (isset($data['body'])) {
			if (is_array($data['body'])) {
				$data = $data['body'];
			} elseif (is_string($data['body'])) {
				$decoded_body = json_decode($data['body'], true);
				if (is_array($decoded_body)) {
					$data = $decoded_body;
				}
			}
		}

		if (isset($data['data']) && is_array($data['data']) && (!isset($data['transaction']) || !isset($data['session']))) {
			$data = $data['data'];
		}

		$order_id = $this->extractOrderId($data);

		$this->load->model('extension/epay/payment/epay');

		if (!$order_id && !empty($data['session']['id'])) {
			$order_id = $this->model_extension_epay_payment_epay->getOrderIdBySessionId((string)$data['session']['id']);
		}

		if (!$order_id && !empty($data['transaction']['sessionId'])) {
			$order_id = $this->model_extension_epay_payment_epay->getOrderIdBySessionId((string)$data['transaction']['sessionId']);
		}

		if (!$order_id) {
			$this->log->write('ePay EPIC webhook: missing order id');
			$this->respond(['status' => 'error', 'message' => 'Missing order id']);
			return;
		}

		$this->load->model('checkout/order');
		$order_info = $this->model_checkout_order->getOrder($order_id);

		if (!$order_info) {
			$this->log->write('ePay EPIC webhook: order not found ' . $order_id);
			$this->respond(['status' => 'error', 'message' => 'Order not found', 'order_id' => $order_id]);
			return;
		}

		$transaction = $data['transaction'] ?? [];
		$state = $transaction['state'] ?? '';
		$transaction_id = $transaction['id'];

		if (empty($transaction['sessionId'])) {
			if (!empty($data['session']['id'])) {
				$transaction['sessionId'] = (string)$data['session']['id'];
			} elseif (!empty($data['sessionId'])) {
				$transaction['sessionId'] = (string)$data['sessionId'];
			}
		}

		if ($transaction_id === '') {
			$keys = implode(',', array_keys($data));
			$this->log->write('ePay EPIC webhook: missing transaction id (order_id=' . $order_id . ', keys=' . $keys . ')');
		}

		$this->model_extension_epay_payment_epay->updateTransactionFromWebhook($order_id, $transaction);

		if ($state === 'SUCCESS') {
			$order_status_id = (int)$this->config->get('payment_epay_order_status_id');
			$comment = sprintf($this->language->get('text_webhook_success'), $transaction_id);
		} elseif ($state === 'FAILED') {
			$order_status_id = (int)$this->config->get('payment_epay_failed_status_id');
			$comment = sprintf($this->language->get('text_webhook_failed'), $transaction_id);
		} else {
			$this->respond([
				'status' => 'ignored',
				'order_id' => $order_id,
				'transaction_id' => $transaction_id,
				'state' => $state
			]);
			return;
		}

		if ($order_status_id) {
			$this->model_checkout_order->addHistory($order_id, $order_status_id, $comment, true);
		}

		$this->respond([
			'status' => 'ok',
			'order_id' => $order_id,
			'transaction_id' => $transaction_id,
			'state' => $state
		]);
	}

    private function getAuthorizationHeader(): ?string
    {
        $auth = '';

        if (!empty($this->request->server['HTTP_AUTHORIZATION'])) {
            $auth = $this->request->server['HTTP_AUTHORIZATION'];
        } elseif (!empty($this->request->server['REDIRECT_HTTP_AUTHORIZATION'])) {
            $auth = $this->request->server['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (!empty($headers['Authorization'])) {
                $auth = $headers['Authorization'];
            }
        }

        if (!$auth) {
            return null;
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return null;
        }

        return trim($m[1]);
    }

	private function extractOrderId(array $data): int {
		if (isset($data['session']['attributes']['order_id'])) {
			return (int)$data['session']['attributes']['order_id'];
		}

		if (isset($data['session']['reference'])) {
			return (int)$data['session']['reference'];
		}

		if (isset($data['transaction']['attributes']['order_id'])) {
			return (int)$data['transaction']['attributes']['order_id'];
		}

		if (isset($data['transaction']['reference'])) {
			return (int)$data['transaction']['reference'];
		}

		return 0;
	}

	private function extractTransactionId(array $data, array $transaction): string {
		$candidates = [
			$transaction['id'] ?? '',
			$transaction['transactionId'] ?? '',
			$data['transactionId'] ?? '',
			$data['id'] ?? ''
		];

		if (isset($data['operations']) && is_array($data['operations']) && !empty($data['operations'][0]) && is_array($data['operations'][0])) {
			$candidates[] = $data['operations'][0]['transactionId'] ?? '';
			$candidates[] = $data['operations'][0]['id'] ?? '';
		}

		foreach ($candidates as $value) {
			$value = trim((string)$value);
			if ($value !== '') {
				return $value;
			}
		}

		return '';
	}

	private function respond(array $payload, int $status_code = 200): void {
		if ($status_code === 401) {
			$this->response->addHeader('HTTP/1.1 401 Unauthorized');
		} elseif ($status_code === 400) {
			$this->response->addHeader('HTTP/1.1 400 Bad Request');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($payload));
	}
}
