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
		$city_area  = trim((string)($this->request->post['city_area'] ?? ''));
		$wh_ref     = trim((string)($this->request->post['warehouse_ref'] ?? ''));
		$wh_name    = trim((string)($this->request->post['warehouse_name'] ?? ''));
		$this->session->data['np_recipient_city_ref']       = $city_ref;
		$this->session->data['np_recipient_city_name']      = $city_name;
		$this->session->data['np_recipient_city_area']      = $city_area;
		$this->session->data['np_recipient_warehouse_ref']  = $wh_ref;
		$this->session->data['np_recipient_warehouse_name'] = $wh_name;
		$this->applyToShippingAddress();
		$this->jsonResponse(['ok' => true]);
	}

	/**
	 * Persists the picked NP city/warehouse into the CORE OpenCart session
	 * shipping address. The picker keeps the hidden native form fields in sync
	 * client-side, but the order is built from session['shipping_address'] —
	 * which the theme may have saved (register.save) BEFORE the customer picked
	 * a warehouse, freezing whatever placeholder was seeded at page load. Writing
	 * the session here makes the final address independent of the click order.
	 */
	private function applyToShippingAddress(): void {
		// Never stomp another carrier's address: only apply while NP is the
		// chosen shipping method, or no method has been chosen yet.
		$method = (string)($this->session->data['shipping_method']['code'] ?? '');
		if ($method !== '' && strpos($method, 'nova_poshta.') !== 0) {
			return;
		}
		$city = (string)($this->session->data['np_recipient_city_name'] ?? '');
		if ($city === '') {
			return;
		}
		$country = $this->ukraineCountry();
		if (!$country) {
			return;
		}
		$zone = $this->matchZone((int)$country['country_id'], (string)($this->session->data['np_recipient_city_area'] ?? ''));
		$wh   = (string)($this->session->data['np_recipient_warehouse_name'] ?? '');
		$prev = (array)($this->session->data['shipping_address'] ?? []);
		$this->session->data['shipping_address'] = [
			'address_id'     => (int)($prev['address_id'] ?? 0),
			'firstname'      => (string)($prev['firstname'] ?? ''),
			'lastname'       => (string)($prev['lastname'] ?? ''),
			'company'        => '',
			'address_1'      => $wh !== '' ? $wh : 'Нова Пошта',
			'address_2'      => '',
			'city'           => $city,
			'postcode'       => '',
			'zone_id'        => $zone ? (int)$zone['zone_id'] : (int)($prev['zone_id'] ?? 0),
			'zone'           => $zone ? (string)$zone['name'] : (string)($prev['zone'] ?? ''),
			'zone_code'      => $zone ? (string)$zone['code'] : (string)($prev['zone_code'] ?? ''),
			'country_id'     => (int)$country['country_id'],
			'country'        => (string)($country['name'] ?? 'Ukraine'),
			'iso_code_2'     => (string)($country['iso_code_2'] ?? 'UA'),
			'iso_code_3'     => (string)($country['iso_code_3'] ?? 'UKR'),
			'address_format' => (string)($country['address_format'] ?? ''),
			'custom_field'   => (array)($prev['custom_field'] ?? []),
		];
	}

	/** Store country if it is Ukraine, else the Ukraine row — carriers ship domestically only. */
	private function ukraineCountry(): array {
		$this->load->model('localisation/country');
		$info = $this->model_localisation_country->getCountry((int)$this->config->get('config_country_id'));
		if (!$info || strtoupper((string)($info['iso_code_2'] ?? '')) !== 'UA') {
			$row = $this->db->query("SELECT country_id FROM `" . DB_PREFIX . "country` WHERE iso_code_2 = 'UA' AND status = 1")->row;
			if ($row) {
				$info = $this->model_localisation_country->getCountry((int)$row['country_id']);
			}
		}
		return is_array($info) ? $info : [];
	}

	/**
	 * Matches an NP oblast name (Cyrillic, e.g. "Дніпропетровська") against the
	 * store's zone list, which is frequently transliterated ("Dnipropetrovs'ka
	 * Oblast'"). Normalized prefix match both ways; null when nothing matches —
	 * never a blind first-row fallback.
	 */
	private function matchZone(int $country_id, string $area): ?array {
		$key = self::latinize(preg_replace('/\s*(область|обл\.?|oblast\'?|м\.)\s*/iu', ' ', $area));
		if ($key === '') {
			return null;
		}
		$rows = $this->db->query("SELECT zone_id, name, code FROM `" . DB_PREFIX . "zone` WHERE country_id = " . (int)$country_id . " AND status = 1")->rows;
		foreach ($rows as $row) {
			$name = self::latinize((string)$row['name']);
			if ($name !== '' && strpos($name, $key) === 0) {
				return $row;
			}
		}
		foreach ($rows as $row) {
			$name = self::latinize((string)$row['name']);
			if ($name !== '' && strpos($key, $name) === 0) {
				return $row;
			}
		}
		return null;
	}

	/** Cyrillic → national-standard Latin, lowercased, a-z only (mbstring-free). */
	private static function latinize(string $s): string {
		static $map = [
			'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'є' => 'ie',
			'ж' => 'zh', 'з' => 'z', 'и' => 'y', 'і' => 'i', 'ї' => 'i', 'й' => 'i', 'к' => 'k', 'л' => 'l',
			'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
			'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ь' => '', 'ю' => 'iu', 'я' => 'ia',
			'А' => 'a', 'Б' => 'b', 'В' => 'v', 'Г' => 'h', 'Ґ' => 'g', 'Д' => 'd', 'Е' => 'e', 'Є' => 'ie',
			'Ж' => 'zh', 'З' => 'z', 'И' => 'y', 'І' => 'i', 'Ї' => 'i', 'Й' => 'i', 'К' => 'k', 'Л' => 'l',
			'М' => 'm', 'Н' => 'n', 'О' => 'o', 'П' => 'p', 'Р' => 'r', 'С' => 's', 'Т' => 't', 'У' => 'u',
			'Ф' => 'f', 'Х' => 'kh', 'Ц' => 'ts', 'Ч' => 'ch', 'Ш' => 'sh', 'Щ' => 'shch', 'Ь' => '', 'Ю' => 'iu', 'Я' => 'ia',
			"'" => '', '’' => '',
		];
		return preg_replace('/[^a-z]/', '', strtolower(strtr($s, $map)));
	}

	public function getSelection(): void {
		$this->jsonResponse([
			'city_ref'       => (string)($this->session->data['np_recipient_city_ref'] ?? ''),
			'city_name'      => (string)($this->session->data['np_recipient_city_name'] ?? ''),
			'city_area'      => (string)($this->session->data['np_recipient_city_area'] ?? ''),
			'warehouse_ref'  => (string)($this->session->data['np_recipient_warehouse_ref'] ?? ''),
			'warehouse_name' => (string)($this->session->data['np_recipient_warehouse_name'] ?? ''),
		]);
	}
}
