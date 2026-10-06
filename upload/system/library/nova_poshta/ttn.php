<?php
namespace Opencart\System\Library\NovaPoshta;

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/license.php';

/**
 * Creates the waybill (TTN) for an order and keeps a queue for the ones the
 * API could not take.
 *
 * Auto-TTN runs inside the request that sets the trigger status — for a
 * cash-on-delivery order that is the buyer's own «Confirm order» click. With
 * the API hanging that click used to wait 15 s per call and the draft ended as
 * «Помилка ТТН: … timed out» for good. Now an unreachable API (or the open
 * breaker, which answers at once) puts the shipment in the queue
 * (status_code = QUEUED) and the hourly cron / the Shipments page retry it;
 * only a real refusal by Nova Poshta («wrong phone», …) is shown as an error.
 *
 * Settings are read through $config with the `shipping_nova_poshta_` prefix.
 */
final class Ttn {
	public const QUEUED       = -1;
	public const MAX_ATTEMPTS = 48;      // ~2 days of hourly retries
	private const PREFIX      = 'shipping_nova_poshta_';

	private static function cut(string $s, int $len): string {
		if (function_exists('mb_substr')) {
			return mb_substr($s, 0, $len);
		}
		return preg_match('/^.{0,' . $len . '}/us', $s, $m) ? $m[0] : substr($s, 0, $len);
	}

	private static function setting($config, string $key): string {
		return (string)$config->get(self::PREFIX . $key);
	}

	/**
	 * Try to create the TTN of one np_shipment row now.
	 *
	 * $interactive: a buyer or the admin is waiting on this request (order
	 * confirmation, status change) — a typed branch is then looked up in the saved
	 * directory only, never downloaded; what cannot be resolved goes to the queue.
	 *
	 * @return array{state:string, message:string} state: created | queued | error | skipped
	 */
	public static function create($db, $config, array $row, bool $interactive = false): array {
		$order_id = (int)$row['order_id'];
		$shipId   = (int)$row['shipment_id'];
		$key = Crypto::decrypt(self::setting($config, 'api_key'));
		if ($key === '') {
			return ['state' => 'skipped', 'message' => 'no API key'];
		}
		$sender = [
			'ref'     => self::setting($config, 'sender_counterparty_ref'),
			'contact' => self::setting($config, 'sender_contact_ref'),
			'phone'   => self::setting($config, 'sender_phone'),
			'city'    => self::setting($config, 'sender_city_ref'),
			'wh'      => self::setting($config, 'sender_warehouse_ref'),
		];
		if (in_array('', $sender, true)) {
			$db->query("UPDATE `" . DB_PREFIX . "np_shipment` SET status_code = 0, status_text = 'Не налаштовано відправника', last_polled_at = NOW() WHERE shipment_id = " . $shipId);
			return ['state' => 'error', 'message' => 'sender not configured'];
		}

		$order = $db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE order_id = " . $order_id)->row;
		if (!$order) {
			return ['state' => 'skipped', 'message' => 'no order'];
		}
		$products = $db->query("SELECT op.name, op.quantity, op.price, COALESCE(p.weight, 0) AS weight FROM `" . DB_PREFIX . "order_product` op LEFT JOIN `" . DB_PREFIX . "product` p ON p.product_id = op.product_id WHERE op.order_id = " . $order_id)->rows;
		$weight = 0.0;
		$cost   = 0.0;
		$descParts = [];
		foreach ($products as $p) {
			$weight += (float)$p['weight'] * (int)$p['quantity'];
			$cost   += (float)$p['price']  * (int)$p['quantity'];
			$descParts[] = trim((string)$p['name']);
		}
		if ($weight <= 0) $weight = 0.5;
		if ($cost   <= 0) $cost   = (float)($order['total'] ?? 100);

		$client = new Client($key, $db);

		// An order placed while the API was down may carry a typed city / branch
		// («manual» in the widget). Resolve them against the directory now, so
		// the queue creates a real waybill instead of a guaranteed refusal.
		$cityRef = (string)$row['recipient_city_ref'];
		$whRef   = (string)$row['recipient_warehouse_ref'];
		if (!Cache::isRef($cityRef)) {
			$cityRef = Cache::cityRefByName($db, (string)($order['shipping_city'] ?? ''));
		}
		if (Cache::isRef($cityRef) && !Cache::isRef($whRef)) {
			$found = self::branchByName($db, $cityRef, (string)($order['shipping_address_1'] ?? ''), $interactive ? '' : $key);
			if ($found === null) {
				// No branch list for this city yet (API down when it was asked for):
				// the retry downloads it and matches the number.
				return self::queue($db, $row, 'відділення введено вручну, уточнимо за довідником Нової Пошти');
			}
			$whRef = $found;
		}
		if (!Cache::isRef($cityRef) || !Cache::isRef($whRef)) {
			if (!Guard::status()['down']) {
				$db->query("UPDATE `" . DB_PREFIX . "np_shipment` SET status_code = 0, status_text = '" . $db->escape(self::cut('Помилка ТТН: місто або відділення введено вручну (' . trim((string)($order['shipping_city'] ?? '') . ', ' . (string)($order['shipping_address_1'] ?? '')) . ') — створіть ТТН у кабінеті НП', 250)) . "', last_polled_at = NOW() WHERE shipment_id = " . $shipId);
				return ['state' => 'error', 'message' => 'manual address'];
			}
			return self::queue($db, $row, 'довідник недоступний');
		}
		if ($cityRef !== (string)$row['recipient_city_ref'] || $whRef !== (string)$row['recipient_warehouse_ref']) {
			$db->query("UPDATE `" . DB_PREFIX . "np_shipment` SET recipient_city_ref = '" . $db->escape($cityRef) . "', recipient_warehouse_ref = '" . $db->escape($whRef) . "' WHERE shipment_id = " . $shipId);
		}

		if ($interactive && Guard::degraded()) {
			// Somebody is waiting on this request and the API has just failed: queue
			// now, the retry creates the waybill once a call gets through.
			return self::queue($db, $row, 'API Нової Пошти недоступне');
		}

		// Cash on delivery is Pro. OpenCart 4.0.2+ stores the payment method as
		// JSON ({"code":"cod.cod"}); older 4.0 kept a payment_code column.
		$payCode = (string)($order['payment_code'] ?? '');
		if ($payCode === '' && !empty($order['payment_method'])) {
			$pm = json_decode((string)$order['payment_method'], true);
			$payCode = is_array($pm) ? (string)($pm['code'] ?? '') : '';
		}
		$isCod = ($payCode === 'cod' || strpos($payCode, 'cod.') === 0);
		$codAmount = (License::isPro($config) && $isCod) ? self::codBase($db, $order_id, $cost) : 0.0;

		$resp = $client->createTTN([
			'sender_ref'              => $sender['ref'],
			'sender_contact_ref'      => $sender['contact'],
			'sender_phone'            => $sender['phone'],
			'sender_city_ref'         => $sender['city'],
			'sender_warehouse_ref'    => $sender['wh'],
			'recipient_city_ref'      => $cityRef,
			'recipient_warehouse_ref' => $whRef,
			'recipient_name'          => trim(($order['shipping_firstname'] ?? '') . ' ' . ($order['shipping_lastname'] ?? '')) ?: trim(($order['firstname'] ?? '') . ' ' . ($order['lastname'] ?? '')),
			'recipient_phone'         => (string)($order['telephone'] ?? ''),
			'weight'                  => $weight,
			'cost'                    => $cost,
			'cod_amount'              => $codAmount,
			'description'             => self::cut(implode(', ', $descParts) ?: 'Order #' . $order_id, 200),
			'service_type'            => 'WarehouseWarehouse',
			'payer_type'              => 'Recipient',
			'payment_method'          => 'Cash',
			'cargo_type'              => 'Cargo',
			'seats_amount'            => 1,
		]);

		if (!empty($resp['success']) && !empty($resp['data'][0]['IntDocNumber'])) {
			$intDoc = (string)$resp['data'][0]['IntDocNumber'];
			$intRef = (string)($resp['data'][0]['Ref'] ?? '');
			$db->query("UPDATE `" . DB_PREFIX . "np_shipment` SET int_doc_number = '" . $db->escape($intDoc) . "', int_doc_ref = '" . $db->escape($intRef) . "', status_code = 1, status_text = 'Створено', weight = " . (float)$weight . ", declared_cost = " . (float)$cost . ", cod_amount = " . (float)$codAmount . ", recipient_phone = '" . $db->escape((string)($order['telephone'] ?? '')) . "', last_polled_at = NOW() WHERE shipment_id = " . $shipId);
			return ['state' => 'created', 'message' => $intDoc];
		}
		if (Client::isUnavailable($resp)) {
			return self::queue($db, $row, 'API Нової Пошти недоступне');
		}
		$err = is_array($resp['errors'] ?? null) ? implode('; ', $resp['errors']) : 'unknown error';
		$db->query("UPDATE `" . DB_PREFIX . "np_shipment` SET status_code = 0, status_text = '" . $db->escape(self::cut('Помилка ТТН: ' . $err, 250)) . "', last_polled_at = NOW() WHERE shipment_id = " . $shipId);
		return ['state' => 'error', 'message' => $err];
	}

	/**
	 * Branch Ref for a typed «Відділення 12» / «№12» from the city's directory
	 * (downloaded when $key is given and the saved list is stale or missing).
	 * '' = the list is there and has no such branch; null = no list to look in.
	 */
	private static function branchByName($db, string $cityRef, string $typed, string $key): ?string {
		if (!preg_match('/(\d+)/u', $typed, $m)) {
			return '';
		}
		$num  = $m[1];
		$list = Cache::getWarehouses($db, $cityRef, $key);
		if (!$list) {
			return null;
		}
		foreach ($list as $w) {
			if ((string)$w['number'] === $num) {
				return (string)$w['ref'];
			}
		}
		return '';
	}

	/**
	 * Cash on delivery = what the buyer owes the shop: the order total minus the
	 * delivery lines (PayerType Recipient — the buyer pays Nova Poshta for the
	 * delivery at the branch). Coupons, vouchers, store credit, taxes and fees
	 * are in the total; the bare sum of product prices is not what the buyer
	 * agreed to pay (a -10% coupon was collected in full). Falls back to that
	 * sum only for an order without totals.
	 */
	private static function codBase($db, int $order_id, float $fallback): float {
		$total = null;
		$ship  = 0.0;
		foreach ($db->query("SELECT `code`, `value` FROM `" . DB_PREFIX . "order_total` WHERE order_id = " . $order_id)->rows as $t) {
			if ($t['code'] === 'total') {
				$total = (float)$t['value'];
			} elseif ($t['code'] === 'shipping') {
				$ship += (float)$t['value'];
			}
		}
		if ($total === null) {
			return $fallback;
		}
		return max(0.0, round($total - $ship, 2));
	}

	private static function queue($db, array $row, string $why): array {
		$attempt = 1;
		if ((int)$row['status_code'] === self::QUEUED && preg_match('/спроба (\d+)/u', (string)$row['status_text'], $m)) {
			$attempt = (int)$m[1] + 1;
		}
		if ($attempt > self::MAX_ATTEMPTS) {
			$db->query("UPDATE `" . DB_PREFIX . "np_shipment` SET status_code = 0, status_text = 'Помилка ТТН: API Нової Пошти не відповідало 2 доби — створіть ТТН вручну', last_polled_at = NOW() WHERE shipment_id = " . (int)$row['shipment_id']);
			return ['state' => 'error', 'message' => 'gave up'];
		}
		$db->query("UPDATE `" . DB_PREFIX . "np_shipment` SET status_code = " . self::QUEUED . ", status_text = '" . $db->escape('У черзі на ТТН: ' . $why . ' (спроба ' . $attempt . ')') . "', last_polled_at = NOW() WHERE shipment_id = " . (int)$row['shipment_id']);
		return ['state' => 'queued', 'message' => $why];
	}

	/**
	 * Retry queued waybills (hourly cron, the Shipments page). Stops as soon as
	 * the API turns out to be down again; returns how many were created.
	 */
	public static function retryQueued($db, $config, int $limit = 10): int {
		Guard::bind($db);
		if (!Guard::canTry()) {
			return 0; // still inside the pause — don't burn attempts on it
		}
		try {
			$rows = $db->query("SELECT * FROM `" . DB_PREFIX . "np_shipment` WHERE status_code = " . self::QUEUED . " AND (int_doc_number IS NULL OR int_doc_number = '') AND (last_polled_at IS NULL OR last_polled_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)) ORDER BY shipment_id LIMIT " . max(1, $limit))->rows;
		} catch (\Throwable $e) {
			return 0;
		}
		$created = 0;
		foreach ($rows as $row) {
			$res = self::create($db, $config, $row);
			if ($res['state'] === 'created') {
				$created++;
			} elseif ($res['state'] === 'queued') {
				break; // still down — the rest waits for the next run
			}
		}
		return $created;
	}

	public static function queuedCount($db): int {
		try {
			return (int)$db->query("SELECT COUNT(*) AS cnt FROM `" . DB_PREFIX . "np_shipment` WHERE status_code = " . self::QUEUED)->row['cnt'];
		} catch (\Throwable $e) {
			return 0;
		}
	}
}
