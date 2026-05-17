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
		curl_close($ch);

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
}
