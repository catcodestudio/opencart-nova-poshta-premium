<?php
namespace Opencart\Catalog\Controller\Extension\NovaPoshtaPremium;

require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/client.php';
require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/crypto.php';

class Events extends \Opencart\System\Engine\Controller {
	/**
	 * Injects the checkout picker widget on every storefront page footer.
	 * The widget self-activates only when it detects a checkout shipping step.
	 */
	public function footerInject(string &$route, array &$args, mixed &$output): void {
		if (!is_string($output) || stripos($output, '</body>') === false) {
			return;
		}
		$baseUrl = defined('HTTPS_SERVER') ? HTTPS_SERVER : HTTP_SERVER;
		$endpoints = [
			'searchCities'  => $baseUrl . 'index.php?route=extension/nova_poshta_premium/checkout.searchCities',
			'getWarehouses' => $baseUrl . 'index.php?route=extension/nova_poshta_premium/checkout.getWarehouses',
			'setSelection'  => $baseUrl . 'index.php?route=extension/nova_poshta_premium/checkout.setSelection',
			'getSelection'  => $baseUrl . 'index.php?route=extension/nova_poshta_premium/checkout.getSelection',
		];
		// JSON flags make it safe to drop directly into a <script> body without
		// breaking out of the tag or being mangled by HTML entity encoding.
		$jsonEndpoints = json_encode($endpoints, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
		$pickerJs = $this->pickerScript();
		$tag = '<script>window.__novaPoshtaPremium=' . $jsonEndpoints . ';' . $pickerJs . '</script>';
		$output = str_replace('</body>', $tag . '</body>', $output);
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
			status_text = 'Draft',
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
		// MVP: we do not yet collect sender Counterparty refs in admin settings,
		// so a real InternetDocument.save will fail without them. We record the
		// intent and surface it in the dashboard; full TTN automation is wired
		// once Sender Counterparty config lands in v1.1.
		$this->db->query("UPDATE `" . DB_PREFIX . "np_shipment` SET status_text = 'Awaiting sender counterparty config', last_polled_at = NOW() WHERE shipment_id = " . (int)$row['shipment_id']);
	}
}
