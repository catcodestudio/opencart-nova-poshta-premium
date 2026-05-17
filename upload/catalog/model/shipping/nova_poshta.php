<?php
namespace Opencart\Catalog\Model\Extension\NovaPoshtaPremium\Shipping;

require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/client.php';

class NovaPoshta extends \Opencart\System\Engine\Model {
	public function getQuote(array $address): array {
		$this->load->language('extension/nova_poshta_premium/shipping/nova_poshta');
		$this->load->model('localisation/geo_zone');

		$results = $this->model_localisation_geo_zone->getGeoZone(
			(int)$this->config->get('shipping_nova_poshta_geo_zone_id'),
			(int)$address['country_id'],
			(int)$address['zone_id']
		);

		$status = !$this->config->get('shipping_nova_poshta_geo_zone_id') || (bool)$results;

		$method_data = [];

		if ($status) {
			$cost = (float)$this->config->get('shipping_nova_poshta_default_cost');
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

			$method_data = [
				'code'       => 'nova_poshta',
				'name'       => $this->language->get('heading_title'),
				'quote'      => $quote_data,
				'sort_order' => $this->config->get('shipping_nova_poshta_sort_order'),
				'error'      => false,
			];
		}

		return $method_data;
	}
}
