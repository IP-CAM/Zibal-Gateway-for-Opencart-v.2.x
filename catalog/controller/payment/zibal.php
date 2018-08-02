<?php

class ControllerPaymentZibal extends Controller
{
	public function index()
	{
		$this->load->language('payment/zibal');
		$this->load->model('checkout/order');
		$this->load->library('encryption');

		$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
		$amount     = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);

		$encryption = new Encryption($this->config->get('config_encryption'));

		if ($this->currency->getCode() != 'RLS') {

			$amount = $amount * 10;
		}

		$data['button_confirm'] = $this->language->get('button_confirm');

		$data['error_warning'] = false;

		if (extension_loaded('curl')) {

            $merchant         = $this->config->get('zibal_merchant');
			$callback    = $this->url->link('payment/zibal/callback', 'order_id=' . $encryption->encrypt($order_info['order_id']), '', 'SSL');
			$telephone   = $order_info['telephone'];
			$order_id    = $order_info['order_id'];
			$description = 'پرداخت سفارش شناسه ' . $order_info['order_id'];

			$params = array (

				'merchant'          => $merchant,
				'amount'       => $amount,
				'callbackUrl'     => urlencode($callback),
				'mobile'       => $telephone,
				'orderId' => $order_id,
				'description'  => $description
			);

			$result = $this->common('https://gateway.zibal.ir/request', $params);

			if ($result && isset($result->result) && $result->result == 100) {

				$data['action'] = 'https://gateway.zibal.ir/start/' . $result->trackId;

			} else {

				$message = isset($result->message) ? $result->message : $this->language->get('error_request');

				$data['error_warning'] = $message;
			}

		} else {

			$data['error_warning'] = $this->language->get('error_curl');
		}

		if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/payment/zibal.tpl')) {

			return $this->load->view($this->config->get('config_template') . '/template/payment/zibal.tpl', $data);

		} else {

			return $this->load->view('default/template/payment/zibal.tpl', $data);
		}
	}

	public function callback()
	{
		$this->load->language('payment/zibal');
		$this->load->model('checkout/order');
		$this->load->library('encryption');

		$this->document->setTitle($this->language->get('heading_title'));

		$encryption = new Encryption($this->config->get('config_encryption'));

		$order_id = isset($this->session->data['order_id']) ? $this->session->data['order_id'] : false;
		$order_id = isset($order_id) ? $order_id : $encryption->decrypt($this->request->get['order_id']);

		$order_info = $this->model_checkout_order->getOrder($order_id);
		$amount     = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);

		if ($this->currency->getCode() != 'RLS') {

			$amount = $amount * 10;
		}

		$data['heading_title'] = $this->language->get('heading_title');

		$data['button_continue'] = $this->language->get('button_continue');
		$data['continue']        = $this->url->link('common/home', '', 'SSL');

		$data['error_warning'] = false;

		if ($this->request->post['success'] && $this->request->post['trackId'] && $this->request->post['orderId']) {

			$success        = $this->request->post['success'];
			$trackId      = $this->request->post['trackId'];
			$orderId = $this->request->post['orderId'];

			if (isset($success) && $success == 1) {

				if ($order_id == $orderId && $orderId == $order_info['order_id']) {

					$params = array (

						'merchant'     => $this->config->get('zibal_api'),
						'trackId' => $trackId
					);

					$result = $this->common('https://gateway.zibal.ir/verify', $params);

					if ($result && isset($result->result) && $result->result == 100) {
                        
						if ($amount == $result->amount) {

							$comment = $this->language->get('text_transaction') . $trackId;

							$this->model_checkout_order->addOrderHistory($order_info['order_id'], $this->config->get('zibal_order_status_id'), $comment);

						} else {

							$data['error_warning'] = $this->language->get('error_amount');
						}

					} else {

						$message = isset($result->message) ? $result->message : $this->language->get('error_undefined');

						$data['error_warning'] =  $this->language->get('error_request') . '<br/>' . $message;
					}

				} else {

					$data['error_warning'] = $this->language->get('error_invoice');
				}

			} else {

				$data['error_warning'] = $this->language->get('error_payment');
			}

		} else {

			$data['error_warning'] = $this->language->get('error_data');
		}

		if ($data['error_warning']) {

			$data['breadcrumbs'] = array ();

			$data['breadcrumbs'][] = array (

				'text' => $this->language->get('text_home'),
				'href' => $this->url->link('common/home', '', 'SSL')
			);

			$data['breadcrumbs'][] = array (

				'text' => $this->language->get('text_basket'),
				'href' => $this->url->link('checkout/cart', '', 'SSL')
			);
		
			$data['breadcrumbs'][] = array (

				'text' => $this->language->get('text_checkout'),
				'href' => $this->url->link('checkout/checkout', '', 'SSL')
			);

			$data['header'] = $this->load->controller('common/header');
			$data['footer'] = $this->load->controller('common/footer');

			if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/payment/zibal_callback.tpl')) {

				$this->response->setOutput($this->load->view($this->config->get('config_template') . '/template/payment/zibal_callback.tpl', $data));

			} else {

				$this->response->setOutput($this->load->view('default/template/payment/zibal_callback.tpl', $data));
			}

		} else {

			$this->response->redirect($this->url->link('checkout/success', '', 'SSL'));
		}
	}

	protected function common($url, $params)
	{
		$ch = curl_init();
		
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
		
			$response = curl_exec($ch);
			$error    = curl_errno($ch);
		
			curl_close($ch);
		
			$output = $error ? false : json_decode($response);
		
			return $output;
	}
}

