<?php
namespace Opencart\System\Library\NovaPoshta;

class Client {
	private string $endpoint = 'https://api.novaposhta.ua/v2.0/json/';
	private string $apiKey;
	private int $timeout = 15;

	public function __construct(string $apiKey) {
		$this->apiKey = $apiKey;
	}

	public function call(string $model, string $method, array $properties = []): array {
		$payload = [
			'apiKey'           => $this->apiKey,
			'modelName'        => $model,
			'calledMethod'     => $method,
			'methodProperties' => (object)$properties,
		];

		$ch = curl_init($this->endpoint);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
			CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
			CURLOPT_TIMEOUT        => $this->timeout,
			CURLOPT_CONNECTTIMEOUT => 5,
		]);

		$raw  = curl_exec($ch);
		$err  = curl_error($ch);
		$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		if ($raw === false) {
			return ['success' => false, 'errors' => ['HTTP: ' . $err], 'http_code' => $http];
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return ['success' => false, 'errors' => ['Invalid JSON response'], 'http_code' => $http, 'raw' => $raw];
		}

		return $decoded;
	}

	public function testConnection(): array {
		return $this->call('Common', 'getCargoTypes');
	}

	public function quote(string $citySenderRef, string $cityRecipientRef, float $weightKg, float $cost, string $serviceType = 'WarehouseWarehouse'): array {
		return $this->call('InternetDocument', 'getDocumentPrice', [
			'CitySender'    => $citySenderRef,
			'CityRecipient' => $cityRecipientRef,
			'Weight'        => (string)max($weightKg, 0.1),
			'ServiceType'   => $serviceType,
			'Cost'          => (string)max((int)round($cost), 1),
			'CargoType'     => 'Cargo',
			'SeatsAmount'   => '1',
		]);
	}

	public function trackStatus(array $intDocNumbers): array {
		$docs = [];
		foreach ($intDocNumbers as $n) {
			$docs[] = ['DocumentNumber' => (string)$n];
		}
		return $this->call('TrackingDocument', 'getStatusDocuments', ['Documents' => $docs]);
	}

	public function getSenderCounterparties(): array {
		return $this->call('Counterparty', 'getCounterparties', ['CounterpartyProperty' => 'Sender', 'Page' => '1']);
	}

	public function getContactPersons(string $counterpartyRef): array {
		return $this->call('Counterparty', 'getCounterpartyContactPersons', ['Ref' => $counterpartyRef]);
	}

	/**
	 * Create a TTN via InternetDocument.save with NewRecipient flow
	 * (auto-creates a PrivatePerson recipient counterparty inline).
	 *
	 * @param array{
	 *   sender_ref:string, sender_contact_ref:string, sender_phone:string,
	 *   sender_city_ref:string, sender_warehouse_ref:string,
	 *   recipient_city_ref:string, recipient_warehouse_ref:string,
	 *   recipient_name:string, recipient_phone:string,
	 *   weight:float, cost:float, description:string,
	 *   service_type?:string, payer_type?:string, payment_method?:string,
	 *   cargo_type?:string, seats_amount?:int
	 * } $args
	 */
	public function createTTN(array $args): array {
		// Two-step flow: first create a PrivatePerson recipient counterparty
		// (returns the global "Приватна особа" ref + new ContactPerson ref), then
		// call InternetDocument.save with the resolved refs.
		$names = preg_split('/\s+/', trim($args['recipient_name'] ?? '')) ?: [];
		$firstName  = (string)($names[0] ?? '');
		$lastName   = (string)($names[1] ?? $firstName);
		$middleName = (string)($names[2] ?? '');
		$phone      = preg_replace('/\D+/', '', (string)$args['recipient_phone']);
		if (strlen($phone) === 10 && $phone[0] === '0') {
			$phone = '38' . $phone;
		}

		$cpResp = $this->call('Counterparty', 'save', [
			'FirstName'            => $firstName ?: 'Recipient',
			'MiddleName'           => $middleName,
			'LastName'             => $lastName ?: 'Recipient',
			'Phone'                => $phone,
			'CounterpartyType'     => 'PrivatePerson',
			'CounterpartyProperty' => 'Recipient',
		]);
		if (empty($cpResp['success']) || empty($cpResp['data'][0]['Ref'])) {
			return $cpResp;
		}
		$recipientRef = (string)$cpResp['data'][0]['Ref'];
		$contactRef   = (string)($cpResp['data'][0]['ContactPerson']['data'][0]['Ref'] ?? '');
		if ($contactRef === '') {
			return ['success' => false, 'errors' => ['ContactPerson missing from Counterparty.save response']];
		}

		return $this->call('InternetDocument', 'save', [
			'PayerType'      => $args['payer_type']     ?? 'Recipient',
			'PaymentMethod'  => $args['payment_method'] ?? 'Cash',
			'DateTime'       => date('d.m.Y'),
			'CargoType'      => $args['cargo_type']     ?? 'Cargo',
			'Weight'         => (string)max((float)$args['weight'], 0.1),
			'ServiceType'    => $args['service_type']   ?? 'WarehouseWarehouse',
			'SeatsAmount'    => (string)max((int)($args['seats_amount'] ?? 1), 1),
			'Description'    => $args['description']    ?: 'Order',
			'Cost'           => (string)max((int)round((float)$args['cost']), 1),
			'CitySender'     => $args['sender_city_ref'],
			'Sender'         => $args['sender_ref'],
			'SenderAddress'  => $args['sender_warehouse_ref'],
			'ContactSender'  => $args['sender_contact_ref'],
			'SendersPhone'   => $args['sender_phone'],
			'CityRecipient'  => $args['recipient_city_ref'],
			'Recipient'      => $recipientRef,
			'RecipientAddress' => $args['recipient_warehouse_ref'],
			'ContactRecipient' => $contactRef,
			'RecipientsPhone' => $phone,
		]);
	}
}
