<?php
namespace Opencart\Catalog\Model\Extension\NovaPoshtaPremium\Shipping;

require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/client.php';
require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/crypto.php';
require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/cache.php';
require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/guard.php';
require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/selection.php';

class NovaPoshta extends \Opencart\System\Engine\Model {
	/** Live tariffs already asked in this request (the checkout quotes the same route twice). */
	private static array $quoted = [];

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

		// A parallel checkout request may have written back a session without
		// the pick — price by the city the customer actually chose.
		\Opencart\System\Library\NovaPoshta\Selection::restore($this->session);
		$senderCity   = (string)$this->config->get('shipping_nova_poshta_sender_city_ref');
		$recipientCity= (string)($this->session->data['np_recipient_city_ref'] ?? '');
		$key          = \Opencart\System\Library\NovaPoshta\Crypto::decrypt((string)$this->config->get('shipping_nova_poshta_api_key'));
		$defaultCost  = (float)$this->config->get('shipping_nova_poshta_default_cost');
		$cost         = $defaultCost;
		// Opt-in: unset (fresh install / upgrade from an older build) means OFF, so
		// the merchant's flat cost is never silently replaced by a carrier tariff.
		$liveRate     = (int)$this->config->get('shipping_nova_poshta_live_rate');

		if ($liveRate && $key !== '' && $senderCity !== '' && \Opencart\System\Library\NovaPoshta\Cache::isRef($recipientCity)) {
			$weight = $this->cartWeightKg();
			$value  = $this->cartValue();
			$memo   = $senderCity . '|' . $recipientCity . '|' . $weight . '|' . $value;
			\Opencart\System\Library\NovaPoshta\Guard::bind($this->db);
			if (!array_key_exists($memo, self::$quoted) && \Opencart\System\Library\NovaPoshta\Guard::degraded()) {
				// The API just failed and nothing has cleared it: don't make the buyer
				// sit out another timeout for a price we can take from the memo.
				self::$quoted[$memo] = \Opencart\System\Library\NovaPoshta\Cache::lastRate($this->db, $senderCity, $recipientCity, $weight);
			}
			if (!array_key_exists($memo, self::$quoted)) {
				$live   = null;
				$client = new \Opencart\System\Library\NovaPoshta\Client($key, $this->db);
				$response = $client->quote($senderCity, $recipientCity, $weight, $value, 'WarehouseWarehouse');
				if (!empty($response['success']) && !empty($response['data'][0]['Cost'])) {
					$live = (float)$response['data'][0]['Cost'];
					\Opencart\System\Library\NovaPoshta\Cache::rememberRate($this->db, $senderCity, $recipientCity, $weight, $live);
				} else {
					// No answer from Nova Poshta (down, paused by the breaker, or an
					// error): the last tariff it quoted for this route and weight,
					// else the flat price below. The order goes through either way.
					$live = \Opencart\System\Library\NovaPoshta\Cache::lastRate($this->db, $senderCity, $recipientCity, $weight);
				}
				self::$quoted[$memo] = $live;
			}
			if (self::$quoted[$memo] !== null) {
				$cost = (float)self::$quoted[$memo];
			}
		}

		$tax_class_id = (int)$this->config->get('shipping_nova_poshta_tax_class_id');

		// A bare "0 ₴" reads as "delivery is free", which is wrong when the buyer
		// pays the courier on pickup. Say so instead of printing a zero price —
		// two different wordings on purpose: the name is reused verbatim by the
		// order totals row, the text sits next to it on the method radio.
		$name = $this->language->get('text_description');
		if ($cost <= 0) {
			$name .= ' (' . $this->language->get('text_pay_on_pickup') . ')';
			$text  = $this->language->get('text_carrier_rate');
		} else {
			$text = $this->currency->format(
				$this->tax->calculate($cost, $tax_class_id, $this->config->get('config_tax')),
				$this->session->data['currency']
			);
		}

		$quote_data['nova_poshta'] = [
			'code'         => 'nova_poshta.nova_poshta',
			'name'         => $name,
			'cost'         => $cost,
			'tax_class_id' => $tax_class_id,
			'text'         => $text,
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
