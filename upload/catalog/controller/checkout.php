<?php
namespace Opencart\Catalog\Controller\Extension\NovaPoshtaPremium;

require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/client.php';
require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/crypto.php';
require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/cache.php';

class Checkout extends \Opencart\System\Engine\Controller {
	private function jsonResponse(array $data): void {
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

	public function searchCities(): void {
		$query = trim((string)($this->request->post['q'] ?? $this->request->get['q'] ?? ''));
		if ($query === '') {
			$this->jsonResponse(['cities' => []]);
			return;
		}
		$this->jsonResponse(['cities' => \Opencart\System\Library\NovaPoshta\Cache::searchCities($this->db, $query, $this->apiKey())]);
	}

	public function getWarehouses(): void {
		$cityRef = trim((string)($this->request->post['city_ref'] ?? $this->request->get['city_ref'] ?? ''));
		if ($cityRef === '') {
			$this->jsonResponse(['warehouses' => []]);
			return;
		}
		$this->jsonResponse(['warehouses' => \Opencart\System\Library\NovaPoshta\Cache::getWarehouses($this->db, $cityRef, $this->apiKey())]);
	}

	public function setSelection(): void {
		$city_ref   = trim((string)($this->request->post['city_ref'] ?? ''));
		$city_name  = trim((string)($this->request->post['city_name'] ?? ''));
		$wh_ref     = trim((string)($this->request->post['warehouse_ref'] ?? ''));
		$wh_name    = trim((string)($this->request->post['warehouse_name'] ?? ''));
		$this->session->data['np_recipient_city_ref']       = $city_ref;
		$this->session->data['np_recipient_city_name']      = $city_name;
		$this->session->data['np_recipient_warehouse_ref']  = $wh_ref;
		$this->session->data['np_recipient_warehouse_name'] = $wh_name;
		$this->jsonResponse(['ok' => true]);
	}

	public function getSelection(): void {
		$this->jsonResponse([
			'city_ref'       => (string)($this->session->data['np_recipient_city_ref'] ?? ''),
			'city_name'      => (string)($this->session->data['np_recipient_city_name'] ?? ''),
			'warehouse_ref'  => (string)($this->session->data['np_recipient_warehouse_ref'] ?? ''),
			'warehouse_name' => (string)($this->session->data['np_recipient_warehouse_name'] ?? ''),
		]);
	}
}
