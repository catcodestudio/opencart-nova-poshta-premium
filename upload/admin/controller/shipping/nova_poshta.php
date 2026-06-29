<?php
namespace Opencart\Admin\Controller\Extension\NovaPoshtaPremium\Shipping;

require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/client.php';
require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/crypto.php';
require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/cache.php';
require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/license.php';

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

	private function apiKey(): string {
		$raw = (string)$this->config->get('shipping_nova_poshta_api_key');
		return $raw === '' ? '' : \Opencart\System\Library\NovaPoshta\Crypto::decrypt($raw);
	}

	public function install(): void {
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

		$this->db->query("CREATE TABLE IF NOT EXISTS `{$prefix}np_api_log` (
			`log_id` int NOT NULL AUTO_INCREMENT,
			`method` varchar(128) DEFAULT NULL,
			`request` longtext,
			`response` longtext,
			`success` tinyint(1) DEFAULT '0',
			`created_at` datetime DEFAULT NULL,
			PRIMARY KEY (`log_id`),
			KEY `created_at` (`created_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->load->model('setting/event');
		$this->model_setting_event->deleteEventByCode('nova_poshta_premium_order_added');
		$this->model_setting_event->deleteEventByCode('nova_poshta_premium_order_history_added');
		$this->model_setting_event->deleteEventByCode('nova_poshta_premium_footer_inject');
		$this->model_setting_event->addEvent([
			'code'        => 'nova_poshta_premium_order_added',
			'description' => 'Nova Poshta Premium — capture cart shipping selection on order create',
			'trigger'     => 'catalog/model/checkout/order/addOrder/after',
			'action'      => 'extension/nova_poshta_premium/events.orderAdded',
			'status'      => 1,
			'sort_order'  => 10,
		]);
		$this->model_setting_event->addEvent([
			'code'        => 'nova_poshta_premium_order_history_added',
			'description' => 'Nova Poshta Premium — auto-create TTN on order status reaching the configured trigger',
			'trigger'     => 'catalog/model/checkout/order/addHistory/after',
			'action'      => 'extension/nova_poshta_premium/events.orderHistoryAdded',
			'status'      => 1,
			'sort_order'  => 10,
		]);
		$this->model_setting_event->addEvent([
			'code'        => 'nova_poshta_premium_footer_inject',
			'description' => 'Nova Poshta Premium — inject checkout picker on storefront footer render',
			'trigger'     => 'catalog/view/common/footer/after',
			'action'      => 'extension/nova_poshta_premium/events.footerInject',
			'status'      => 1,
			'sort_order'  => 10,
		]);

		$this->load->model('setting/cron');
		// Best-effort cleanup of any prior install
		try { $this->model_setting_cron->deleteCronByCode('nova_poshta_premium_poll'); } catch (\Throwable $e) {}
		try { $this->model_setting_cron->deleteCronByCode('nova_poshta_premium_webhook'); } catch (\Throwable $e) {}
		try { $this->model_setting_cron->deleteCronByCode('nova_poshta_premium_license'); } catch (\Throwable $e) {}

		// OC 4.1.0.3 signature: addCron(code, description, cycle, action, status)
		$this->model_setting_cron->addCron('nova_poshta_premium_poll', 'Nova Poshta Premium — poll shipment status', 'hour', 'extension/nova_poshta_premium/cron.pollStatus', true);
		$this->model_setting_cron->addCron('nova_poshta_premium_webhook', 'Nova Poshta Premium — dispatch queued outbound webhooks', 'hour', 'extension/nova_poshta_premium/cron.dispatchWebhooks', true);
		$this->model_setting_cron->addCron('nova_poshta_premium_license', 'Nova Poshta Premium — daily license check', 'day', 'extension/nova_poshta_premium/cron.licenseCheck', true);
		try { $this->model_setting_cron->deleteCronByCode('nova_poshta_premium_sync_cities'); } catch (\Throwable $e) {}
		$this->model_setting_cron->addCron('nova_poshta_premium_sync_cities', 'Nova Poshta Premium — weekly full city sync', 'week', 'extension/nova_poshta_premium/cron.syncCities', true);
		try { $this->model_setting_cron->deleteCronByCode('nova_poshta_premium_sync_cod'); } catch (\Throwable $e) {}
		$this->model_setting_cron->addCron('nova_poshta_premium_sync_cod', 'Nova Poshta Premium — daily COD payout sync', 'day', 'extension/nova_poshta_premium/cron.syncCod', true);

		// Idempotent schema upgrade: add return columns on existing installs.
		try {
			$this->db->query("ALTER TABLE `{$prefix}np_shipment` ADD COLUMN `return_int_doc_number` varchar(32) DEFAULT NULL AFTER `int_doc_ref`");
		} catch (\Throwable $e) {}
		try {
			$this->db->query("ALTER TABLE `{$prefix}np_shipment` ADD COLUMN `return_int_doc_ref` varchar(64) DEFAULT NULL AFTER `return_int_doc_number`");
		} catch (\Throwable $e) {}

		// Grant access+modify on our admin routes to the current user group.
		$this->load->model('user/user_group');
		foreach (['extension/nova_poshta_premium/shipping/nova_poshta', 'extension/nova_poshta_premium/shipment'] as $route) {
			try {
				$this->model_user_user_group->addPermission((int)$this->user->getGroupId(), 'access', $route);
				$this->model_user_user_group->addPermission((int)$this->user->getGroupId(), 'modify', $route);
			} catch (\Throwable $e) {}
		}
	}

	public function uninstall(): void {
		$this->load->model('setting/event');
		foreach (['nova_poshta_premium_order_added', 'nova_poshta_premium_order_history_added', 'nova_poshta_premium_footer_inject'] as $code) {
			try { $this->model_setting_event->deleteEventByCode($code); } catch (\Throwable $e) {}
		}
		$this->load->model('setting/cron');
		foreach (['nova_poshta_premium_poll', 'nova_poshta_premium_webhook', 'nova_poshta_premium_license', 'nova_poshta_premium_sync_cities', 'nova_poshta_premium_sync_cod'] as $code) {
			try { $this->model_setting_cron->deleteCronByCode($code); } catch (\Throwable $e) {}
		}
		// Tables intentionally preserved on uninstall to avoid losing shipment history.
	}

	public function setup(): void {
		$this->load->language('extension/nova_poshta_premium/shipping/nova_poshta');
		$json = [];
		if (!$this->user->hasPermission('modify', 'extension/nova_poshta_premium/shipping/nova_poshta')) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			try {
				$this->install();
				$json['success'] = $this->language->get('text_setup_ok');
			} catch (\Throwable $e) {
				$json['error'] = 'Setup failed: ' . $e->getMessage();
			}
		}
		$this->jsonResponse($json);
	}

	public function index(): void {
		$this->load->language('extension/nova_poshta_premium/shipping/nova_poshta');

		$this->document->setTitle($this->language->get('heading_title'));

		$ut = $this->session->data['user_token'];

		$data['breadcrumbs'] = [
			['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $ut)],
			['text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', 'user_token=' . $ut . '&type=shipping')],
			['text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/nova_poshta_premium/shipping/nova_poshta', 'user_token=' . $ut)],
		];

		$data['save']           = $this->url->link('extension/nova_poshta_premium/shipping/nova_poshta.save', 'user_token=' . $ut);
		$data['test']           = $this->url->link('extension/nova_poshta_premium/shipping/nova_poshta.test', 'user_token=' . $ut);
		$data['search_cities']  = $this->url->link('extension/nova_poshta_premium/shipping/nova_poshta.searchCities', 'user_token=' . $ut);
		$data['get_warehouses'] = $this->url->link('extension/nova_poshta_premium/shipping/nova_poshta.getWarehouses', 'user_token=' . $ut);
		$data['quote_preview']  = $this->url->link('extension/nova_poshta_premium/shipping/nova_poshta.quotePreview', 'user_token=' . $ut);
		$data['setup_url']      = $this->url->link('extension/nova_poshta_premium/shipping/nova_poshta.setup', 'user_token=' . $ut);
		$data['shipments_url']  = $this->url->link('extension/nova_poshta_premium/shipment.list', 'user_token=' . $ut);
		$data['url_license_check']= $this->url->link('extension/nova_poshta_premium/shipping/nova_poshta.licenseCheck', 'user_token=' . $ut);
		$data['url_counterparties'] = $this->url->link('extension/nova_poshta_premium/shipping/nova_poshta.loadCounterparties', 'user_token=' . $ut);
		$data['url_contacts']       = $this->url->link('extension/nova_poshta_premium/shipping/nova_poshta.loadContactPersons', 'user_token=' . $ut);
		$data['back']           = $this->url->link('marketplace/extension', 'user_token=' . $ut . '&type=shipping');

		$data['shipping_nova_poshta_api_key']              = $this->apiKey();
		$data['shipping_nova_poshta_default_cost']         = $this->config->get('shipping_nova_poshta_default_cost');
		$data['shipping_nova_poshta_accent_color']         = (string)($this->config->get('shipping_nova_poshta_accent_color') ?: '#da291c');
		$radiusCfg = $this->config->get('shipping_nova_poshta_radius');
		$data['shipping_nova_poshta_radius']               = ($radiusCfg === null || $radiusCfg === '') ? 14 : (int)$radiusCfg;
		$data['shipping_nova_poshta_theme']                = (string)($this->config->get('shipping_nova_poshta_theme') ?: 'auto');
		$data['shipping_nova_poshta_status']               = $this->config->get('shipping_nova_poshta_status');
		$data['shipping_nova_poshta_sort_order']           = $this->config->get('shipping_nova_poshta_sort_order');
		$data['shipping_nova_poshta_tax_class_id']         = (int)$this->config->get('shipping_nova_poshta_tax_class_id');
		$data['shipping_nova_poshta_geo_zone_id']          = (int)$this->config->get('shipping_nova_poshta_geo_zone_id');
		$data['shipping_nova_poshta_sender_city_ref']      = (string)$this->config->get('shipping_nova_poshta_sender_city_ref');
		$data['shipping_nova_poshta_sender_city_name']     = (string)$this->config->get('shipping_nova_poshta_sender_city_name');
		$data['shipping_nova_poshta_sender_warehouse_ref'] = (string)$this->config->get('shipping_nova_poshta_sender_warehouse_ref');
		$data['shipping_nova_poshta_sender_warehouse_name']= (string)$this->config->get('shipping_nova_poshta_sender_warehouse_name');
		$data['shipping_nova_poshta_sender_counterparty_ref']  = (string)$this->config->get('shipping_nova_poshta_sender_counterparty_ref');
		$data['shipping_nova_poshta_sender_counterparty_name'] = (string)$this->config->get('shipping_nova_poshta_sender_counterparty_name');
		$data['shipping_nova_poshta_sender_contact_ref']       = (string)$this->config->get('shipping_nova_poshta_sender_contact_ref');
		$data['shipping_nova_poshta_sender_contact_name']      = (string)$this->config->get('shipping_nova_poshta_sender_contact_name');
		$data['shipping_nova_poshta_sender_phone']             = (string)$this->config->get('shipping_nova_poshta_sender_phone');
		$data['shipping_nova_poshta_auto_ttn_status_id']   = (int)$this->config->get('shipping_nova_poshta_auto_ttn_status_id');
		$data['shipping_nova_poshta_license_key']          = (string)$this->config->get('shipping_nova_poshta_license_key');
		$data['shipping_nova_poshta_license_status']       = \Opencart\System\Library\NovaPoshta\License::describe($this->config);
		$data['shipping_nova_poshta_license_is_pro']       = \Opencart\System\Library\NovaPoshta\License::isPro($this->config);

		$this->load->model('localisation/tax_class');
		$data['tax_classes'] = $this->model_localisation_tax_class->getTaxClasses();

		$this->load->model('localisation/geo_zone');
		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

		$this->load->model('localisation/order_status');
		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

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
			$post = $this->request->post;
			// Encrypt API key at rest if user is changing it (non-empty incoming
			// → store encrypted). An empty / missing api_key field means "don't
			// touch what's already saved" — important because OC's editSetting()
			// is replace-all and would wipe the stored key otherwise.
			$this->load->model('setting/setting');
			$current = $this->model_setting_setting->getSetting('shipping_nova_poshta');
			if (!is_array($current)) { $current = []; }

			if (!isset($post['shipping_nova_poshta_api_key']) || $post['shipping_nova_poshta_api_key'] === '') {
				unset($post['shipping_nova_poshta_api_key']);
			} else {
				$post['shipping_nova_poshta_api_key'] = \Opencart\System\Library\NovaPoshta\Crypto::encrypt(trim($post['shipping_nova_poshta_api_key']));
			}

			// Merge so unposted keys (api_key, license_*, sender_*, etc.) keep
			// their current value instead of being wiped by replace-all.
			$merged = array_merge($current, $post);
			$this->model_setting_setting->editSetting('shipping_nova_poshta', $merged);
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
			$key = trim((string)($this->request->post['shipping_nova_poshta_api_key'] ?? ''));
			if ($key === '') {
				$key = $this->apiKey();
			}
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
			if ($query === '') {
				$json['error'] = $this->language->get('error_query_empty');
			} else {
				$json = ['cities' => \Opencart\System\Library\NovaPoshta\Cache::searchCities($this->db, $query, $this->apiKey())];
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
			if ($cityRef === '') {
				$json['error'] = $this->language->get('error_city_empty');
			} else {
				$json = ['warehouses' => \Opencart\System\Library\NovaPoshta\Cache::getWarehouses($this->db, $cityRef, $this->apiKey())];
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
			$key       = $this->apiKey();
			$senderRef = (string)$this->config->get('shipping_nova_poshta_sender_city_ref');
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

	public function loadCounterparties(): void {
		$this->load->language('extension/nova_poshta_premium/shipping/nova_poshta');
		$json = ['counterparties' => []];
		if (!$this->user->hasPermission('modify', 'extension/nova_poshta_premium/shipping/nova_poshta')) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			$key = $this->apiKey();
			if ($key === '') {
				$json['error'] = $this->language->get('error_api_key_empty');
			} else {
				$client = new \Opencart\System\Library\NovaPoshta\Client($key);
				$resp = $client->getSenderCounterparties();
				if (!empty($resp['success']) && is_array($resp['data'] ?? null)) {
					foreach ($resp['data'] as $row) {
						$json['counterparties'][] = [
							'ref'  => (string)($row['Ref'] ?? ''),
							'name' => (string)($row['Description'] ?? ''),
							'type' => (string)($row['CounterpartyType'] ?? ''),
						];
					}
				} else {
					$json['error'] = (is_array($resp['errors'] ?? null) ? implode('; ', $resp['errors']) : 'Unknown error');
				}
			}
		}
		$this->jsonResponse($json);
	}

	public function loadContactPersons(): void {
		$this->load->language('extension/nova_poshta_premium/shipping/nova_poshta');
		$json = ['contacts' => []];
		if (!$this->user->hasPermission('modify', 'extension/nova_poshta_premium/shipping/nova_poshta')) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			$key = $this->apiKey();
			$cpRef = (string)($this->request->post['counterparty_ref'] ?? '');
			if ($key === '' || $cpRef === '') {
				$json['error'] = $this->language->get('error_api_key_empty');
			} else {
				$client = new \Opencart\System\Library\NovaPoshta\Client($key);
				$resp = $client->getContactPersons($cpRef);
				if (!empty($resp['success']) && is_array($resp['data'] ?? null)) {
					foreach ($resp['data'] as $row) {
						$json['contacts'][] = [
							'ref'   => (string)($row['Ref'] ?? ''),
							'name'  => (string)($row['Description'] ?? ''),
							'phone' => (string)($row['Phones'] ?? ''),
						];
					}
				} else {
					$json['error'] = (is_array($resp['errors'] ?? null) ? implode('; ', $resp['errors']) : 'Unknown error');
				}
			}
		}
		$this->jsonResponse($json);
	}

	/**
	 * Activate or re-verify the license key against the CatCode server.
	 * Called from the settings page when the merchant clicks
	 * "Activate / Re-check License".
	 */
	public function licenseCheck(): void {
		$this->load->language('extension/nova_poshta_premium/shipping/nova_poshta');
		$json = [];
		if (!$this->user->hasPermission('modify', 'extension/nova_poshta_premium/shipping/nova_poshta')) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			$key = trim((string)($this->request->post['shipping_nova_poshta_license_key'] ?? $this->config->get('shipping_nova_poshta_license_key')));
			if ($key === '') {
				$json['error'] = $this->language->get('error_license_empty');
			} else {
				$this->load->model('setting/setting');
				$storedKey = (string)$this->config->get('shipping_nova_poshta_license_key');
				// If the user submitted the same key we already have, treat as
				// re-verify (no extra activation slot). Otherwise this is a
				// fresh activate (may consume a slot).
				$result = ($storedKey !== '' && $storedKey === $key)
					? \Opencart\System\Library\NovaPoshta\License::verify($this->config, $this->model_setting_setting)
					: \Opencart\System\Library\NovaPoshta\License::activate($this->config, $this->model_setting_setting, $key);

				if (!empty($result['ok'])) {
					$tpl = $this->language->get('text_license_ok');
					$json['success'] = sprintf($tpl, (string)($result['product_name'] ?? ''));
				} else {
					$err = (string)($result['error'] ?? 'unknown');
					// Map server error codes to localized messages where we have them.
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

	/**
	 * Release the activation slot on the server AND clear local cache.
	 * Called when the merchant clicks "Deactivate License" — useful when
	 * moving a key to a different domain.
	 */
	public function licenseDeactivate(): void {
		$this->load->language('extension/nova_poshta_premium/shipping/nova_poshta');
		$json = [];
		if (!$this->user->hasPermission('modify', 'extension/nova_poshta_premium/shipping/nova_poshta')) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			$this->load->model('setting/setting');
			\Opencart\System\Library\NovaPoshta\License::deactivate($this->config, $this->model_setting_setting);
			$json['success'] = $this->language->get('text_license_deactivated');
		}
		$this->jsonResponse($json);
	}
}
