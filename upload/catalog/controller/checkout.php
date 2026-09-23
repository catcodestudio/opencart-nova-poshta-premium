<?php
namespace Opencart\Catalog\Controller\Extension\NovaPoshtaPremium;

require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/client.php';
require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/crypto.php';
require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/cache.php';
require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/zones.php';
require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/selection.php';

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
		// The oblast comes from the module's own city directory by ref; the
		// posted value is only a fallback for a manually typed city.
		$dirArea = \Opencart\System\Library\NovaPoshta\Cache::cityArea($this->db, $city_ref);
		if ($dirArea !== '') {
			$city_area = $dirArea;
		}
		$this->session->data['np_recipient_city_ref']       = $city_ref;
		$this->session->data['np_recipient_city_name']      = $city_name;
		$this->session->data['np_recipient_city_area']      = $city_area;
		$this->session->data['np_recipient_warehouse_ref']  = $wh_ref;
		$this->session->data['np_recipient_warehouse_name'] = $wh_name;
		\Opencart\System\Library\NovaPoshta\Selection::remember($this->session);
		$this->applyToShippingAddress();
		// The rate is keyed by the recipient city, so the quote list the core
		// cached when the carrier was picked (no city yet — hence the flat
		// fallback) must not survive the pick: `shipping_method.save` validates
		// against this cache, so a stale entry would re-save the old price.
		unset($this->session->data['shipping_methods']);
		$this->requoteChosenMethod();
		$zone = $this->resolveZone($city_area, $city_name);
		$this->jsonResponse([
			'ok'      => true,
			'zone_id' => $zone ? (int)$zone['zone_id'] : 0,
			'cost'    => (float)($this->session->data['shipping_method']['cost'] ?? 0),
		]);
	}

	/**
	 * Re-prices the ALREADY CHOSEN Nova Poshta method on the server.
	 *
	 * The carrier is usually picked before the branch (the widget opens only
	 * then), so session['shipping_method'] holds a quote made without the
	 * recipient city. The widget re-saves the method on the stock checkout, but
	 * a theme or one-page checkout that doesn't re-save would still carry the
	 * stale price into the order — the confirm step reads the session entry, not
	 * the rendered list. Replacing it here makes the price independent of the
	 * front end. The payment method is left untouched.
	 */
	private function requoteChosenMethod(): void {
		$chosen = $this->session->data['shipping_method'] ?? null;
		if (!is_array($chosen) || strpos((string)($chosen['code'] ?? ''), 'nova_poshta.') !== 0) {
			return;
		}
		$address = $this->session->data['shipping_address'] ?? null;
		if (!is_array($address) || !isset($address['country_id'])) {
			return;
		}
		try {
			$this->load->model('extension/nova_poshta_premium/shipping/nova_poshta');
			$quote = $this->model_extension_nova_poshta_premium_shipping_nova_poshta->getQuote($address);
		} catch (\Throwable $e) {
			return;
		}
		if (!empty($quote['quote']['nova_poshta']) && is_array($quote['quote']['nova_poshta'])) {
			$this->session->data['shipping_method'] = $quote['quote']['nova_poshta'];
		}
	}

	/**
	 * Persists the picked NP city/warehouse into the CORE OpenCart session
	 * shipping address. The picker keeps the hidden native form fields in sync
	 * client-side, but the order is built from session['shipping_address'] —
	 * which the theme may have saved (register.save) BEFORE the customer picked
	 * a warehouse, freezing whatever placeholder was seeded at page load. Writing
	 * the session here makes the final address independent of the click order.
	 * The order row itself is corrected once more after addOrder/editOrder
	 * (events.php), because a later register.save may overwrite this session.
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
		$area = (string)($this->session->data['np_recipient_city_area'] ?? '');
		$zone = $this->resolveZone($area, $city);
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
			// No match → no zone: the previous value is the hidden form's seed
			// (first zone of the country), not the customer's region.
			'zone_id'        => $zone ? (int)$zone['zone_id'] : 0,
			'zone'           => $zone ? (string)$zone['name'] : $area,
			'zone_code'      => $zone ? (string)$zone['code'] : '',
			'country_id'     => (int)$country['country_id'],
			'country'        => (string)($country['name'] ?? 'Ukraine'),
			'iso_code_2'     => (string)($country['iso_code_2'] ?? 'UA'),
			'iso_code_3'     => (string)($country['iso_code_3'] ?? 'UKR'),
			'address_format' => (string)($country['address_format'] ?? ''),
			'custom_field'   => (array)($prev['custom_field'] ?? []),
		];
	}

	private function resolveZone(string $area, string $city): array {
		$country = $this->ukraineCountry();
		return \Opencart\System\Library\NovaPoshta\Zones::resolve(
			$this->db,
			$area,
			$city,
			(int)$this->config->get('config_language_id'),
			(int)($country['country_id'] ?? 0)
		);
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

	public function getSelection(): void {
		\Opencart\System\Library\NovaPoshta\Selection::restore($this->session);
		$this->jsonResponse([
			'city_ref'       => (string)($this->session->data['np_recipient_city_ref'] ?? ''),
			'city_name'      => (string)($this->session->data['np_recipient_city_name'] ?? ''),
			'city_area'      => (string)($this->session->data['np_recipient_city_area'] ?? ''),
			'warehouse_ref'  => (string)($this->session->data['np_recipient_warehouse_ref'] ?? ''),
			'warehouse_name' => (string)($this->session->data['np_recipient_warehouse_name'] ?? ''),
			'zone_id'        => (int)($this->resolveZone((string)($this->session->data['np_recipient_city_area'] ?? ''), (string)($this->session->data['np_recipient_city_name'] ?? ''))['zone_id'] ?? 0),
		]);
	}
}
