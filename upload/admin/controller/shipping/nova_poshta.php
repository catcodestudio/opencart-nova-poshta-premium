<?php
namespace Opencart\Admin\Controller\Extension\NovaPoshtaPremium\Shipping;

require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/client.php';

class NovaPoshta extends \Opencart\System\Engine\Controller {
	private function jsonResponse(array $data): void {
		// Suppress PHP notices/deprecations from leaking into the JSON body
		// (e.g. core ecb.php curl_close deprecation under PHP 8.5+).
		if (ob_get_level() > 0) {
			ob_clean();
		}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));
	}

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

		$ut = $this->session->data['user_token'];
		$data['save']           = $this->url->link('extension/nova_poshta_premium/shipping/nova_poshta.save', 'user_token=' . $ut);
		$data['test']           = $this->url->link('extension/nova_poshta_premium/shipping/nova_poshta.test', 'user_token=' . $ut);
		$data['search_cities']  = $this->url->link('extension/nova_poshta_premium/shipping/nova_poshta.searchCities', 'user_token=' . $ut);
		$data['get_warehouses'] = $this->url->link('extension/nova_poshta_premium/shipping/nova_poshta.getWarehouses', 'user_token=' . $ut);
		$data['quote_preview']  = $this->url->link('extension/nova_poshta_premium/shipping/nova_poshta.quotePreview', 'user_token=' . $ut);
		$data['back']           = $this->url->link('marketplace/extension', 'user_token=' . $ut . '&type=shipping');

		$data['shipping_nova_poshta_api_key']             = $this->config->get('shipping_nova_poshta_api_key');
		$data['shipping_nova_poshta_default_cost']        = $this->config->get('shipping_nova_poshta_default_cost');
		$data['shipping_nova_poshta_status']              = $this->config->get('shipping_nova_poshta_status');
		$data['shipping_nova_poshta_sort_order']          = $this->config->get('shipping_nova_poshta_sort_order');
		$data['shipping_nova_poshta_tax_class_id']        = (int)$this->config->get('shipping_nova_poshta_tax_class_id');
		$data['shipping_nova_poshta_geo_zone_id']         = (int)$this->config->get('shipping_nova_poshta_geo_zone_id');
		$data['shipping_nova_poshta_sender_city_ref']     = (string)$this->config->get('shipping_nova_poshta_sender_city_ref');
		$data['shipping_nova_poshta_sender_city_name']    = (string)$this->config->get('shipping_nova_poshta_sender_city_name');
		$data['shipping_nova_poshta_sender_warehouse_ref']  = (string)$this->config->get('shipping_nova_poshta_sender_warehouse_ref');
		$data['shipping_nova_poshta_sender_warehouse_name'] = (string)$this->config->get('shipping_nova_poshta_sender_warehouse_name');

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

		$this->jsonResponse($json);
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

		$this->jsonResponse($json);
	}

	public function searchCities(): void {
		$this->load->language('extension/nova_poshta_premium/shipping/nova_poshta');

		$json = ['cities' => []];

		if (!$this->user->hasPermission('modify', 'extension/nova_poshta_premium/shipping/nova_poshta')) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			$query = trim((string)($this->request->post['q'] ?? $this->request->get['q'] ?? ''));
			$key   = (string)$this->config->get('shipping_nova_poshta_api_key');

			if ($key === '') {
				$json['error'] = $this->language->get('error_api_key_empty');
			} elseif ($query === '') {
				$json['error'] = $this->language->get('error_query_empty');
			} else {
				$client   = new \Opencart\System\Library\NovaPoshta\Client($key);
				$response = $client->call('Address', 'getCities', ['FindByString' => $query, 'Limit' => '20']);

				if (!empty($response['success']) && is_array($response['data'])) {
					foreach ($response['data'] as $row) {
						$json['cities'][] = [
							'ref'  => (string)($row['Ref'] ?? ''),
							'name' => (string)($row['Description'] ?? ''),
							'area' => (string)($row['AreaDescription'] ?? ''),
						];
					}
				} else {
					$json['error'] = $this->language->get('text_test_fail') . ' ' . (is_array($response['errors'] ?? null) ? implode('; ', $response['errors']) : '');
				}
			}
		}

		$this->jsonResponse($json);
	}

	public function getWarehouses(): void {
		$this->load->language('extension/nova_poshta_premium/shipping/nova_poshta');

		$json = ['warehouses' => []];

		if (!$this->user->hasPermission('modify', 'extension/nova_poshta_premium/shipping/nova_poshta')) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			$cityRef = trim((string)($this->request->post['city_ref'] ?? $this->request->get['city_ref'] ?? ''));
			$key     = (string)$this->config->get('shipping_nova_poshta_api_key');

			if ($key === '') {
				$json['error'] = $this->language->get('error_api_key_empty');
			} elseif ($cityRef === '') {
				$json['error'] = $this->language->get('error_city_empty');
			} else {
				$client   = new \Opencart\System\Library\NovaPoshta\Client($key);
				$response = $client->call('Address', 'getWarehouses', ['CityRef' => $cityRef, 'Limit' => '500']);

				if (!empty($response['success']) && is_array($response['data'])) {
					foreach ($response['data'] as $row) {
						$json['warehouses'][] = [
							'ref'        => (string)($row['Ref'] ?? ''),
							'number'     => (string)($row['Number'] ?? ''),
							'description'=> (string)($row['Description'] ?? ''),
							'type_ref'   => (string)($row['TypeOfWarehouse'] ?? ''),
						];
					}
				} else {
					$json['error'] = $this->language->get('text_test_fail') . ' ' . (is_array($response['errors'] ?? null) ? implode('; ', $response['errors']) : '');
				}
			}
		}

		$this->jsonResponse($json);
	}

	public function quotePreview(): void {
		$this->load->language('extension/nova_poshta_premium/shipping/nova_poshta');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/nova_poshta_premium/shipping/nova_poshta')) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			$key       = (string)$this->config->get('shipping_nova_poshta_api_key');
			$senderRef = (string)$this->config->get('shipping_nova_poshta_sender_city_ref');
			// Kyiv city ref — well-known constant for the preview destination
			$kyivRef   = '8d5a980d-391c-11dd-90d9-001a92567626';

			if ($key === '') {
				$json['error'] = $this->language->get('error_api_key_empty');
			} elseif ($senderRef === '') {
				$json['error'] = $this->language->get('error_sender_city_empty');
			} else {
				$client   = new \Opencart\System\Library\NovaPoshta\Client($key);
				$response = $client->call('InternetDocument', 'getDocumentPrice', [
					'CitySender'    => $senderRef,
					'CityRecipient' => $kyivRef,
					'Weight'        => '1',
					'ServiceType'   => 'WarehouseWarehouse',
					'Cost'          => '500',
					'CargoType'     => 'Cargo',
					'SeatsAmount'   => '1',
				]);

				if (!empty($response['success']) && !empty($response['data'][0]['Cost'])) {
					$json['success'] = sprintf($this->language->get('text_quote_ok'), (float)$response['data'][0]['Cost']);
				} else {
					$json['error'] = $this->language->get('text_quote_fail') . ' ' . (is_array($response['errors'] ?? null) ? implode('; ', $response['errors']) : '');
				}
			}
		}

		$this->jsonResponse($json);
	}
}
