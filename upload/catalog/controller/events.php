<?php
namespace Opencart\Catalog\Controller\Extension\NovaPoshtaPremium;

require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/client.php';
require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/crypto.php';
require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/license.php';
require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/cache.php';
require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/zones.php';
require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/selection.php';
require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/ttn.php';

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
		// Stock checkout and CatCode One Page Checkout (its own route) alike.
		if (!in_array((string)($this->request->get['route'] ?? ''), ['checkout/checkout', 'extension/cc_onepage/checkout'], true)) {
			return;
		}
		$this->ensureEditEvents();
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
	 * catalog/model/checkout/order.addOrder/after — $output is the new order_id.
	 */
	public function orderAdded(string &$route, array &$args, mixed &$output): void {
		$this->syncOrder((int)($output ?? 0));
	}

	/**
	 * catalog/model/checkout/order.editOrder/after — args[0] is the order_id.
	 *
	 * OpenCart 4 creates the order when the confirm block first renders and then
	 * REWRITES it (editOrder) from the session on every later confirm refresh —
	 * after a payment change, a register.save of the hidden native form, etc.
	 * That rewrite put back the hidden form's seed zone (the first zone of the
	 * country, «Автономна Республіка Крим») for a Kyiv branch, so the address
	 * must be stamped after each edit too, not only on create.
	 */
	public function orderEdited(string &$route, array &$args, mixed &$output): void {
		$this->syncOrder((int)($args[0] ?? 0));
	}

	/**
	 * Writes the chosen Nova Poshta destination onto the order row and keeps a
	 * draft np_shipment row for it. Idempotent: safe after addOrder and after
	 * every editOrder.
	 */
	private function syncOrder(int $order_id): void {
		if ($order_id <= 0) {
			return;
		}
		// Only act on orders actually shipped by this carrier — a stale NP
		// selection in the session must not attach drafts to Ukrposhta orders.
		\Opencart\System\Library\NovaPoshta\Selection::restore($this->session);
		$method = (string)($this->session->data['shipping_method']['code'] ?? '');
		if (strpos($method, 'nova_poshta.') !== 0) {
			// The customer switched carriers after an earlier NP pick: drop the
			// untouched draft this order got then (never one with a waybill).
			$this->db->query("DELETE FROM `" . DB_PREFIX . "np_shipment` WHERE order_id = " . $order_id . " AND status_code = 0 AND (int_doc_number IS NULL OR int_doc_number = '')");
			return;
		}
		$cityRef = (string)($this->session->data['np_recipient_city_ref'] ?? '');
		$whRef   = (string)($this->session->data['np_recipient_warehouse_ref'] ?? '');
		$whName  = (string)($this->session->data['np_recipient_warehouse_name'] ?? '');
		$cityName= (string)($this->session->data['np_recipient_city_name'] ?? '');
		if ($cityRef === '' && $whRef === '') {
			return; // Nothing picked yet — the next editOrder will carry it.
		}
		// The order address must carry OUR data: the NP classifier city, the
		// oblast resolved on the server from the module's own city directory
		// (never the hidden form's seed) and the picked branch; NP branches have
		// no postal code, so the index is emptied — never a '00000' placeholder
		// that the hidden native form may have frozen into the order.
		$area = \Opencart\System\Library\NovaPoshta\Cache::cityArea($this->db, $cityRef);
		if ($area === '') {
			$area = (string)($this->session->data['np_recipient_city_area'] ?? '');
		}
		$row       = $this->db->query("SELECT shipping_country_id FROM `" . DB_PREFIX . "order` WHERE order_id = " . $order_id)->row;
		$countryId = (int)($row['shipping_country_id'] ?? 0);
		$zone      = \Opencart\System\Library\NovaPoshta\Zones::resolve($this->db, $area, $cityName, (int)$this->config->get('config_language_id'), $countryId);

		$sets = ["shipping_postcode = ''"];
		if ($cityName !== '') {
			$sets[] = "shipping_city = '" . $this->db->escape($cityName) . "'";
		}
		if ($whName !== '') {
			$sets[] = "shipping_address_1 = '" . $this->db->escape($whName) . "'";
		}
		if ($zone) {
			$sets[] = "shipping_zone_id = " . (int)$zone['zone_id'];
			$sets[] = "shipping_zone = '" . $this->db->escape((string)$zone['name']) . "'";
		} else {
			// No unambiguous store zone: no zone id at all, and the NP oblast as
			// plain text (or nothing) — never a random first zone of the country.
			$sets[] = "shipping_zone_id = 0";
			$sets[] = "shipping_zone = '" . $this->db->escape($area) . "'";
		}
		$this->db->query("UPDATE `" . DB_PREFIX . "order` SET " . implode(', ', $sets) . " WHERE order_id = " . $order_id);

		$senderCity = (string)$this->config->get('shipping_nova_poshta_sender_city_ref');
		$senderWh   = (string)$this->config->get('shipping_nova_poshta_sender_warehouse_ref');
		$existing   = $this->db->query("SELECT shipment_id, int_doc_number FROM `" . DB_PREFIX . "np_shipment` WHERE order_id = " . $order_id . " ORDER BY shipment_id DESC LIMIT 1")->row;
		if ($existing) {
			if ((string)($existing['int_doc_number'] ?? '') === '') {
				$this->db->query("UPDATE `" . DB_PREFIX . "np_shipment` SET
					recipient_city_ref = '" . $this->db->escape($cityRef) . "',
					recipient_warehouse_ref = '" . $this->db->escape($whRef) . "',
					recipient_name = '" . $this->db->escape($cityName . ' / ' . $whName) . "'
					WHERE shipment_id = " . (int)$existing['shipment_id']);
			}
			return;
		}
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
	 * The editOrder hook arrived in 1.2.22. A store that updates the files
	 * without pressing «Setup» in the module settings would never get it, so
	 * register it here once (checkout page only; one indexed lookup).
	 */
	private function ensureEditEvents(): void {
		$triggers = [
			'nova_poshta_premium_order_edited'       => 'catalog/model/checkout/order*editOrder/after',
			'nova_poshta_premium_order_edited_slash' => 'catalog/model/checkout/order/editOrder/after',
		];
		try {
			$have = [];
			foreach ($this->db->query("SELECT code FROM `" . DB_PREFIX . "event` WHERE code IN ('" . implode("','", array_keys($triggers)) . "')")->rows as $r) {
				$have[(string)$r['code']] = true;
			}
			foreach ($triggers as $code => $trigger) {
				if (isset($have[$code])) {
					continue;
				}
				$this->db->query("INSERT INTO `" . DB_PREFIX . "event` SET code = '" . $this->db->escape($code) . "', description = 'Nova Poshta Premium — stamp the NP address and oblast after the order is rewritten', `trigger` = '" . $this->db->escape($trigger) . "', action = 'extension/nova_poshta_premium/events.orderEdited', status = 1, sort_order = 10");
			}
		} catch (\Throwable $e) {
			// Never break the storefront over a self-heal.
		}
	}

	/**
	 * On order status change — if status matches the configured auto-TTN trigger,
	 * create the TTN. For cash on delivery this runs inside the buyer's own
	 * «Confirm order» request, so it must never wait on a dead API: Client gives
	 * up after a few seconds (or at once while the breaker is open) and the
	 * shipment goes to the retry queue instead of failing the waybill for good.
	 */
	public function orderHistoryAdded(string &$route, array &$args, mixed &$output): void {
		$order_id  = (int)($args[0] ?? 0);
		$order_status_id = (int)($args[1] ?? 0);
		$trigger   = (int)$this->config->get('shipping_nova_poshta_auto_ttn_status_id');
		if ($order_id <= 0 || $trigger <= 0 || $order_status_id !== $trigger) {
			return;
		}
		// Both triggers (4.1 dot / 4.0.2 slash) are registered, and on 4.0.2.x the
		// wildcard one matches too: one status change, two calls. Measured on
		// 4.0.2.3 with the API hanging — the buyer waited for two TTN attempts.
		static $done = [];
		if (isset($done[$order_id . ':' . $order_status_id])) {
			return;
		}
		$done[$order_id . ':' . $order_status_id] = true;
		$row = $this->db->query("SELECT * FROM `" . DB_PREFIX . "np_shipment` WHERE order_id = " . $order_id . " AND (int_doc_number IS NULL OR int_doc_number = '') LIMIT 1")->row;
		if (!$row) {
			return;
		}
		try {
			\Opencart\System\Library\NovaPoshta\Ttn::create($this->db, $this->config, $row, true);
		} catch (\Throwable $e) {
			// The order is placed; a waybill problem must not turn the buyer's
			// confirmation into an error page.
			$this->db->query("UPDATE `" . DB_PREFIX . "np_shipment` SET status_text = '" . $this->db->escape(self::cut('Помилка ТТН: ' . $e->getMessage(), 250)) . "', last_polled_at = NOW() WHERE shipment_id = " . (int)$row['shipment_id']);
		}
	}
}
