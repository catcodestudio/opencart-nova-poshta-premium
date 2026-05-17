<?php
namespace Opencart\Catalog\Controller\Extension\NovaPoshtaPremium;

require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/client.php';
require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/crypto.php';

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
		$json = ['cities' => []];
		$query = trim((string)($this->request->post['q'] ?? $this->request->get['q'] ?? ''));
		$key   = $this->apiKey();
		if ($key === '' || $query === '') {
			$this->jsonResponse($json);
			return;
		}
		$client   = new \Opencart\System\Library\NovaPoshta\Client($key);
		$response = $client->call('Address', 'getCities', ['FindByString' => $query, 'Limit' => '15']);
		if (!empty($response['success']) && is_array($response['data'])) {
			foreach ($response['data'] as $row) {
				$json['cities'][] = [
					'ref'  => (string)($row['Ref'] ?? ''),
					'name' => (string)($row['Description'] ?? ''),
					'area' => (string)($row['AreaDescription'] ?? ''),
				];
			}
		}
		$this->jsonResponse($json);
	}

	public function getWarehouses(): void {
		$json = ['warehouses' => []];
		$cityRef = trim((string)($this->request->post['city_ref'] ?? $this->request->get['city_ref'] ?? ''));
		$key     = $this->apiKey();
		if ($key === '' || $cityRef === '') {
			$this->jsonResponse($json);
			return;
		}
		$client   = new \Opencart\System\Library\NovaPoshta\Client($key);
		$response = $client->call('Address', 'getWarehouses', ['CityRef' => $cityRef, 'Limit' => '500']);
		if (!empty($response['success']) && is_array($response['data'])) {
			foreach ($response['data'] as $row) {
				$json['warehouses'][] = [
					'ref'        => (string)($row['Ref'] ?? ''),
					'number'     => (string)($row['Number'] ?? ''),
					'description'=> (string)($row['Description'] ?? ''),
					'type_ref'   => (string)($row['TypeOfWarehouse'] ?? ''),
				];
			}
		}
		$this->jsonResponse($json);
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
