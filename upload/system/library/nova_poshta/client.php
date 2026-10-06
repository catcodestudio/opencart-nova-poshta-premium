<?php
namespace Opencart\System\Library\NovaPoshta;

require_once __DIR__ . '/translit.php';
require_once __DIR__ . '/guard.php';

class Client {
	private string $endpoint = 'https://api.novaposhta.ua/v2.0/json/';
	private string $apiKey;
	// Seconds for the whole call / for the TCP+TLS handshake. Short on purpose:
	// a request that waits for a dead API holds a PHP worker, and a shop has few.
	private int $timeout;
	private int $connectTimeout = 3;
	private bool $bypassGuard = false;

	/**
	 * @param $db      the shop connection — binds the shared circuit breaker
	 *                 (Guard). Null leaves the breaker out (CLI scripts).
	 * @param $timeout seconds for one call; 6 for anything a buyer or the admin
	 *                 waits on, more only for the paged directory downloads.
	 */
	public function __construct(string $apiKey, $db = null, int $timeout = 6) {
		$this->apiKey  = $apiKey;
		$this->timeout = max(2, $timeout);
		if ($db !== null) {
			Guard::bind($db);
		}
	}

	/** The admin «Test connection» button asks the API even while the breaker is open. */
	public function bypassGuard(): self {
		$this->bypassGuard = true;
		return $this;
	}

	/**
	 * Whether a response means «the API is not there» (as opposed to «the API
	 * said no»): the caller then falls back or queues instead of reporting an error.
	 */
	public static function isUnavailable(array $response): bool {
		if (!empty($response['unavailable'])) {
			return true;
		}
		if (!empty($response['success'])) {
			return false;
		}
		$errors = is_array($response['errors'] ?? null) ? implode(' ', array_map('strval', $response['errors'])) : '';
		return (bool)preg_match('/temporar|unavailable|time ?out|timed out|internal server|overload|недоступ|тимчасово|технічн/iu', $errors);
	}

	public function call(string $model, string $method, array $properties = []): array {
		if (!$this->bypassGuard && !Guard::allow()) {
			return ['success' => false, 'unavailable' => true, 'errors' => ['Nova Poshta API is not responding — paused, working from the saved data']];
		}

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
			CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
			// Sub-second timeouts need this on some builds; harmless otherwise.
			CURLOPT_NOSIGNAL       => true,
		]);

		$raw  = curl_exec($ch);
		$err  = curl_error($ch);
		$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

		if ($raw === false || $http === 0) {
			Guard::failure('HTTP: ' . $err);
			return ['success' => false, 'unavailable' => true, 'errors' => ['HTTP: ' . $err], 'http_code' => $http];
		}

		$decoded = json_decode((string)$raw, true);
		if (!is_array($decoded)) {
			if ($http >= 500 || $http === 429 || $http === 0) {
				Guard::failure('HTTP ' . $http);
			} else {
				// A 200 maintenance page is an outage too.
				Guard::failure('Invalid JSON (HTTP ' . $http . ')');
			}
			return ['success' => false, 'unavailable' => true, 'errors' => ['Invalid JSON response (HTTP ' . $http . ')'], 'http_code' => $http];
		}
		if ($http >= 500) {
			Guard::failure('HTTP ' . $http);
			$decoded['success'] = false;
			$decoded['unavailable'] = true;
			return $decoded;
		}

		// The API answered. Business errors («City not found») are not an outage;
		// «service temporarily unavailable» spelled as success:false is.
		if (empty($decoded['success']) && self::isUnavailable($decoded)) {
			Guard::failure(implode('; ', array_map('strval', (array)($decoded['errors'] ?? []))));
			$decoded['unavailable'] = true;
			return $decoded;
		}
		Guard::success();
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
		$rawName = trim($args['recipient_name'] ?? '');
		// NP rejects Latin in names — transliterate before sending.
		$rawName = Translit::toCyrillic($rawName);
		$names = preg_split('/\s+/', $rawName) ?: [];
		$firstName  = (string)($names[0] ?? '');
		$lastName   = (string)($names[1] ?? $firstName);
		$middleName = (string)($names[2] ?? '');
		$phone      = Translit::normalizePhone((string)$args['recipient_phone']);

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

		$payload = [
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
		];
		// COD: if cod_amount provided, attach BackwardDelivery for cash-on-delivery.
		if (!empty($args['cod_amount']) && (float)$args['cod_amount'] > 0) {
			$payload['BackwardDeliveryData'] = [[
				'PayerType'        => 'Recipient',
				'CargoType'        => 'Money',
				'RedeliveryString' => (string)max((int)round((float)$args['cod_amount']), 1),
			]];
		}
		return $this->call('InternetDocument', 'save', $payload);
	}

	/**
	 * Create a return TTN against an existing shipment. Uses the standard
	 * "refusal of delivery" reason — sender becomes the recipient of the return.
	 */
	public function createReturn(string $intDocNumber, string $newWarehouseRef, string $reasonRef = '49754eb2-a9e1-11e3-9fa0-0050568002cf', string $subtypeReasonRef = '49754ec8-a9e1-11e3-9fa0-0050568002cf'): array {
		return $this->call('AdditionalServiceGeneral', 'save', [
			'IntDocNumber'       => $intDocNumber,
			'PaymentMethod'      => 'Cash',
			'Reason'             => $reasonRef,
			// 🔴 SubtypeReason is mandatory: without it every return dies with
			// "SubtypeReason is not specified" before the parcel is even looked at.
			'SubtypeReason'      => $subtypeReasonRef,
			'OrderType'          => 'orderCargoReturn',
			'ServiceType'        => 'WarehouseWarehouse',
			// 🔴 NOT ReturnAddressRef: that field wants an address Ref owned by the
			// counterparty, so a warehouse Ref comes back as "ReturnAddressRef not
			// found". A return to a branch goes in RecipientWarehouse.
			'RecipientWarehouse' => $newWarehouseRef,
		]);
	}

	/** Ask Nova Poshta whether this waybill may be returned at all. */
	public function checkReturnPossible(string $intDocNumber): array {
		return $this->call('AdditionalServiceGeneral', 'CheckPossibilityCreateReturn', [
			'Number' => $intDocNumber,
		]);
	}

	/**
	 * Fetch our own shipments list for COD reconciliation. Returns rows with
	 * BackwardDeliverySum / MoneyTransferNumber when payouts are issued.
	 */
	public function getOwnShipments(string $dateFrom, string $dateTo, int $page = 1): array {
		return $this->call('InternetDocument', 'getDocumentList', [
			'DateTimeFrom' => $dateFrom,
			'DateTimeTo'   => $dateTo,
			'GetFullList'  => '1',
			'Page'         => (string)$page,
		]);
	}
}
