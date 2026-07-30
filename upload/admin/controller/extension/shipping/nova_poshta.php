<?php
require_once DIR_SYSTEM . 'library/novaposhta/client.php';
require_once DIR_SYSTEM . 'library/novaposhta/crypto.php';
require_once DIR_SYSTEM . 'library/novaposhta/cache.php';
require_once DIR_SYSTEM . 'library/novaposhta/license.php';

/**
 * Nova Poshta Premium — admin settings controller (OpenCart 3.0.x).
 */
class ControllerExtensionShippingNovaPoshta extends Controller {
	private function jsonResponse($data) {
		if (ob_get_level() > 0) {
			ob_clean();
		}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));
	}

	private function apiKey() {
		$raw = (string)$this->config->get('shipping_nova_poshta_api_key');
		return $raw === '' ? '' : NpCrypto::decrypt($raw);
	}

	/**
	 * Key for the admin AJAX helpers (city search, warehouses, rate preview,
	 * sender lookups). The one typed in the form wins over the stored one.
	 *
	 * Without this, a freshly pasted key passed the connection test (which
	 * always used the posted value) while every other tool answered "nothing
	 * found" until the settings were saved.
	 */
	private function requestApiKey() {
		$posted = isset($this->request->post['shipping_nova_poshta_api_key'])
			? trim((string)$this->request->post['shipping_nova_poshta_api_key'])
			: '';
		return $posted !== '' ? $posted : $this->apiKey();
	}

	public function install() {
		$prefix = DB_PREFIX;

		$this->db->query("CREATE TABLE IF NOT EXISTS `{$prefix}np_shipment` (
			`shipment_id` int NOT NULL AUTO_INCREMENT,
			`order_id` int NOT NULL,
			`int_doc_number` varchar(32) DEFAULT NULL,
			`int_doc_ref` varchar(64) DEFAULT NULL,
			`return_int_doc_number` varchar(32) DEFAULT NULL,
			`return_int_doc_ref` varchar(64) DEFAULT NULL,
			`sender_city_ref` varchar(64) DEFAULT NULL,
			`sender_warehouse_ref` varchar(64) DEFAULT NULL,
			`recipient_city_ref` varchar(64) DEFAULT NULL,
			`recipient_warehouse_ref` varchar(64) DEFAULT NULL,
			`recipient_phone` varchar(32) DEFAULT NULL,
			`recipient_name` varchar(255) DEFAULT NULL,
			`service_type` varchar(32) DEFAULT NULL,
			`weight` decimal(10,3) DEFAULT NULL,
			`declared_cost` decimal(15,2) DEFAULT NULL,
			`cod_amount` decimal(15,2) DEFAULT NULL,
			`status_code` int DEFAULT '0',
			`status_text` varchar(255) DEFAULT NULL,
			`money_transfer_number` varchar(64) DEFAULT NULL,
			`created_at` datetime DEFAULT NULL,
			`last_polled_at` datetime DEFAULT NULL,
			PRIMARY KEY (`shipment_id`),
			KEY `order_id` (`order_id`),
			KEY `int_doc_number` (`int_doc_number`),
			KEY `status_code` (`status_code`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `{$prefix}np_shipment_history` (
			`history_id` int NOT NULL AUTO_INCREMENT,
			`shipment_id` int NOT NULL,
			`status_code` int DEFAULT '0',
			`status_text` varchar(255) DEFAULT NULL,
			`changed_at` datetime DEFAULT NULL,
			PRIMARY KEY (`history_id`),
			KEY `shipment_id` (`shipment_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `{$prefix}np_webhook_endpoint` (
			`endpoint_id` int NOT NULL AUTO_INCREMENT,
			`url` varchar(512) NOT NULL,
			`secret` varchar(128) DEFAULT NULL,
			`events` varchar(255) DEFAULT 'status.changed',
			`status` tinyint(1) DEFAULT '1',
			PRIMARY KEY (`endpoint_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `{$prefix}np_webhook_delivery` (
			`delivery_id` int NOT NULL AUTO_INCREMENT,
			`endpoint_id` int NOT NULL,
			`shipment_id` int DEFAULT NULL,
			`event` varchar(64) DEFAULT NULL,
			`payload` longtext,
			`response_code` int DEFAULT NULL,
			`response_body` text,
			`attempt` int DEFAULT '1',
			`status` enum('queued','sent','failed') DEFAULT 'queued',
			`next_retry_at` datetime DEFAULT NULL,
			`created_at` datetime DEFAULT NULL,
			PRIMARY KEY (`delivery_id`),
			KEY `endpoint_id` (`endpoint_id`),
			KEY `status_next` (`status`,`next_retry_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `{$prefix}np_cities` (
			`ref` char(36) NOT NULL,
			`description` varchar(255) DEFAULT NULL,
			`area_description` varchar(255) DEFAULT NULL,
			`updated_at` datetime DEFAULT NULL,
			PRIMARY KEY (`ref`),
			KEY `description` (`description`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `{$prefix}np_warehouses` (
			`ref` char(36) NOT NULL,
			`city_ref` char(36) NOT NULL,
			`number` varchar(16) DEFAULT NULL,
			`description` varchar(512) DEFAULT NULL,
			`type_ref` char(36) DEFAULT NULL,
			`updated_at` datetime DEFAULT NULL,
			PRIMARY KEY (`ref`),
			KEY `city_ref` (`city_ref`),
			KEY `updated_at` (`updated_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		// Register events (OC3 positional addEvent; triggers app-prefixed).
		$this->load->model('setting/event');
		foreach ($this->eventCodes() as $code) {
			$this->model_setting_event->deleteEventByCode($code);
		}
		$this->model_setting_event->addEvent('np_premium_order_added', 'catalog/model/checkout/order/addOrder/after', 'extension/shipping/nova_poshta/orderAdded', 1, 10);
		$this->model_setting_event->addEvent('np_premium_order_before', 'catalog/model/checkout/order/addOrder/before', 'extension/shipping/nova_poshta/addOrderBefore', 1, 10);
		$this->model_setting_event->addEvent('np_premium_order_history', 'catalog/model/checkout/order/addOrderHistory/after', 'extension/shipping/nova_poshta/orderHistoryAdded', 1, 10);
		$this->model_setting_event->addEvent('np_premium_footer_inject', 'catalog/view/common/footer/after', 'extension/shipping/nova_poshta/footerInject', 1, 10);
		// The branch is chosen in the delivery step, after OpenCart's address step
		// has already validated its required Address 1 — keep that hidden field
		// filled so a hidden-field error can never stall the checkout.
		$this->model_setting_event->addEvent('np_premium_addr_guest', 'catalog/controller/checkout/guest/save/before', 'extension/shipping/nova_poshta/addressPresave', 1, 10);
		$this->model_setting_event->addEvent('np_premium_addr_register', 'catalog/controller/checkout/register/save/before', 'extension/shipping/nova_poshta/addressPresave', 1, 10);
		$this->model_setting_event->addEvent('np_premium_addr_payment', 'catalog/controller/checkout/payment_address/save/before', 'extension/shipping/nova_poshta/addressPresave', 1, 10);
		$this->model_setting_event->addEvent('np_premium_addr_shipping', 'catalog/controller/checkout/shipping_address/save/before', 'extension/shipping/nova_poshta/addressPresave', 1, 10);

		// Grant access+modify on our admin routes to the current user group.
		$this->load->model('user/user_group');
		foreach (array('extension/shipping/nova_poshta', 'extension/shipping/np_shipment') as $route) {
			try {
				$this->model_user_user_group->addPermission((int)$this->user->getGroupId(), 'access', $route);
				$this->model_user_user_group->addPermission((int)$this->user->getGroupId(), 'modify', $route);
			} catch (\Exception $e) {}
		}
	}

	/** Every event code this extension owns — install/uninstall stay in sync. */
	private function eventCodes() {
		return array(
			'np_premium_order_added',
			'np_premium_order_before',
			'np_premium_order_history',
			'np_premium_footer_inject',
			'np_premium_addr_guest',
			'np_premium_addr_register',
			'np_premium_addr_payment',
			'np_premium_addr_shipping',
		);
	}

	public function uninstall() {
		$this->load->model('setting/event');
		foreach ($this->eventCodes() as $code) {
			try { $this->model_setting_event->deleteEventByCode($code); } catch (\Exception $e) {}
		}
		// Tables preserved on uninstall to avoid losing shipment history.
	}

	public function setup() {
		$this->load->language('extension/shipping/nova_poshta');
		$json = array();
		if (!$this->user->hasPermission('modify', 'extension/shipping/nova_poshta')) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			try {
				$this->install();
				$json['success'] = $this->language->get('text_setup_ok');
			} catch (\Exception $e) {
				$json['error'] = 'Setup failed: ' . $e->getMessage();
			}
		}
		$this->jsonResponse($json);
	}

	public function index() {
		$this->load->language('extension/shipping/nova_poshta');
		$this->document->setTitle($this->language->get('heading_title'));

		$ut = $this->session->data['user_token'];

		$data['breadcrumbs'] = array();
		$data['breadcrumbs'][] = array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $ut, true));
		$data['breadcrumbs'][] = array('text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', 'user_token=' . $ut . '&type=shipping', true));
		$data['breadcrumbs'][] = array('text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/shipping/nova_poshta', 'user_token=' . $ut, true));

		$data['save']               = $this->url->link('extension/shipping/nova_poshta/save', 'user_token=' . $ut, true);
		$data['test']               = $this->url->link('extension/shipping/nova_poshta/test', 'user_token=' . $ut, true);
		$data['search_cities']      = $this->url->link('extension/shipping/nova_poshta/searchCities', 'user_token=' . $ut, true);
		$data['get_warehouses']     = $this->url->link('extension/shipping/nova_poshta/getWarehouses', 'user_token=' . $ut, true);
		$data['quote_preview']      = $this->url->link('extension/shipping/nova_poshta/quotePreview', 'user_token=' . $ut, true);
		$data['setup_url']          = $this->url->link('extension/shipping/nova_poshta/setup', 'user_token=' . $ut, true);
		$data['shipments_url']      = $this->url->link('extension/shipping/np_shipment', 'user_token=' . $ut, true);
		$data['url_license_check']  = $this->url->link('extension/shipping/nova_poshta/licenseCheck', 'user_token=' . $ut, true);
		$data['url_license_off']    = $this->url->link('extension/shipping/nova_poshta/licenseDeactivate', 'user_token=' . $ut, true);
		$data['url_counterparties'] = $this->url->link('extension/shipping/nova_poshta/loadCounterparties', 'user_token=' . $ut, true);
		$data['url_contacts']       = $this->url->link('extension/shipping/nova_poshta/loadContactPersons', 'user_token=' . $ut, true);
		$data['back']               = $this->url->link('marketplace/extension', 'user_token=' . $ut . '&type=shipping', true);
		$data['user_token']         = $ut;

		// Cron URL hints (merchant adds these to system crontab).
		$base = defined('HTTPS_CATALOG') ? HTTPS_CATALOG : (defined('HTTP_CATALOG') ? HTTP_CATALOG : '');
		$data['cron_poll']    = $base . 'index.php?route=extension/shipping/nova_poshta/pollStatus';
		$data['cron_webhook'] = $base . 'index.php?route=extension/shipping/nova_poshta/dispatchWebhooks';
		$data['cron_license'] = $base . 'index.php?route=extension/shipping/nova_poshta/licenseCheck';
		$data['cron_cities']  = $base . 'index.php?route=extension/shipping/nova_poshta/syncCities';
		$data['cron_cod']     = $base . 'index.php?route=extension/shipping/nova_poshta/syncCod';

		$data['shipping_nova_poshta_api_key']               = $this->apiKey();
		$data['shipping_nova_poshta_default_cost']          = $this->config->get('shipping_nova_poshta_default_cost');
		$data['shipping_nova_poshta_accent_color']          = (string)($this->config->get('shipping_nova_poshta_accent_color') ?: '#da291c');
		$radiusCfg = $this->config->get('shipping_nova_poshta_radius');
		$data['shipping_nova_poshta_radius']                = ($radiusCfg === null || $radiusCfg === '') ? 14 : (int)$radiusCfg;
		$data['shipping_nova_poshta_theme']                 = (string)($this->config->get('shipping_nova_poshta_theme') ?: 'auto');
		$data['shipping_nova_poshta_status']                = $this->config->get('shipping_nova_poshta_status');
		$data['shipping_nova_poshta_sort_order']            = $this->config->get('shipping_nova_poshta_sort_order');
		$data['shipping_nova_poshta_tax_class_id']          = (int)$this->config->get('shipping_nova_poshta_tax_class_id');
		$data['shipping_nova_poshta_geo_zone_id']           = (int)$this->config->get('shipping_nova_poshta_geo_zone_id');
		$data['shipping_nova_poshta_sender_city_ref']       = (string)$this->config->get('shipping_nova_poshta_sender_city_ref');
		$data['shipping_nova_poshta_sender_city_name']      = (string)$this->config->get('shipping_nova_poshta_sender_city_name');
		$data['shipping_nova_poshta_sender_warehouse_ref']  = (string)$this->config->get('shipping_nova_poshta_sender_warehouse_ref');
		$data['shipping_nova_poshta_sender_warehouse_name'] = (string)$this->config->get('shipping_nova_poshta_sender_warehouse_name');
		$data['shipping_nova_poshta_sender_counterparty_ref']  = (string)$this->config->get('shipping_nova_poshta_sender_counterparty_ref');
		$data['shipping_nova_poshta_sender_counterparty_name'] = (string)$this->config->get('shipping_nova_poshta_sender_counterparty_name');
		$data['shipping_nova_poshta_sender_contact_ref']    = (string)$this->config->get('shipping_nova_poshta_sender_contact_ref');
		$data['shipping_nova_poshta_sender_contact_name']   = (string)$this->config->get('shipping_nova_poshta_sender_contact_name');
		$data['shipping_nova_poshta_sender_phone']          = (string)$this->config->get('shipping_nova_poshta_sender_phone');
		$data['shipping_nova_poshta_auto_ttn_status_id']    = (int)$this->config->get('shipping_nova_poshta_auto_ttn_status_id');
		$data['shipping_nova_poshta_license_key']           = (string)$this->config->get('shipping_nova_poshta_license_key');
		$data['shipping_nova_poshta_license_status']        = NpLicense::describe($this->config);
		$data['shipping_nova_poshta_license_is_pro']        = NpLicense::isPro($this->config);

		$this->load->model('localisation/tax_class');
		$data['tax_classes'] = $this->model_localisation_tax_class->getTaxClasses();

		$this->load->model('localisation/geo_zone');
		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

		$this->load->model('localisation/order_status');
		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

		$data['header']      = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer']      = $this->load->controller('common/footer');

		$data = array_merge($this->language->all(), $data);

		$this->response->setOutput($this->load->view('extension/shipping/nova_poshta', $data));
	}

	public function save() {
		$this->load->language('extension/shipping/nova_poshta');
		$json = array();

		if (!$this->user->hasPermission('modify', 'extension/shipping/nova_poshta')) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json) {
			$post = $this->request->post;
			$this->load->model('setting/setting');
			$current = $this->model_setting_setting->getSetting('shipping_nova_poshta');
			if (!is_array($current)) { $current = array(); }

			if (!isset($post['shipping_nova_poshta_api_key']) || $post['shipping_nova_poshta_api_key'] === '') {
				unset($post['shipping_nova_poshta_api_key']);
			} else {
				$post['shipping_nova_poshta_api_key'] = NpCrypto::encrypt(trim($post['shipping_nova_poshta_api_key']));
			}

			$merged = array_merge($current, $post);
			$this->model_setting_setting->editSetting('shipping_nova_poshta', $merged);
			$json['success'] = $this->language->get('text_success');
		}

		$this->jsonResponse($json);
	}

	public function test() {
		$this->load->language('extension/shipping/nova_poshta');
		$json = array();
		if (!$this->user->hasPermission('modify', 'extension/shipping/nova_poshta')) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			$key = trim((string)(isset($this->request->post['shipping_nova_poshta_api_key']) ? $this->request->post['shipping_nova_poshta_api_key'] : ''));
			if ($key === '') {
				$key = $this->apiKey();
			}
			if ($key === '') {
				$json['error'] = $this->language->get('error_api_key_empty');
			} else {
				$client   = new NpClient($key);
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

	public function searchCities() {
		$this->load->language('extension/shipping/nova_poshta');
		$json = array('cities' => array());
		if (!$this->user->hasPermission('modify', 'extension/shipping/nova_poshta')) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			$query = trim((string)(isset($this->request->post['q']) ? $this->request->post['q'] : (isset($this->request->get['q']) ? $this->request->get['q'] : '')));
			if ($query === '') {
				$json['error'] = $this->language->get('error_query_empty');
			} else {
				$json = array('cities' => NpCache::searchCities($this->db, $query, $this->requestApiKey()));
			}
		}
		$this->jsonResponse($json);
	}

	public function getWarehouses() {
		$this->load->language('extension/shipping/nova_poshta');
		$json = array('warehouses' => array());
		if (!$this->user->hasPermission('modify', 'extension/shipping/nova_poshta')) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			$cityRef = trim((string)(isset($this->request->post['city_ref']) ? $this->request->post['city_ref'] : (isset($this->request->get['city_ref']) ? $this->request->get['city_ref'] : '')));
			if ($cityRef === '') {
				$json['error'] = $this->language->get('error_city_empty');
			} else {
				$json = array('warehouses' => NpCache::getWarehouses($this->db, $cityRef, $this->requestApiKey()));
			}
		}
		$this->jsonResponse($json);
	}

	public function quotePreview() {
		$this->load->language('extension/shipping/nova_poshta');
		$json = array();
		if (!$this->user->hasPermission('modify', 'extension/shipping/nova_poshta')) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			$key       = $this->requestApiKey();
			$senderRef = (string)$this->config->get('shipping_nova_poshta_sender_city_ref');
			$kyivRef   = '8d5a980d-391c-11dd-90d9-001a92567626';
			if ($key === '') {
				$json['error'] = $this->language->get('error_api_key_empty');
			} elseif ($senderRef === '') {
				$json['error'] = $this->language->get('error_sender_city_empty');
			} else {
				$client   = new NpClient($key);
				$response = $client->call('InternetDocument', 'getDocumentPrice', array(
					'CitySender'    => $senderRef,
					'CityRecipient' => $kyivRef,
					'Weight'        => '1',
					'ServiceType'   => 'WarehouseWarehouse',
					'Cost'          => '500',
					'CargoType'     => 'Cargo',
					'SeatsAmount'   => '1',
				));
				if (!empty($response['success']) && !empty($response['data'][0]['Cost'])) {
					$json['success'] = sprintf($this->language->get('text_quote_ok'), (float)$response['data'][0]['Cost']);
				} else {
					$json['error'] = $this->language->get('text_quote_fail') . ' ' . (is_array(isset($response['errors']) ? $response['errors'] : null) ? implode('; ', $response['errors']) : '');
				}
			}
		}
		$this->jsonResponse($json);
	}

	public function loadCounterparties() {
		$this->load->language('extension/shipping/nova_poshta');
		$json = array('counterparties' => array());
		if (!$this->user->hasPermission('modify', 'extension/shipping/nova_poshta')) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			$key = $this->requestApiKey();
			if ($key === '') {
				$json['error'] = $this->language->get('error_api_key_empty');
			} else {
				$client = new NpClient($key);
				$resp = $client->getSenderCounterparties();
				if (!empty($resp['success']) && is_array(isset($resp['data']) ? $resp['data'] : null)) {
					foreach ($resp['data'] as $row) {
						$json['counterparties'][] = array(
							'ref'  => (string)(isset($row['Ref']) ? $row['Ref'] : ''),
							'name' => (string)(isset($row['Description']) ? $row['Description'] : ''),
							'type' => (string)(isset($row['CounterpartyType']) ? $row['CounterpartyType'] : ''),
						);
					}
				} else {
					$json['error'] = (is_array(isset($resp['errors']) ? $resp['errors'] : null) ? implode('; ', $resp['errors']) : 'Unknown error');
				}
			}
		}
		$this->jsonResponse($json);
	}

	public function loadContactPersons() {
		$this->load->language('extension/shipping/nova_poshta');
		$json = array('contacts' => array());
		if (!$this->user->hasPermission('modify', 'extension/shipping/nova_poshta')) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			$key = $this->requestApiKey();
			$cpRef = (string)(isset($this->request->post['counterparty_ref']) ? $this->request->post['counterparty_ref'] : '');
			if ($key === '' || $cpRef === '') {
				$json['error'] = $this->language->get('error_api_key_empty');
			} else {
				$client = new NpClient($key);
				$resp = $client->getContactPersons($cpRef);
				if (!empty($resp['success']) && is_array(isset($resp['data']) ? $resp['data'] : null)) {
					foreach ($resp['data'] as $row) {
						$json['contacts'][] = array(
							'ref'   => (string)(isset($row['Ref']) ? $row['Ref'] : ''),
							'name'  => (string)(isset($row['Description']) ? $row['Description'] : ''),
							'phone' => (string)(isset($row['Phones']) ? $row['Phones'] : ''),
						);
					}
				} else {
					$json['error'] = (is_array(isset($resp['errors']) ? $resp['errors'] : null) ? implode('; ', $resp['errors']) : 'Unknown error');
				}
			}
		}
		$this->jsonResponse($json);
	}

	public function licenseCheck() {
		$this->load->language('extension/shipping/nova_poshta');
		$json = array();
		if (!$this->user->hasPermission('modify', 'extension/shipping/nova_poshta')) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			$key = trim((string)(isset($this->request->post['shipping_nova_poshta_license_key']) ? $this->request->post['shipping_nova_poshta_license_key'] : $this->config->get('shipping_nova_poshta_license_key')));
			if ($key === '') {
				$json['error'] = $this->language->get('error_license_empty');
			} else {
				$this->load->model('setting/setting');
				$storedKey = (string)$this->config->get('shipping_nova_poshta_license_key');
				$result = ($storedKey !== '' && $storedKey === $key)
					? NpLicense::verify($this->config, $this->model_setting_setting)
					: NpLicense::activate($this->config, $this->model_setting_setting, $key);

				if (!empty($result['ok'])) {
					$json['success'] = sprintf($this->language->get('text_license_ok'), (string)(isset($result['product_name']) ? $result['product_name'] : ''));
				} else {
					$err = (string)(isset($result['error']) ? $result['error'] : 'unknown');
					$key_for_msg = 'text_license_err_' . $err;
					$msg = $this->language->get($key_for_msg);
					if ($msg === $key_for_msg) {
						$msg = $this->language->get('text_license_bad') . ' (' . $err . ')';
					}
					$json['error'] = $msg;
				}
			}
		}
		$this->jsonResponse($json);
	}

	public function licenseDeactivate() {
		$this->load->language('extension/shipping/nova_poshta');
		$json = array();
		if (!$this->user->hasPermission('modify', 'extension/shipping/nova_poshta')) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			$this->load->model('setting/setting');
			NpLicense::deactivate($this->config, $this->model_setting_setting);
			$json['success'] = $this->language->get('text_license_deactivated');
		}
		$this->jsonResponse($json);
	}
}
