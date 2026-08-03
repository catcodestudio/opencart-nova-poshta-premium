<?php
require_once DIR_SYSTEM . 'library/novaposhta/client.php';
require_once DIR_SYSTEM . 'library/novaposhta/crypto.php';

/**
 * Nova Poshta Premium — shipping quote model (OpenCart 3.0.x).
 * By default the quote is the flat cost from settings (most UA stores let the
 * customer pay the carrier on pickup). Live rating via
 * InternetDocument.getDocumentPrice is opt-in per store and still falls back to
 * the flat cost when the API key or sender city is not configured.
 */
class ModelExtensionShippingNovaPoshta extends Model {
	public function getQuote($address) {
		$this->load->language('extension/shipping/nova_poshta');

		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$this->config->get('shipping_nova_poshta_geo_zone_id') . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");

		if (!$this->config->get('shipping_nova_poshta_geo_zone_id')) {
			$status = true;
		} elseif ($query->num_rows) {
			$status = true;
		} else {
			$status = false;
		}

		if (!$status) {
			return array();
		}

		$senderCity    = (string)$this->config->get('shipping_nova_poshta_sender_city_ref');
		$recipientCity = isset($this->session->data['np_recipient_city_ref']) ? (string)$this->session->data['np_recipient_city_ref'] : '';
		$key           = NpCrypto::decrypt((string)$this->config->get('shipping_nova_poshta_api_key'));
		$cost          = (float)$this->config->get('shipping_nova_poshta_default_cost');
		// Opt-in: unset (fresh install / upgrade from <= 1.2.7) means OFF, so the
		// merchant's flat cost is never silently replaced by a carrier tariff.
		$liveRate      = (int)$this->config->get('shipping_nova_poshta_live_rate');

		if ($liveRate && $key !== '' && $senderCity !== '' && $recipientCity !== '') {
			$weight = $this->cartWeightKg();
			$value  = $this->cartValue();
			$client = new NpClient($key);
			$response = $client->quote($senderCity, $recipientCity, $weight, $value, 'WarehouseWarehouse');
			if (!empty($response['success']) && !empty($response['data'][0]['Cost'])) {
				$cost = (float)$response['data'][0]['Cost'];
			}
		}

		$tax_class_id = (int)$this->config->get('shipping_nova_poshta_tax_class_id');

		// A bare "0 ₴" reads as "delivery is free", which is wrong when the buyer
		// pays the courier on pickup. Say so instead of printing a zero price —
		// in the method title too, since the order totals row reuses it.
		$title = $this->language->get('text_description');
		if ($cost <= 0) {
			// Two different wordings on purpose: the title is reused verbatim by
			// the order totals row, the text sits next to it on the method radio.
			$title .= ' (' . $this->language->get('text_pay_on_pickup') . ')';
			$text   = $this->language->get('text_carrier_rate');
		} else {
			$text = $this->currency->format($this->tax->calculate($cost, $tax_class_id, $this->config->get('config_tax')), $this->session->data['currency']);
		}

		$quote_data = array();
		$quote_data['nova_poshta'] = array(
			'code'         => 'nova_poshta.nova_poshta',
			'title'        => $title,
			'cost'         => $cost,
			'tax_class_id' => $tax_class_id,
			'text'         => $text
		);

		return array(
			'code'       => 'nova_poshta',
			'title'      => $this->language->get('heading_title'),
			'quote'      => $quote_data,
			'sort_order' => $this->config->get('shipping_nova_poshta_sort_order'),
			'error'      => false
		);
	}

	private function cartWeightKg() {
		if (!isset($this->cart) || !is_object($this->cart)) {
			return 1.0;
		}
		$w = (float)$this->cart->getWeight();
		return $w > 0 ? $w : 1.0;
	}

	private function cartValue() {
		if (!isset($this->cart) || !is_object($this->cart)) {
			return 100.0;
		}
		$total = (float)$this->cart->getSubTotal();
		return $total > 0 ? $total : 100.0;
	}
}
