<?php
namespace Opencart\Admin\Controller\Extension\NovaPoshtaPremium;

require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/client.php';
require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/crypto.php';
require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/license.php';

class Shipment extends \Opencart\System\Engine\Controller {
	private function jsonResponse(array $data): void {
		if (ob_get_level() > 0) { ob_clean(); }
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));
	}

	private function apiKey(): string {
		$raw = (string)$this->config->get('shipping_nova_poshta_api_key');
		return $raw === '' ? '' : \Opencart\System\Library\NovaPoshta\Crypto::decrypt($raw);
	}

	public function list(): void {
		$this->load->language('extension/nova_poshta_premium/shipment');
		$this->document->setTitle($this->language->get('heading_title'));

		$ut = $this->session->data['user_token'];

		$rows = $this->db->query("SELECT s.*, o.invoice_no FROM `" . DB_PREFIX . "np_shipment` s LEFT JOIN `" . DB_PREFIX . "order` o ON o.order_id = s.order_id ORDER BY s.shipment_id DESC LIMIT 200")->rows;

		$data['shipments'] = array_map(function ($r) use ($ut) {
			return [
				'shipment_id'    => $r['shipment_id'],
				'order_id'       => $r['order_id'],
				'order_url'      => $this->url->link('sale/order.info', 'user_token=' . $ut . '&order_id=' . (int)$r['order_id']),
				'int_doc_number' => $r['int_doc_number'] ?? '',
				'tracking_url'   => $r['int_doc_number'] ? ('https://novaposhta.ua/tracking/?cargo_number=' . urlencode((string)$r['int_doc_number'])) : '',
				'recipient'      => $r['recipient_name'] ?? '',
				'status_code'    => (int)$r['status_code'],
				'status_text'    => $r['status_text'] ?? '',
				'cod_amount'     => (float)($r['cod_amount'] ?? 0),
				'money_transfer' => (string)($r['money_transfer_number'] ?? ''),
				'return_ttn'     => (string)($r['return_int_doc_number'] ?? ''),
				'created_at'     => $r['created_at'] ?? '',
				'last_polled_at' => $r['last_polled_at'] ?? '',
				'return_url'     => $this->url->link('extension/nova_poshta_premium/shipment.createReturn', 'user_token=' . $ut . '&shipment_id=' . (int)$r['shipment_id']),
			];
		}, $rows);

		$data['breadcrumbs'] = [
			['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $ut)],
			['text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/nova_poshta_premium/shipment.list', 'user_token=' . $ut)],
		];
		$data['back']      = $this->url->link('extension/nova_poshta_premium/shipping/nova_poshta', 'user_token=' . $ut);
		$data['sync_url']  = $this->url->link('extension/nova_poshta_premium/shipment.syncCod', 'user_token=' . $ut);

		// Return TTN and COD reconciliation are Pro. The buttons stay on screen
		// but disabled, so the merchant can see what a licence adds instead of
		// finding out only after clicking.
		$data['is_pro']    = \Opencart\System\Library\NovaPoshta\License::isPro($this->config);
		$data['settings_url'] = $this->url->link('extension/nova_poshta_premium/shipping/nova_poshta', 'user_token=' . $ut);

		$data['header']      = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer']      = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/nova_poshta_premium/shipment_list', $data));
	}

	public function createReturn(): void {
		$json = [];
		// Pro feature: programmatic Return TTN creation via NP API.
		// Free users can still issue returns by hand from the NP cabinet.
		if (!\Opencart\System\Library\NovaPoshta\License::isPro($this->config)) {
			$json['error'] = 'Return TTN is a Pro feature. Activate a license to use it.';
			$this->jsonResponse($json);
			return;
		}
		$shipmentId = (int)($this->request->get['shipment_id'] ?? 0);
		$row = $this->db->query("SELECT int_doc_number FROM `" . DB_PREFIX . "np_shipment` WHERE shipment_id = " . $shipmentId)->row;
		if (!$row || empty($row['int_doc_number'])) {
			$json['error'] = 'Shipment has no TTN to return';
		} else {
			$key = $this->apiKey();
			$senderWh = (string)$this->config->get('shipping_nova_poshta_sender_warehouse_ref');
			if ($key === '' || $senderWh === '') {
				$json['error'] = 'API key or sender warehouse not configured';
			} else {
				$client = new \Opencart\System\Library\NovaPoshta\Client($key);
				$check = $client->checkReturnPossible((string)$row['int_doc_number']);
				if (empty($check['success'])) {
					$json['error'] = 'Return not possible: ' . (is_array(isset($check['errors']) ? $check['errors'] : null) ? implode('; ', $check['errors']) : 'unknown');
					$this->jsonResponse($json);
					return;
				}
				$resp = $client->createReturn((string)$row['int_doc_number'], $senderWh);
				if (!empty($resp['success']) && !empty($resp['data'][0]['Number'])) {
					$ttn = (string)$resp['data'][0]['Number'];
					$ref = (string)($resp['data'][0]['Ref'] ?? '');
					$this->db->query("UPDATE `" . DB_PREFIX . "np_shipment` SET return_int_doc_number = '" . $this->db->escape($ttn) . "', return_int_doc_ref = '" . $this->db->escape($ref) . "' WHERE shipment_id = " . $shipmentId);
					$json['success'] = 'Return TTN created: ' . $ttn;
				} else {
					$json['error'] = 'Return failed: ' . (is_array($resp['errors'] ?? null) ? implode('; ', $resp['errors']) : 'unknown');
				}
			}
		}
		$this->jsonResponse($json);
	}

	public function syncCod(): void {
		$json = [];
		// Pro feature: manual COD reconciliation trigger from admin UI.
		if (!\Opencart\System\Library\NovaPoshta\License::isPro($this->config)) {
			$json['error'] = 'COD sync is a Pro feature. Activate a license to use it.';
			$this->jsonResponse($json);
			return;
		}
		$key = $this->apiKey();
		if ($key === '') {
			$json['error'] = 'API key not configured';
		} else {
			$client = new \Opencart\System\Library\NovaPoshta\Client($key);
			$updated = 0;
			$dateFrom = date('d.m.Y', strtotime('-90 days'));
			$dateTo   = date('d.m.Y');
			for ($page = 1; $page <= 10; $page++) {
				$resp = $client->getOwnShipments($dateFrom, $dateTo, $page);
				if (empty($resp['success']) || empty($resp['data'])) break;
				foreach ($resp['data'] as $row) {
					$num   = (string)($row['IntDocNumber'] ?? '');
					$bds   = (float)($row['BackwardDeliverySum'] ?? 0);
					$mtn   = (string)($row['MoneyTransferNumber'] ?? '');
					if ($num === '') continue;
					$this->db->query("UPDATE `" . DB_PREFIX . "np_shipment` SET cod_amount = " . $bds . ", money_transfer_number = '" . $this->db->escape($mtn) . "' WHERE int_doc_number = '" . $this->db->escape($num) . "'");
					$updated++;
				}
				if (count($resp['data']) < 100) break;
			}
			$json['success'] = 'Synced ' . $updated . ' shipments (range ' . $dateFrom . '–' . $dateTo . ')';
		}
		$this->jsonResponse($json);
	}
}
