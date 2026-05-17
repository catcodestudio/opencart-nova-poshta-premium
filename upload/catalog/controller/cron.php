<?php
namespace Opencart\Catalog\Controller\Extension\NovaPoshtaPremium;

require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/client.php';
require_once DIR_EXTENSION . 'nova_poshta_premium/system/library/nova_poshta/crypto.php';

class Cron extends \Opencart\System\Engine\Controller {
	public function pollStatus(): void {
		$key = \Opencart\System\Library\NovaPoshta\Crypto::decrypt((string)$this->config->get('shipping_nova_poshta_api_key'));
		if ($key === '') {
			return;
		}
		$rows = $this->db->query("SELECT shipment_id, int_doc_number, status_code FROM `" . DB_PREFIX . "np_shipment` WHERE int_doc_number IS NOT NULL AND int_doc_number <> '' AND status_code < 9 LIMIT 100")->rows;
		if (!$rows) {
			return;
		}
		$client = new \Opencart\System\Library\NovaPoshta\Client($key);
		$byNumber = [];
		foreach ($rows as $r) {
			$byNumber[$r['int_doc_number']] = $r;
		}
		$response = $client->trackStatus(array_keys($byNumber));
		if (empty($response['success']) || !is_array($response['data'] ?? null)) {
			return;
		}
		foreach ($response['data'] as $doc) {
			$num = (string)($doc['Number'] ?? '');
			if (!isset($byNumber[$num])) continue;
			$prev = (int)$byNumber[$num]['status_code'];
			$newCode = (int)($doc['StatusCode'] ?? 0);
			$newText = (string)($doc['Status'] ?? '');
			$shipId  = (int)$byNumber[$num]['shipment_id'];
			$this->db->query("UPDATE `" . DB_PREFIX . "np_shipment` SET status_code = " . $newCode . ", status_text = '" . $this->db->escape($newText) . "', last_polled_at = NOW() WHERE shipment_id = " . $shipId);
			if ($newCode !== $prev) {
				$this->db->query("INSERT INTO `" . DB_PREFIX . "np_shipment_history` SET shipment_id = " . $shipId . ", status_code = " . $newCode . ", status_text = '" . $this->db->escape($newText) . "', changed_at = NOW()");
				$this->queueWebhooks($shipId, 'status.changed', [
					'shipment_id'      => $shipId,
					'int_doc_number'   => $num,
					'previous_status'  => $prev,
					'current_status'   => $newCode,
					'status_text'      => $newText,
				]);
			}
		}
	}

	private function queueWebhooks(int $shipmentId, string $event, array $payload): void {
		$endpoints = $this->db->query("SELECT endpoint_id FROM `" . DB_PREFIX . "np_webhook_endpoint` WHERE status = 1 AND FIND_IN_SET('" . $this->db->escape($event) . "', events)")->rows;
		foreach ($endpoints as $ep) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "np_webhook_delivery` SET endpoint_id = " . (int)$ep['endpoint_id'] . ", shipment_id = " . $shipmentId . ", event = '" . $this->db->escape($event) . "', payload = '" . $this->db->escape(json_encode($payload)) . "', status = 'queued', next_retry_at = NOW(), created_at = NOW()");
		}
	}

	public function dispatchWebhooks(): void {
		$rows = $this->db->query("SELECT d.*, e.url, e.secret FROM `" . DB_PREFIX . "np_webhook_delivery` d INNER JOIN `" . DB_PREFIX . "np_webhook_endpoint` e ON e.endpoint_id = d.endpoint_id WHERE d.status = 'queued' AND d.next_retry_at <= NOW() AND d.attempt <= 4 LIMIT 50")->rows;
		foreach ($rows as $r) {
			$body = (string)$r['payload'];
			$signature = hash_hmac('sha256', $body, (string)$r['secret']);
			$ch = curl_init((string)$r['url']);
			curl_setopt_array($ch, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_POST           => true,
				CURLOPT_HTTPHEADER     => [
					'Content-Type: application/json',
					'X-NP-Event: ' . (string)$r['event'],
					'X-NP-Signature: sha256=' . $signature,
				],
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_TIMEOUT        => 10,
			]);
			$respBody = (string)curl_exec($ch);
			$code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$attempt  = (int)$r['attempt'];
			if ($code >= 200 && $code < 300) {
				$this->db->query("UPDATE `" . DB_PREFIX . "np_webhook_delivery` SET status = 'sent', response_code = " . $code . ", response_body = '" . $this->db->escape(substr($respBody, 0, 4000)) . "' WHERE delivery_id = " . (int)$r['delivery_id']);
			} else {
				$nextAttempt = $attempt + 1;
				$backoffMin = (int)pow(2, $attempt) * 5; // 5, 10, 20, 40 min
				if ($nextAttempt > 4) {
					$this->db->query("UPDATE `" . DB_PREFIX . "np_webhook_delivery` SET status = 'failed', attempt = " . $nextAttempt . ", response_code = " . $code . ", response_body = '" . $this->db->escape(substr($respBody, 0, 4000)) . "' WHERE delivery_id = " . (int)$r['delivery_id']);
				} else {
					$this->db->query("UPDATE `" . DB_PREFIX . "np_webhook_delivery` SET attempt = " . $nextAttempt . ", response_code = " . $code . ", response_body = '" . $this->db->escape(substr($respBody, 0, 4000)) . "', next_retry_at = DATE_ADD(NOW(), INTERVAL " . $backoffMin . " MINUTE) WHERE delivery_id = " . (int)$r['delivery_id']);
				}
			}
		}
	}

	public function licenseCheck(): void {
		// STUB: dev mode skips remote verification. Production would POST
		// to vendor license server and update shipping_nova_poshta_license_status.
		// Intentionally a no-op when no key configured.
	}
}
