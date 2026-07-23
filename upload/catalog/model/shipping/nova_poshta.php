<?php
namespace Opencart\Catalog\Model\Extension\NovaPoshtaPremium\Shipping;

require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/client.php';
require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/crypto.php';

class NovaPoshta extends \Opencart\System\Engine\Model {
	public function getQuote(array $address): array {
		$this->load->language('extension/nova_poshta_premium/shipping/nova_poshta');

		// Geo-zone check via direct query — OpenCart 4.x has no catalog
		// `localisation/geo_zone` model, so loading it fatals the whole quote.
		$geo_zone_id = (int)$this->config->get('shipping_nova_poshta_geo_zone_id');
		if ($geo_zone_id) {
			$query = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "zone_to_geo_zone` WHERE `geo_zone_id` = '" . (int)$geo_zone_id . "' AND `country_id` = '" . (int)$address['country_id'] . "' AND (`zone_id` = '" . (int)$address['zone_id'] . "' OR `zone_id` = '0')");
			$status = (bool)$query->row['total'];
		} else {
			$status = true;
		}
		if (!$status) {
			return [];
		}

		$senderCity   = (string)$this->config->get('shipping_nova_poshta_sender_city_ref');
		$recipientCity= (string)($this->session->data['np_recipient_city_ref'] ?? '');
		$key          = \Opencart\System\Library\NovaPoshta\Crypto::decrypt((string)$this->config->get('shipping_nova_poshta_api_key'));
		$defaultCost  = (float)$this->config->get('shipping_nova_poshta_default_cost');
		$cost         = $defaultCost;

		if ($key !== '' && $senderCity !== '' && $recipientCity !== '') {
			$weight = $this->cartWeightKg();
			$value  = $this->cartValue();
			$client = new \Opencart\System\Library\NovaPoshta\Client($key);
			$response = $client->quote($senderCity, $recipientCity, $weight, $value, 'WarehouseWarehouse');
			if (!empty($response['success']) && !empty($response['data'][0]['Cost'])) {
				$cost = (float)$response['data'][0]['Cost'];
			}
		}

		$tax_class_id = (int)$this->config->get('shipping_nova_poshta_tax_class_id');
		$quote_data['nova_poshta'] = [
			'code'         => 'nova_poshta.nova_poshta',
			'name'         => $this->language->get('text_description'),
			'cost'         => $cost,
			'tax_class_id' => $tax_class_id,
			'text'         => $this->currency->format(
				$this->tax->calculate($cost, $tax_class_id, $this->config->get('config_tax')),
				$this->session->data['currency']
			),
		];

		return [
			'code'       => 'nova_poshta',
			'name'       => $this->language->get('heading_title'),
			'quote'      => $quote_data,
			'sort_order' => $this->config->get('shipping_nova_poshta_sort_order'),
			'error'      => false,
		];
	}

	private function cartWeightKg(): float {
		if (!isset($this->cart) || !is_object($this->cart)) {
			return 1.0;
		}
		// OC cart->getWeight() returns in store's default weight unit. For NP we need kg.
		// Conservative default: assume KG unit if not detectable.
		try {
			$w = (float)$this->cart->getWeight();
			return $w > 0 ? $w : 1.0;
		} catch (\Throwable $e) {
			return 1.0;
		}
	}

	private function cartValue(): float {
		if (!isset($this->cart) || !is_object($this->cart)) {
			return 100.0;
		}
		try {
			$total = (float)$this->cart->getSubTotal();
			return $total > 0 ? $total : 100.0;
		} catch (\Throwable $e) {
			return 100.0;
		}
	}
}
