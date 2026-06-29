<?php
require_once DIR_SYSTEM . 'library/novaposhta/client.php';
require_once DIR_SYSTEM . 'library/novaposhta/crypto.php';
require_once DIR_SYSTEM . 'library/novaposhta/license.php';

/**
 * Nova Poshta Premium — shipments dashboard (OpenCart 3.0.x).
 */
class ControllerExtensionShippingNpShipment extends Controller {
	private function jsonResponse($data) {
		if (ob_get_level() > 0) { ob_clean(); }
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));
	}

	private function apiKey() {
		$raw = (string)$this->config->get('shipping_nova_poshta_api_key');
		return $raw === '' ? '' : NpCrypto::decrypt($raw);
	}

	public function index() {
		$this->load->language('extension/shipping/np_shipment');
		$this->document->setTitle($this->language->get('heading_title'));

		$ut = $this->session->data['user_token'];

		$rows = $this->db->query("SELECT s.*, o.invoice_no FROM `" . DB_PREFIX . "np_shipment` s LEFT JOIN `" . DB_PREFIX . "order` o ON o.order_id = s.order_id ORDER BY s.shipment_id DESC LIMIT 200")->rows;

		$shipments = array();
		foreach ($rows as $r) {
			$shipments[] = array(
				'shipment_id'    => $r['shipment_id'],
				'order_id'       => $r['order_id'],
				'order_url'      => $this->url->link('sale/order/info', 'user_token=' . $ut . '&order_id=' . (int)$r['order_id'], true),
				'int_doc_number' => isset($r['int_doc_number']) ? $r['int_doc_number'] : '',
				'tracking_url'   => !empty($r['int_doc_number']) ? ('https://novaposhta.ua/tracking/?cargo_number=' . urlencode((string)$r['int_doc_number'])) : '',
				'recipient'      => isset($r['recipient_name']) ? $r['recipient_name'] : '',
				'status_code'    => (int)$r['status_code'],
				'status_text'    => isset($r['status_text']) ? $r['status_text'] : '',
				'cod_amount'     => (float)(isset($r['cod_amount']) ? $r['cod_amount'] : 0),
				'money_transfer' => (string)(isset($r['money_transfer_number']) ? $r['money_transfer_number'] : ''),
				'return_ttn'     => (string)(isset($r['return_int_doc_number']) ? $r['return_int_doc_number'] : ''),
				'created_at'     => isset($r['created_at']) ? $r['created_at'] : '',
				'last_polled_at' => isset($r['last_polled_at']) ? $r['last_polled_at'] : '',
				'return_url'     => $this->url->link('extension/shipping/np_shipment/createReturn', 'user_token=' . $ut . '&shipment_id=' . (int)$r['shipment_id'], true),
			);
		}
		$data['shipments'] = $shipments;

		$data['breadcrumbs'] = array();
		$data['breadcrumbs'][] = array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $ut, true));
		$data['breadcrumbs'][] = array('text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/shipping/np_shipment', 'user_token=' . $ut, true));

		$data['back']     = $this->url->link('extension/shipping/nova_poshta', 'user_token=' . $ut, true);
		$data['sync_url'] = $this->url->link('extension/shipping/np_shipment/syncCod', 'user_token=' . $ut, true);

		$data['header']      = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer']      = $this->load->controller('common/footer');

		$data = array_merge($this->language->all(), $data);

		$this->response->setOutput($this->load->view('extension/shipping/np_shipment', $data));
	}

	public function createReturn() {
		$json = array();
		if (!NpLicense::isPro($this->config)) {
			$json['error'] = 'Return TTN is a Pro feature. Activate a license to use it.';
			$this->jsonResponse($json);
			return;
		}
		$shipmentId = (int)(isset($this->request->get['shipment_id']) ? $this->request->get['shipment_id'] : 0);
		$row = $this->db->query("SELECT int_doc_number FROM `" . DB_PREFIX . "np_shipment` WHERE shipment_id = " . $shipmentId)->row;
		if (!$row || empty($row['int_doc_number'])) {
			$json['error'] = 'Shipment has no TTN to return';
		} else {
			$key = $this->apiKey();
			$senderWh = (string)$this->config->get('shipping_nova_poshta_sender_warehouse_ref');
			if ($key === '' || $senderWh === '') {
				$json['error'] = 'API key or sender warehouse not configured';
			} else {
				$client = new NpClient($key);
				$resp = $client->createReturn((string)$row['int_doc_number'], $senderWh);
				if (!empty($resp['success']) && !empty($resp['data'][0]['Number'])) {
					$ttn = (string)$resp['data'][0]['Number'];
					$ref = (string)(isset($resp['data'][0]['Ref']) ? $resp['data'][0]['Ref'] : '');
					$this->db->query("UPDATE `" . DB_PREFIX . "np_shipment` SET return_int_doc_number = '" . $this->db->escape($ttn) . "', return_int_doc_ref = '" . $this->db->escape($ref) . "' WHERE shipment_id = " . $shipmentId);
					$json['success'] = 'Return TTN created: ' . $ttn;
				} else {
					$json['error'] = 'Return failed: ' . (is_array(isset($resp['errors']) ? $resp['errors'] : null) ? implode('; ', $resp['errors']) : 'unknown');
				}
			}
		}
		$this->jsonResponse($json);
	}

	public function syncCod() {
		$json = array();
		if (!NpLicense::isPro($this->config)) {
			$json['error'] = 'COD sync is a Pro feature. Activate a license to use it.';
			$this->jsonResponse($json);
			return;
		}
		$key = $this->apiKey();
		if ($key === '') {
			$json['error'] = 'API key not configured';
		} else {
			$client = new NpClient($key);
			$updated = 0;
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
					$updated++;
				}
				if (count($resp['data']) < 100) break;
			}
			$json['success'] = 'Synced ' . $updated . ' shipments (range ' . $dateFrom . '-' . $dateTo . ')';
		}
		$this->jsonResponse($json);
	}
}
