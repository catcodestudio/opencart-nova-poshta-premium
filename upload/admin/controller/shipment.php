<?php
namespace Opencart\Admin\Controller\Extension\NovaPoshtaPremium;

class Shipment extends \Opencart\System\Engine\Controller {
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
				'created_at'     => $r['created_at'] ?? '',
				'last_polled_at' => $r['last_polled_at'] ?? '',
			];
		}, $rows);

		$data['breadcrumbs'] = [
			['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $ut)],
			['text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/nova_poshta_premium/shipment.list', 'user_token=' . $ut)],
		];
		$data['back'] = $this->url->link('extension/nova_poshta_premium/shipping/nova_poshta', 'user_token=' . $ut);

		$data['header']      = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer']      = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/nova_poshta_premium/shipment_list', $data));
	}
}
