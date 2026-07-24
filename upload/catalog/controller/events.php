<?php
namespace Opencart\Catalog\Controller\Extension\NovaPoshtaPremium;

require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/client.php';
require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/crypto.php';
require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/license.php';

class Events extends \Opencart\System\Engine\Controller {
	/** UTF-8 safe truncate that survives hosts without mbstring. */
	private static function cut(string $s, int $len): string {
		if (function_exists('mb_substr')) {
			return mb_substr($s, 0, $len);
		}
		return preg_match('/^.{0,' . $len . '}/us', $s, $m) ? $m[0] : substr($s, 0, $len);
	}

	/**
	 * Injects the checkout picker widget on every storefront page footer.
	 * The widget self-activates only when it detects a checkout shipping step.
	 */
	public function footerInject(string &$route, array &$args, mixed &$output): void {
		if (!is_string($output) || stripos($output, '</body>') === false) {
			return;
		}
		$this->seedShippingAddress();
		$baseUrl = defined('HTTPS_SERVER') ? HTTPS_SERVER : HTTP_SERVER;
		$accent = (string)($this->config->get('shipping_nova_poshta_accent_color') ?: '#da291c');
		if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
			$accent = '#da291c';
		}
		// Appearance controls so the merchant can match the widget to their theme.
		$radius = (int)$this->config->get('shipping_nova_poshta_radius');
		if ($radius < 0 || $radius > 28) {
			$radius = 14;
		}
		$theme = (string)$this->config->get('shipping_nova_poshta_theme');
		if (!in_array($theme, ['auto', 'light', 'dark'], true)) {
			$theme = 'auto';
		}
		$endpoints = [
			'searchCities'  => $baseUrl . 'index.php?route=extension/nova_poshta_premium/checkout.searchCities',
			'getWarehouses' => $baseUrl . 'index.php?route=extension/nova_poshta_premium/checkout.getWarehouses',
			'setSelection'  => $baseUrl . 'index.php?route=extension/nova_poshta_premium/checkout.setSelection',
			'getSelection'  => $baseUrl . 'index.php?route=extension/nova_poshta_premium/checkout.getSelection',
			'accentColor'   => $accent,
			'radius'        => $radius,
			'theme'         => $theme,
		];
		// JSON flags make it safe to drop directly into a <script> body without
		// breaking out of the tag or being mangled by HTML entity encoding.
		$jsonEndpoints = json_encode($endpoints, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
		$pickerJs = $this->pickerScript();
		$tag = '<script>window.__novaPoshtaPremium=' . $jsonEndpoints . ';' . $pickerJs . '</script>';
		$output = str_replace('</body>', $tag . '</body>', $output);
	}

	/**
	 * Fresh guest session on the checkout page: the core refuses to quote
	 * shipping methods until session['shipping_address'] exists, but the manual
	 * address form is replaced by the carrier picker — so the customer hits a
	 * dead «Потрібна адреса доставки!» before doing anything. Seed a minimal
	 * Ukraine address once (address_id=0, no zone) so the method list opens in
	 * the natural order; the picker/setSelection then writes the real address.
	 */
	private function seedShippingAddress(): void {
		if (((string)($this->request->get['route'] ?? '')) !== 'checkout/checkout') {
			return;
		}
		if (isset($this->session->data['shipping_address']['address_id'])) {
			return;
		}
		$this->load->model('localisation/country');
		$info = $this->model_localisation_country->getCountry((int)$this->config->get('config_country_id'));
		if (!$info || strtoupper((string)($info['iso_code_2'] ?? '')) !== 'UA') {
			$row = $this->db->query("SELECT country_id FROM `" . DB_PREFIX . "country` WHERE iso_code_2 = 'UA' AND status = 1")->row;
			if ($row) {
				$info = $this->model_localisation_country->getCountry((int)$row['country_id']);
			}
		}
		if (!$info) {
			return;
		}
		// The same core gate also demands session['customer'] before quoting.
		// Seed an empty guest stub (customer_id=0) — register.save overwrites it
		// with the real contact data. The picker JS keeps the confirm button
		// gated until that save happens, so no anonymous order can slip through.
		if (!isset($this->session->data['customer'])) {
			$this->session->data['customer'] = [
				'customer_id'       => 0,
				'customer_group_id' => (int)$this->config->get('config_customer_group_id'),
				'firstname'         => '',
				'lastname'          => '',
				'email'             => '',
				'telephone'         => '',
				'custom_field'      => [],
			];
		}
		$this->session->data['shipping_address'] = [
			'address_id'     => 0,
			'firstname'      => '',
			'lastname'       => '',
			'company'        => '',
			'address_1'      => '',
			'address_2'      => '',
			'city'           => '',
			'postcode'       => '',
			'zone_id'        => 0,
			'zone'           => '',
			'zone_code'      => '',
			'country_id'     => (int)$info['country_id'],
			'country'        => (string)($info['name'] ?? 'Ukraine'),
			'iso_code_2'     => (string)($info['iso_code_2'] ?? 'UA'),
			'iso_code_3'     => (string)($info['iso_code_3'] ?? 'UKR'),
			'address_format' => (string)($info['address_format'] ?? ''),
			'custom_field'   => [],
		];
	}

	private function pickerScript(): string {
		$file = DIR_EXTENSION . 'nova_poshta_premium/catalog/view/javascript/nova_poshta_premium/picker.js';
		if (!is_file($file)) {
			return '';
		}
		return (string)file_get_contents($file);
	}

	/**
	 * On order create — capture chosen NP city/warehouse refs from session
	 * and write a draft np_shipment row keyed by order_id.
	 */
	public function orderAdded(string &$route, array &$args, mixed &$output): void {
		$order_id = (int)($output ?? 0);
		if ($order_id <= 0) {
			return;
		}
		$cityRef = (string)($this->session->data['np_recipient_city_ref'] ?? '');
		$whRef   = (string)($this->session->data['np_recipient_warehouse_ref'] ?? '');
		$whName  = (string)($this->session->data['np_recipient_warehouse_name'] ?? '');
		$cityName= (string)($this->session->data['np_recipient_city_name'] ?? '');
		if ($cityRef === '' && $whRef === '') {
			return; // Customer used a different shipping method.
		}
		$senderCity = (string)$this->config->get('shipping_nova_poshta_sender_city_ref');
		$senderWh   = (string)$this->config->get('shipping_nova_poshta_sender_warehouse_ref');
		$this->db->query("INSERT INTO `" . DB_PREFIX . "np_shipment` SET
			order_id = " . $order_id . ",
			sender_city_ref = '" . $this->db->escape($senderCity) . "',
			sender_warehouse_ref = '" . $this->db->escape($senderWh) . "',
			recipient_city_ref = '" . $this->db->escape($cityRef) . "',
			recipient_warehouse_ref = '" . $this->db->escape($whRef) . "',
			recipient_name = '" . $this->db->escape($cityName . ' / ' . $whName) . "',
			service_type = 'WarehouseWarehouse',
			status_code = 0,
			status_text = 'Чернетка',
			created_at = NOW()");
	}

	/**
	 * On order status change — if status matches the configured auto-TTN trigger,
	 * attempt to create a TTN via NP API.
	 */
	public function orderHistoryAdded(string &$route, array &$args, mixed &$output): void {
		$order_id  = (int)($args[0] ?? 0);
		$order_status_id = (int)($args[1] ?? 0);
		$trigger   = (int)$this->config->get('shipping_nova_poshta_auto_ttn_status_id');
		if ($order_id <= 0 || $trigger <= 0 || $order_status_id !== $trigger) {
			return;
		}
		$row = $this->db->query("SELECT * FROM `" . DB_PREFIX . "np_shipment` WHERE order_id = " . $order_id . " AND (int_doc_number IS NULL OR int_doc_number = '') LIMIT 1")->row;
		if (!$row) {
			return;
		}
		$key = \Opencart\System\Library\NovaPoshta\Crypto::decrypt((string)$this->config->get('shipping_nova_poshta_api_key'));
		if ($key === '') {
			return;
		}
		$senderRef       = (string)$this->config->get('shipping_nova_poshta_sender_counterparty_ref');
		$senderContact   = (string)$this->config->get('shipping_nova_poshta_sender_contact_ref');
		$senderPhone     = (string)$this->config->get('shipping_nova_poshta_sender_phone');
		$senderCityRef   = (string)$this->config->get('shipping_nova_poshta_sender_city_ref');
		$senderWhRef     = (string)$this->config->get('shipping_nova_poshta_sender_warehouse_ref');
		if ($senderRef === '' || $senderContact === '' || $senderPhone === '' || $senderCityRef === '' || $senderWhRef === '') {
			$this->db->query("UPDATE `" . DB_PREFIX . "np_shipment` SET status_text = 'Не налаштовано відправника', last_polled_at = NOW() WHERE shipment_id = " . (int)$row['shipment_id']);
			return;
		}

		// Fetch order details for recipient name/phone + weight/value.
		$this->load->model('checkout/order');
		$order = $this->model_checkout_order->getOrder($order_id);
		if (!$order) {
			return;
		}
		$products = $this->db->query("SELECT op.name, op.quantity, op.price, COALESCE(p.weight, 0) AS weight FROM `" . DB_PREFIX . "order_product` op LEFT JOIN `" . DB_PREFIX . "product` p ON p.product_id = op.product_id WHERE op.order_id = " . $order_id)->rows;
		$weight = 0.0;
		$cost   = 0.0;
		$descParts = [];
		foreach ($products as $p) {
			$weight += (float)$p['weight'] * (int)$p['quantity'];
			$cost   += (float)$p['price']  * (int)$p['quantity'];
			$descParts[] = trim((string)$p['name']);
		}
		if ($weight <= 0) $weight = 0.5;
		if ($cost   <= 0) $cost   = (float)($order['total'] ?? 100);

		// COD attachment is a Pro feature — without a valid license we still
		// mint the TTN (core value), we just don't request cash-on-delivery
		// from Nova Poshta. Merchant can convert manually in NP cabinet if
		// they prefer that workflow without a license.
		$isPro = \Opencart\System\Library\NovaPoshta\License::isPro($this->config);
		$codAmount = ($isPro && (string)($order['payment_code'] ?? '') === 'cod') ? $cost : 0.0;
		$client = new \Opencart\System\Library\NovaPoshta\Client($key);
		$resp = $client->createTTN([
			'sender_ref'             => $senderRef,
			'sender_contact_ref'     => $senderContact,
			'sender_phone'           => $senderPhone,
			'sender_city_ref'        => $senderCityRef,
			'sender_warehouse_ref'   => $senderWhRef,
			'recipient_city_ref'     => (string)$row['recipient_city_ref'],
			'recipient_warehouse_ref'=> (string)$row['recipient_warehouse_ref'],
			'recipient_name'         => trim(($order['shipping_firstname'] ?? '') . ' ' . ($order['shipping_lastname'] ?? '')) ?: ($order['firstname'] . ' ' . $order['lastname']),
			'recipient_phone'        => (string)($order['telephone'] ?? ''),
			'weight'                 => $weight,
			'cost'                   => $cost,
			'cod_amount'             => $codAmount,
			'description'            => self::cut(implode(', ', $descParts) ?: 'Order #' . $order_id, 200),
			'service_type'           => 'WarehouseWarehouse',
			'payer_type'             => 'Recipient',
			'payment_method'         => 'Cash',
			'cargo_type'             => 'Cargo',
			'seats_amount'           => 1,
		]);

		if (!empty($resp['success']) && !empty($resp['data'][0]['IntDocNumber'])) {
			$intDoc = (string)$resp['data'][0]['IntDocNumber'];
			$intRef = (string)($resp['data'][0]['Ref'] ?? '');
			$this->db->query("UPDATE `" . DB_PREFIX . "np_shipment` SET int_doc_number = '" . $this->db->escape($intDoc) . "', int_doc_ref = '" . $this->db->escape($intRef) . "', status_code = 1, status_text = 'Створено', weight = " . (float)$weight . ", declared_cost = " . (float)$cost . ", cod_amount = " . (float)$codAmount . ", recipient_phone = '" . $this->db->escape((string)$order['telephone']) . "', last_polled_at = NOW() WHERE shipment_id = " . (int)$row['shipment_id']);
		} else {
			$err = is_array($resp['errors'] ?? null) ? implode('; ', $resp['errors']) : 'unknown error';
			$this->db->query("UPDATE `" . DB_PREFIX . "np_shipment` SET status_text = '" . $this->db->escape(self::cut('Помилка ТТН: ' . $err, 250)) . "', last_polled_at = NOW() WHERE shipment_id = " . (int)$row['shipment_id']);
		}
	}
}
