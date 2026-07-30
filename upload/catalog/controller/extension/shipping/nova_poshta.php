<?php
require_once DIR_SYSTEM . 'library/novaposhta/client.php';
require_once DIR_SYSTEM . 'library/novaposhta/crypto.php';
require_once DIR_SYSTEM . 'library/novaposhta/cache.php';
require_once DIR_SYSTEM . 'library/novaposhta/license.php';

/**
 * Nova Poshta Premium — storefront controller (OpenCart 3.0.x).
 * Hosts: checkout picker AJAX, event handlers (footer inject + order hooks),
 * and cron endpoints (status poll, webhooks, license, city/COD sync).
 */
class ControllerExtensionShippingNovaPoshta extends Controller {
	/** UTF-8 safe truncate that survives hosts without mbstring. */
	private static function cut($s, $len) {
		if (function_exists('mb_substr')) {
			return mb_substr($s, 0, $len);
		}
		return preg_match('/^.{0,' . $len . '}/us', $s, $m) ? $m[0] : substr($s, 0, $len);
	}

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

	/* ---------- Checkout picker AJAX ---------- */

	public function searchCities() {
		$query = trim((string)(isset($this->request->post['q']) ? $this->request->post['q'] : (isset($this->request->get['q']) ? $this->request->get['q'] : '')));
		if ($query === '') {
			$this->jsonResponse(array('cities' => array()));
			return;
		}
		$this->jsonResponse(array('cities' => NpCache::searchCities($this->db, $query, $this->apiKey())));
	}

	public function getWarehouses() {
		$cityRef = trim((string)(isset($this->request->post['city_ref']) ? $this->request->post['city_ref'] : (isset($this->request->get['city_ref']) ? $this->request->get['city_ref'] : '')));
		if ($cityRef === '') {
			$this->jsonResponse(array('warehouses' => array()));
			return;
		}
		$this->jsonResponse(array('warehouses' => NpCache::getWarehouses($this->db, $cityRef, $this->apiKey())));
	}

	public function setSelection() {
		$this->session->data['np_recipient_city_ref']       = trim((string)(isset($this->request->post['city_ref']) ? $this->request->post['city_ref'] : ''));
		$this->session->data['np_recipient_city_name']      = trim((string)(isset($this->request->post['city_name']) ? $this->request->post['city_name'] : ''));
		$this->session->data['np_recipient_warehouse_ref']  = trim((string)(isset($this->request->post['warehouse_ref']) ? $this->request->post['warehouse_ref'] : ''));
		$this->session->data['np_recipient_warehouse_name'] = trim((string)(isset($this->request->post['warehouse_name']) ? $this->request->post['warehouse_name'] : ''));
		$this->jsonResponse(array('ok' => true));
	}

	public function getSelection() {
		$this->jsonResponse(array(
			'city_ref'       => (string)(isset($this->session->data['np_recipient_city_ref']) ? $this->session->data['np_recipient_city_ref'] : ''),
			'city_name'      => (string)(isset($this->session->data['np_recipient_city_name']) ? $this->session->data['np_recipient_city_name'] : ''),
			'warehouse_ref'  => (string)(isset($this->session->data['np_recipient_warehouse_ref']) ? $this->session->data['np_recipient_warehouse_ref'] : ''),
			'warehouse_name' => (string)(isset($this->session->data['np_recipient_warehouse_name']) ? $this->session->data['np_recipient_warehouse_name'] : ''),
		));
	}

	/* ---------- Events ---------- */

	/**
	 * controller/checkout/{guest,register,payment_address,shipping_address}/save/before
	 *
	 * The customer picks a Nova Poshta branch in our widget, which lives in the
	 * delivery step — i.e. *after* OpenCart's address step has already demanded a
	 * street address. Merchants work around that by hiding the native address
	 * fields, which leaves the required Address 1 empty: the step then fails
	 * validation against an invisible field and the checkout silently refuses to
	 * advance. Supply a sane value for the hidden fields so that never happens —
	 * the real destination (branch or, for pickup, the store) is written onto the
	 * order by addOrderBefore() once the delivery method is known.
	 */
	public function addressPresave(&$route, &$args) {
		if (!isset($this->request->post['address_1'])) {
			return;
		}
		if (trim((string)$this->request->post['address_1']) !== '') {
			return;
		}
		$this->request->post['address_1'] = $this->fallbackAddress();

		// Only fill a blank postcode; countries that really require one still get
		// the customer's own value whenever the field is visible and filled in.
		if (isset($this->request->post['postcode']) && trim((string)$this->request->post['postcode']) === '') {
			$this->request->post['postcode'] = '00000';
		}
	}

	/**
	 * The typed city, else a neutral placeholder.
	 *
	 * Deliberately NOT the Nova Poshta branch from the session: the address step
	 * runs before the delivery method is chosen, so a branch left over from an
	 * earlier pick would end up on a pickup or courier order. addOrderBefore()
	 * stamps the branch once the method is known to be ours.
	 */
	private function fallbackAddress() {
		$city = trim((string)(isset($this->request->post['city']) ? $this->request->post['city'] : ''));
		// OpenCart requires 3..128 chars, so a 1-2 letter city cannot stand alone.
		if ($city !== '' && strlen($city) >= 3) {
			return self::cut($city, 128);
		}
		return 'Уточнюється';
	}

	/**
	 * Is this order actually shipping with us? Reads the order data OpenCart is
	 * about to persist, falling back to the session's chosen method so both the
	 * before- and after-addOrder hooks agree.
	 */
	private function isOurShipping($order_data) {
		$code = '';
		if (is_array($order_data) && isset($order_data['shipping_code'])) {
			$code = (string)$order_data['shipping_code'];
		}
		if ($code === '' && isset($this->session->data['shipping_method']['code'])) {
			$code = (string)$this->session->data['shipping_method']['code'];
		}
		// No method at all (e.g. a downloadable-only order): not ours.
		if ($code === '') {
			return false;
		}
		return strpos($code, 'nova_poshta') === 0;
	}

	/**
	 * catalog/model/checkout/order/addOrder/before — args[0] is the order data.
	 * Stamp the confirmed Nova Poshta destination onto the order address so the
	 * merchant sees "City — Branch" instead of the placeholder above.
	 */
	public function addOrderBefore(&$route, &$args) {
		if (!isset($args[0]) || !is_array($args[0])) {
			return;
		}
		$city = trim((string)(isset($this->session->data['np_recipient_city_name']) ? $this->session->data['np_recipient_city_name'] : ''));
		$wh   = trim((string)(isset($this->session->data['np_recipient_warehouse_name']) ? $this->session->data['np_recipient_warehouse_name'] : ''));
		if ($wh === '') {
			return;
		}
		// Never stamp a branch onto a pickup/courier order.
		if (!$this->isOurShipping($args[0])) {
			return;
		}
		$args[0]['shipping_address_1'] = self::cut($wh, 128);
		if ($city !== '') {
			$args[0]['shipping_city'] = self::cut($city, 128);
		}
	}

	/** view/common/footer/after — inject picker widget before </body>. */
	public function footerInject(&$route, &$data, &$output) {
		if (!is_string($output) || stripos($output, '</body>') === false) {
			return;
		}
		$baseUrl = defined('HTTPS_SERVER') ? HTTPS_SERVER : HTTP_SERVER;

		$accent = (string)($this->config->get('shipping_nova_poshta_accent_color') ?: '#da291c');
		if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
			$accent = '#da291c';
		}
		$radius = (int)$this->config->get('shipping_nova_poshta_radius');
		if ($radius < 0 || $radius > 28) {
			$radius = 14;
		}
		$theme = (string)$this->config->get('shipping_nova_poshta_theme');
		if (!in_array($theme, array('auto', 'light', 'dark'), true)) {
			$theme = 'auto';
		}

		$endpoints = array(
			'searchCities'  => $baseUrl . 'index.php?route=extension/shipping/nova_poshta/searchCities',
			'getWarehouses' => $baseUrl . 'index.php?route=extension/shipping/nova_poshta/getWarehouses',
			'setSelection'  => $baseUrl . 'index.php?route=extension/shipping/nova_poshta/setSelection',
			'getSelection'  => $baseUrl . 'index.php?route=extension/shipping/nova_poshta/getSelection',
			'accentColor'   => $accent,
			'radius'        => $radius,
			'theme'         => $theme,
		);
		$jsonEndpoints = json_encode($endpoints, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
		$pickerJs = $this->pickerScript();
		$tag = '<script>window.__novaPoshtaPremium=' . $jsonEndpoints . ';' . $pickerJs . '</script>';
		$output = str_replace('</body>', $tag . '</body>', $output);
	}

	private function pickerScript() {
		$file = DIR_SYSTEM . 'library/novaposhta/picker.js';
		if (!is_file($file)) {
			return '';
		}
		return (string)file_get_contents($file);
	}

	/** catalog/model/checkout/order/addOrder/after — $output is new order_id. */
	public function orderAdded(&$route, &$args, &$output) {
		$order_id = (int)($output ? $output : 0);
		if ($order_id <= 0) {
			return;
		}
		// A shopper may pick a branch and then switch to pickup or a courier. The
		// session still holds that branch, so without this guard every such order
		// got a Nova Poshta draft shipment it must never have.
		if (!$this->isOurShipping(isset($args[0]) ? $args[0] : array())) {
			return;
		}
		$cityRef  = (string)(isset($this->session->data['np_recipient_city_ref']) ? $this->session->data['np_recipient_city_ref'] : '');
		$whRef    = (string)(isset($this->session->data['np_recipient_warehouse_ref']) ? $this->session->data['np_recipient_warehouse_ref'] : '');
		$whName   = (string)(isset($this->session->data['np_recipient_warehouse_name']) ? $this->session->data['np_recipient_warehouse_name'] : '');
		$cityName = (string)(isset($this->session->data['np_recipient_city_name']) ? $this->session->data['np_recipient_city_name'] : '');
		if ($cityRef === '' && $whRef === '') {
			return;
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

	/** catalog/model/checkout/order/addOrderHistory/after — args: order_id, order_status_id. */
	public function orderHistoryAdded(&$route, &$args, &$output) {
		$order_id        = (int)(isset($args[0]) ? $args[0] : 0);
		$order_status_id = (int)(isset($args[1]) ? $args[1] : 0);
		$trigger         = (int)$this->config->get('shipping_nova_poshta_auto_ttn_status_id');
		if ($order_id <= 0 || $trigger <= 0 || $order_status_id !== $trigger) {
			return;
		}
		$row = $this->db->query("SELECT * FROM `" . DB_PREFIX . "np_shipment` WHERE order_id = " . $order_id . " AND (int_doc_number IS NULL OR int_doc_number = '') LIMIT 1")->row;
		if (!$row) {
			return;
		}
		$key = NpCrypto::decrypt((string)$this->config->get('shipping_nova_poshta_api_key'));
		if ($key === '') {
			return;
		}
		$senderRef     = (string)$this->config->get('shipping_nova_poshta_sender_counterparty_ref');
		$senderContact = (string)$this->config->get('shipping_nova_poshta_sender_contact_ref');
		$senderPhone   = (string)$this->config->get('shipping_nova_poshta_sender_phone');
		$senderCityRef = (string)$this->config->get('shipping_nova_poshta_sender_city_ref');
		$senderWhRef   = (string)$this->config->get('shipping_nova_poshta_sender_warehouse_ref');
		if ($senderRef === '' || $senderContact === '' || $senderPhone === '' || $senderCityRef === '' || $senderWhRef === '') {
			$this->db->query("UPDATE `" . DB_PREFIX . "np_shipment` SET status_text = 'Не налаштовано відправника', last_polled_at = NOW() WHERE shipment_id = " . (int)$row['shipment_id']);
			return;
		}

		$this->load->model('checkout/order');
		$order = $this->model_checkout_order->getOrder($order_id);
		if (!$order) {
			return;
		}
		$products = $this->db->query("SELECT op.name, op.quantity, op.price, COALESCE(p.weight, 0) AS weight FROM `" . DB_PREFIX . "order_product` op LEFT JOIN `" . DB_PREFIX . "product` p ON p.product_id = op.product_id WHERE op.order_id = " . $order_id)->rows;
		$weight = 0.0;
		$cost   = 0.0;
		$descParts = array();
		foreach ($products as $p) {
			$weight += (float)$p['weight'] * (int)$p['quantity'];
			$cost   += (float)$p['price']  * (int)$p['quantity'];
			$descParts[] = trim((string)$p['name']);
		}
		if ($weight <= 0) $weight = 0.5;
		if ($cost   <= 0) $cost   = (float)(isset($order['total']) ? $order['total'] : 100);

		$isPro = NpLicense::isPro($this->config);
		$codAmount = ($isPro && (string)(isset($order['payment_code']) ? $order['payment_code'] : '') === 'cod') ? $cost : 0.0;
		$client = new NpClient($key);
		$resp = $client->createTTN(array(
			'sender_ref'              => $senderRef,
			'sender_contact_ref'      => $senderContact,
			'sender_phone'            => $senderPhone,
			'sender_city_ref'         => $senderCityRef,
			'sender_warehouse_ref'    => $senderWhRef,
			'recipient_city_ref'      => (string)$row['recipient_city_ref'],
			'recipient_warehouse_ref' => (string)$row['recipient_warehouse_ref'],
			'recipient_name'          => trim((isset($order['shipping_firstname']) ? $order['shipping_firstname'] : '') . ' ' . (isset($order['shipping_lastname']) ? $order['shipping_lastname'] : '')) ?: ($order['firstname'] . ' ' . $order['lastname']),
			'recipient_phone'         => (string)(isset($order['telephone']) ? $order['telephone'] : ''),
			'weight'                  => $weight,
			'cost'                    => $cost,
			'cod_amount'              => $codAmount,
			'description'             => self::cut(implode(', ', $descParts) ?: ('Order #' . $order_id), 200),
			'service_type'            => 'WarehouseWarehouse',
			'payer_type'              => 'Recipient',
			'payment_method'          => 'Cash',
			'cargo_type'              => 'Cargo',
			'seats_amount'            => 1,
		));

		if (!empty($resp['success']) && !empty($resp['data'][0]['IntDocNumber'])) {
			$intDoc = (string)$resp['data'][0]['IntDocNumber'];
			$intRef = (string)(isset($resp['data'][0]['Ref']) ? $resp['data'][0]['Ref'] : '');
			$this->db->query("UPDATE `" . DB_PREFIX . "np_shipment` SET int_doc_number = '" . $this->db->escape($intDoc) . "', int_doc_ref = '" . $this->db->escape($intRef) . "', status_code = 1, status_text = 'Створено', weight = " . (float)$weight . ", declared_cost = " . (float)$cost . ", cod_amount = " . (float)$codAmount . ", recipient_phone = '" . $this->db->escape((string)$order['telephone']) . "', last_polled_at = NOW() WHERE shipment_id = " . (int)$row['shipment_id']);
		} else {
			$err = is_array(isset($resp['errors']) ? $resp['errors'] : null) ? implode('; ', $resp['errors']) : 'unknown error';
			$this->db->query("UPDATE `" . DB_PREFIX . "np_shipment` SET status_text = '" . $this->db->escape(self::cut('Помилка ТТН: ' . $err, 250)) . "', last_polled_at = NOW() WHERE shipment_id = " . (int)$row['shipment_id']);
		}
	}

	/* ---------- Cron endpoints (add to system crontab) ---------- */

	public function pollStatus() {
		if (!NpLicense::isPro($this->config)) {
			return;
		}
		$key = NpCrypto::decrypt((string)$this->config->get('shipping_nova_poshta_api_key'));
		if ($key === '') {
			return;
		}
		$rows = $this->db->query("SELECT shipment_id, int_doc_number, status_code FROM `" . DB_PREFIX . "np_shipment` WHERE int_doc_number IS NOT NULL AND int_doc_number <> '' AND status_code < 9 LIMIT 100")->rows;
		if (!$rows) {
			return;
		}
		$client = new NpClient($key);
		$byNumber = array();
		foreach ($rows as $r) {
			$byNumber[$r['int_doc_number']] = $r;
		}
		$response = $client->trackStatus(array_keys($byNumber));
		if (empty($response['success']) || !is_array(isset($response['data']) ? $response['data'] : null)) {
			return;
		}
		foreach ($response['data'] as $doc) {
			$num = (string)(isset($doc['Number']) ? $doc['Number'] : '');
			if (!isset($byNumber[$num])) continue;
			$prev    = (int)$byNumber[$num]['status_code'];
			$newCode = (int)(isset($doc['StatusCode']) ? $doc['StatusCode'] : 0);
			$newText = (string)(isset($doc['Status']) ? $doc['Status'] : '');
			$shipId  = (int)$byNumber[$num]['shipment_id'];
			$this->db->query("UPDATE `" . DB_PREFIX . "np_shipment` SET status_code = " . $newCode . ", status_text = '" . $this->db->escape($newText) . "', last_polled_at = NOW() WHERE shipment_id = " . $shipId);
			if ($newCode !== $prev) {
				$this->db->query("INSERT INTO `" . DB_PREFIX . "np_shipment_history` SET shipment_id = " . $shipId . ", status_code = " . $newCode . ", status_text = '" . $this->db->escape($newText) . "', changed_at = NOW()");
				$this->queueWebhooks($shipId, 'status.changed', array(
					'shipment_id'     => $shipId,
					'int_doc_number'  => $num,
					'previous_status' => $prev,
					'current_status'  => $newCode,
					'status_text'     => $newText,
				));
			}
		}
	}

	private function queueWebhooks($shipmentId, $event, $payload) {
		$endpoints = $this->db->query("SELECT endpoint_id FROM `" . DB_PREFIX . "np_webhook_endpoint` WHERE status = 1 AND FIND_IN_SET('" . $this->db->escape($event) . "', events)")->rows;
		foreach ($endpoints as $ep) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "np_webhook_delivery` SET endpoint_id = " . (int)$ep['endpoint_id'] . ", shipment_id = " . (int)$shipmentId . ", event = '" . $this->db->escape($event) . "', payload = '" . $this->db->escape(json_encode($payload)) . "', status = 'queued', next_retry_at = NOW(), created_at = NOW()");
		}
	}

	public function dispatchWebhooks() {
		if (!NpLicense::isPro($this->config)) {
			return;
		}
		$rows = $this->db->query("SELECT d.*, e.url, e.secret FROM `" . DB_PREFIX . "np_webhook_delivery` d INNER JOIN `" . DB_PREFIX . "np_webhook_endpoint` e ON e.endpoint_id = d.endpoint_id WHERE d.status = 'queued' AND d.next_retry_at <= NOW() AND d.attempt <= 4 LIMIT 50")->rows;
		foreach ($rows as $r) {
			$body = (string)$r['payload'];
			$signature = hash_hmac('sha256', $body, (string)$r['secret']);
			$ch = curl_init((string)$r['url']);
			curl_setopt_array($ch, array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_POST           => true,
				CURLOPT_HTTPHEADER     => array(
					'Content-Type: application/json',
					'X-NP-Event: ' . (string)$r['event'],
					'X-NP-Signature: sha256=' . $signature,
				),
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_TIMEOUT        => 10,
			));
			$respBody = (string)curl_exec($ch);
			$code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$attempt  = (int)$r['attempt'];
			if ($code >= 200 && $code < 300) {
				$this->db->query("UPDATE `" . DB_PREFIX . "np_webhook_delivery` SET status = 'sent', response_code = " . $code . ", response_body = '" . $this->db->escape(substr($respBody, 0, 4000)) . "' WHERE delivery_id = " . (int)$r['delivery_id']);
			} else {
				$nextAttempt = $attempt + 1;
				$backoffMin = (int)pow(2, $attempt) * 5;
				if ($nextAttempt > 4) {
					$this->db->query("UPDATE `" . DB_PREFIX . "np_webhook_delivery` SET status = 'failed', attempt = " . $nextAttempt . ", response_code = " . $code . ", response_body = '" . $this->db->escape(substr($respBody, 0, 4000)) . "' WHERE delivery_id = " . (int)$r['delivery_id']);
				} else {
					$this->db->query("UPDATE `" . DB_PREFIX . "np_webhook_delivery` SET attempt = " . $nextAttempt . ", response_code = " . $code . ", response_body = '" . $this->db->escape(substr($respBody, 0, 4000)) . "', next_retry_at = DATE_ADD(NOW(), INTERVAL " . $backoffMin . " MINUTE) WHERE delivery_id = " . (int)$r['delivery_id']);
				}
			}
		}
	}

	public function licenseCheck() {
		$key = (string)$this->config->get('shipping_nova_poshta_license_key');
		if ($key === '') {
			return;
		}
		$this->load->model('setting/setting');
		NpLicense::verify($this->config, $this->model_setting_setting);
	}

	public function syncCities() {
		$key = NpCrypto::decrypt((string)$this->config->get('shipping_nova_poshta_api_key'));
		NpCache::syncCities($this->db, $key);
	}

	public function syncCod() {
		if (!NpLicense::isPro($this->config)) {
			return;
		}
		$key = NpCrypto::decrypt((string)$this->config->get('shipping_nova_poshta_api_key'));
		if ($key === '') return;
		$client = new NpClient($key);
		$dateFrom = date('d.m.Y', strtotime('-90 days'));
		$dateTo   = date('d.m.Y');
		for ($page = 1; $page <= 10; $page++) {
			$resp = $client->getOwnShipments($dateFrom, $dateTo, $page);
			if (empty($resp['success']) || empty($resp['data'])) break;
			foreach ($resp['data'] as $row) {
				$num = (string)(isset($row['IntDocNumber']) ? $row['IntDocNumber'] : '');
				$bds = (float)(isset($row['BackwardDeliverySum']) ? $row['BackwardDeliverySum'] : 0);
				$mtn = (string)(isset($row['MoneyTransferNumber']) ? $row['MoneyTransferNumber'] : '');
				if ($num === '') continue;
				$this->db->query("UPDATE `" . DB_PREFIX . "np_shipment` SET cod_amount = " . $bds . ", money_transfer_number = '" . $this->db->escape($mtn) . "' WHERE int_doc_number = '" . $this->db->escape($num) . "'");
			}
			if (count($resp['data']) < 100) break;
		}
	}
}
