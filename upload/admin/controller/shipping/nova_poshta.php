<?php
namespace Opencart\Admin\Controller\Extension\NovaPoshtaPremium\Shipping;

class NovaPoshta extends \Opencart\System\Engine\Controller {
	public function index(): void {
		$this->load->language('extension/nova_poshta_premium/shipping/nova_poshta');

		$this->document->setTitle($this->language->get('heading_title'));

		$data['breadcrumbs'] = [
			[
				'text' => $this->language->get('text_home'),
				'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token']),
			],
			[
				'text' => $this->language->get('text_extension'),
				'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=shipping'),
			],
			[
				'text' => $this->language->get('heading_title'),
				'href' => $this->url->link('extension/nova_poshta_premium/shipping/nova_poshta', 'user_token=' . $this->session->data['user_token']),
			],
		];

		$data['save'] = $this->url->link('extension/nova_poshta_premium/shipping/nova_poshta.save', 'user_token=' . $this->session->data['user_token']);
		$data['test'] = $this->url->link('extension/nova_poshta_premium/shipping/nova_poshta.test', 'user_token=' . $this->session->data['user_token']);
		$data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=shipping');

		$data['shipping_nova_poshta_api_key']      = $this->config->get('shipping_nova_poshta_api_key');
		$data['shipping_nova_poshta_default_cost'] = $this->config->get('shipping_nova_poshta_default_cost');
		$data['shipping_nova_poshta_status']       = $this->config->get('shipping_nova_poshta_status');
		$data['shipping_nova_poshta_sort_order']   = $this->config->get('shipping_nova_poshta_sort_order');
		$data['shipping_nova_poshta_tax_class_id'] = (int)$this->config->get('shipping_nova_poshta_tax_class_id');
		$data['shipping_nova_poshta_geo_zone_id']  = (int)$this->config->get('shipping_nova_poshta_geo_zone_id');

		$this->load->model('localisation/tax_class');
		$data['tax_classes'] = $this->model_localisation_tax_class->getTaxClasses();

		$this->load->model('localisation/geo_zone');
		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

		$data['header']      = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer']      = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/nova_poshta_premium/shipping/nova_poshta', $data));
	}

	public function save(): void {
		$this->load->language('extension/nova_poshta_premium/shipping/nova_poshta');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/nova_poshta_premium/shipping/nova_poshta')) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json) {
			$this->load->model('setting/setting');
			$this->model_setting_setting->editSetting('shipping_nova_poshta', $this->request->post);
			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function test(): void {
		$this->load->language('extension/nova_poshta_premium/shipping/nova_poshta');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/nova_poshta_premium/shipping/nova_poshta')) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			$key = trim((string)($this->request->post['shipping_nova_poshta_api_key'] ?? $this->config->get('shipping_nova_poshta_api_key')));

			if ($key === '') {
				$json['error'] = $this->language->get('error_api_key_empty');
			} else {
				$client   = new \Opencart\System\Library\NovaPoshta\Client($key);
				$response = $client->testConnection();

				if (!empty($response['success'])) {
					$count = is_array($response['data']) ? count($response['data']) : 0;
					$json['success'] = sprintf($this->language->get('text_test_ok'), $count);
				} else {
					$json['error'] = $this->language->get('text_test_fail') . ' ' . (is_array($response['errors']) ? implode('; ', $response['errors']) : '');
				}
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}
}
