<?php
namespace Opencart\System\Library\NovaPoshta;

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/guard.php';

/**
 * Cache-first lookup for cities (full sync weekly) and warehouses (lazy per-city, 7-day TTL).
 * Falls back to live NP API on cache miss + populates the cache.
 *
 * When the API is down the saved directory is the answer, whatever its age:
 * a branch list from last week is still a working list, an empty one stops the
 * order. The live API is only asked while Guard lets it (see guard.php).
 */
class Cache {
	private const WAREHOUSE_TTL_DAYS = 7;
	/** Rows from which np_cities counts as fully synced. */
	const FULL_DIRECTORY = 3000;
	// Seconds per call for the directory downloads (a Kyiv page is ~500 rows).
	private const DIRECTORY_TIMEOUT = 8;

	/**
	 * The 14 cities the checkout widget offers as one-click picks: their branch
	 * lists are refreshed by the weekly cron so they are there when the API is not.
	 */
	public const POPULAR = [
		'8d5a980d-391c-11dd-90d9-001a92567626', 'db5c88e0-391c-11dd-90d9-001a92567626', 'db5c88d0-391c-11dd-90d9-001a92567626',
		'db5c88f0-391c-11dd-90d9-001a92567626', 'db5c88f5-391c-11dd-90d9-001a92567626', 'db5c88c6-391c-11dd-90d9-001a92567626',
		'db5c890d-391c-11dd-90d9-001a92567626', 'db5c888c-391c-11dd-90d9-001a92567626', 'db5c88de-391c-11dd-90d9-001a92567626',
		'db5c8892-391c-11dd-90d9-001a92567626', 'db5c897c-391c-11dd-90d9-001a92567626', 'db5c8902-391c-11dd-90d9-001a92567626',
		'db5c8904-391c-11dd-90d9-001a92567626', 'db5c8900-391c-11dd-90d9-001a92567626',
	];

	/**
	 * mbstring is not guaranteed on shared hosting. The ASCII fallback leaves
	 * Cyrillic case untouched, which is harmless: the LIKE below runs against a
	 * case-insensitive collation, and a miss just falls through to the live API.
	 */
	private static function lower(string $s): string {
		return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
	}

	public static function searchCities($db, string $query, string $apiKey): array {
		// Every escape() result must sit inside its own quotes with nothing else:
		// on the PDO driver escape() returns a bound placeholder (":0"), so
		// appending a wildcard inside the quotes ("LIKE ':0%'") breaks the SQL.
		// Wildcards therefore go through escape() as part of the value.
		$lower  = self::lower($query);
		$prefix = $db->escape($lower . '%');
		$any    = $db->escape('%' . $lower . '%');
		$exact  = $db->escape($lower);
		$rows = $db->query("SELECT ref, description, area_description FROM `" . DB_PREFIX . "np_cities` WHERE LOWER(description) LIKE '" . $prefix . "' OR LOWER(description) LIKE '" . $any . "' ORDER BY (LOWER(description) = '" . $exact . "') DESC, CHAR_LENGTH(description) ASC LIMIT 20")->rows;
		$cached = array_map(fn($r) => ['ref' => (string)$r['ref'], 'name' => (string)$r['description'], 'area' => (string)$r['area_description']], $rows);
		// A sparse directory (no full sync yet) can match only «Крива (Київська обл.)»
		// for «Київ» — the city itself is then never offered, so such a hit is not an answer.
		foreach ($cached as $c) {
			if (self::lower($c['name']) === $lower) return $cached;
		}
		if ($cached && (int)$db->query("SELECT COUNT(*) AS cnt FROM `" . DB_PREFIX . "np_cities`")->row['cnt'] >= self::FULL_DIRECTORY) return $cached;
		// Miss: live fetch and store this batch (not full sync — incremental warmup).
		if ($apiKey === '') return $cached;
		$client = new Client($apiKey, $db);
		$resp = $client->call('Address', 'getCities', ['FindByString' => $query, 'Limit' => '20']);
		if (empty($resp['success']) || !is_array($resp['data'] ?? null)) return $cached;
		$out = [];
		foreach ($resp['data'] as $row) {
			$ref  = (string)($row['Ref'] ?? '');
			$desc = (string)($row['Description'] ?? '');
			$area = (string)($row['AreaDescription'] ?? '');
			if ($ref === '') continue;
			$db->query("INSERT INTO `" . DB_PREFIX . "np_cities` SET ref = '" . $db->escape($ref) . "', description = '" . $db->escape($desc) . "', area_description = '" . $db->escape($area) . "', updated_at = NOW() ON DUPLICATE KEY UPDATE description = VALUES(description), area_description = VALUES(area_description), updated_at = VALUES(updated_at)");
			$out[] = ['ref' => $ref, 'name' => $desc, 'area' => $area];
		}
		$seen = array_column($out, 'ref');
		foreach ($cached as $c) {
			if (!in_array($c['ref'], $seen, true)) $out[] = $c;
		}
		return $out;
	}

	/**
	 * Oblast of a city straight from the module's own directory — the order
	 * region is decided on the server, never from what the browser posted.
	 */
	public static function cityArea($db, string $cityRef): string {
		if ($cityRef === '') {
			return '';
		}
		$row = $db->query("SELECT area_description FROM `" . DB_PREFIX . "np_cities` WHERE ref = '" . $db->escape($cityRef) . "'")->row;
		return $row ? trim((string)$row['area_description']) : '';
	}

	/** Cached rows for a city regardless of age — the stale-but-usable fallback. */
	private static function warehouseRows($db, string $cityRef): array {
		$rows = $db->query("SELECT ref, number, description, type_ref FROM `" . DB_PREFIX . "np_warehouses` WHERE city_ref = '" . $db->escape($cityRef) . "' ORDER BY CAST(number AS UNSIGNED), number")->rows;
		return array_map(fn($r) => [
			'ref'         => (string)$r['ref'],
			'number'      => (string)$r['number'],
			'description' => (string)$r['description'],
			'type_ref'    => (string)$r['type_ref'],
		], $rows);
	}

	public static function getWarehouses($db, string $cityRef, string $apiKey): array {
		// Check freshness: any cached row for this city within TTL?
		$fresh = $db->query("SELECT COUNT(*) AS cnt FROM `" . DB_PREFIX . "np_warehouses` WHERE city_ref = '" . $db->escape($cityRef) . "' AND updated_at > DATE_SUB(NOW(), INTERVAL " . self::WAREHOUSE_TTL_DAYS . " DAY)")->row;
		if (!empty($fresh) && (int)$fresh['cnt'] > 0) {
			return self::warehouseRows($db, $cityRef);
		}
		// Miss / stale: fetch live, repopulate, return.
		if ($apiKey === '' || !self::isRef($cityRef)) return self::warehouseRows($db, $cityRef);
		$client = new Client($apiKey, $db, self::DIRECTORY_TIMEOUT);
		// The API returns at most Limit rows and does not say the list was cut:
		// Kyiv has well over 500 branches and parcel lockers, so without paging
		// the tail of the list never arrived.
		$rows = [];
		$page = 1;
		$ok   = false;
		while ($page <= 40) {
			$resp = $client->call('Address', 'getWarehouses', ['CityRef' => $cityRef, 'Limit' => '500', 'Page' => (string)$page]);
			if (empty($resp['success']) || !isset($resp['data']) || !is_array($resp['data'])) {
				$ok = false; // a half-downloaded list must not replace a whole one
				break;
			}
			$ok = true;
			$chunk = $resp['data'];
			if (!$chunk) break;
			$rows = array_merge($rows, $chunk);
			if (count($chunk) < 500) break;
			$page++;
		}
		// API down or empty answer: leave the cache alone and serve what it has,
		// however old. «TTL expired + API down right now» used to show the buyer an
		// empty list instead of last week's working one, and the order stopped.
		if (!$ok || !$rows) return self::warehouseRows($db, $cityRef);
		// Wipe stale rows for this city to avoid stale-leftover ghosts.
		$db->query("DELETE FROM `" . DB_PREFIX . "np_warehouses` WHERE city_ref = '" . $db->escape($cityRef) . "'");
		$out = [];
		foreach ($rows as $row) {
			$ref  = (string)($row['Ref'] ?? '');
			$num  = (string)($row['Number'] ?? '');
			$desc = (string)($row['Description'] ?? '');
			$type = (string)($row['TypeOfWarehouse'] ?? '');
			if ($ref === '') continue;
			$db->query("INSERT INTO `" . DB_PREFIX . "np_warehouses` SET ref = '" . $db->escape($ref) . "', city_ref = '" . $db->escape($cityRef) . "', number = '" . $db->escape($num) . "', description = '" . $db->escape($desc) . "', type_ref = '" . $db->escape($type) . "', updated_at = NOW() ON DUPLICATE KEY UPDATE city_ref = VALUES(city_ref), number = VALUES(number), description = VALUES(description), type_ref = VALUES(type_ref), updated_at = VALUES(updated_at)");
			$out[] = ['ref' => $ref, 'number' => $num, 'description' => $desc, 'type_ref' => $type];
		}
		return $out;
	}

	/**
	 * Weekly cron: refresh the branch lists of the popular cities and of the
	 * cities the shop shipped to lately, so a checkout during an API outage finds
	 * them in the directory. Lists younger than half the TTL are left alone; stops
	 * at the first sign the API is down.
	 */
	public static function warmWarehouses($db, string $apiKey, array $extraRefs = []): int {
		if ($apiKey === '') return 0;
		$refs = self::POPULAR;
		try {
			foreach ($db->query("SELECT recipient_city_ref, MAX(created_at) AS last_at FROM `" . DB_PREFIX . "np_shipment` WHERE created_at > DATE_SUB(NOW(), INTERVAL 60 DAY) AND recipient_city_ref <> '' GROUP BY recipient_city_ref ORDER BY last_at DESC LIMIT 40")->rows as $r) {
				$refs[] = (string)$r['recipient_city_ref'];
			}
		} catch (\Throwable $e) {
			// No shipments table yet — popular cities only.
		}
		$refs = array_values(array_unique(array_filter(array_merge($refs, $extraRefs), [self::class, 'isRef'])));
		$done = 0;
		foreach ($refs as $ref) {
			if (Guard::status()['down']) {
				break;
			}
			$fresh = $db->query("SELECT COUNT(*) AS cnt FROM `" . DB_PREFIX . "np_warehouses` WHERE city_ref = '" . $db->escape($ref) . "' AND updated_at > DATE_SUB(NOW(), INTERVAL " . (int)(self::WAREHOUSE_TTL_DAYS * 12) . " HOUR)")->row;
			if (!empty($fresh) && (int)$fresh['cnt'] > 0) {
				continue;
			}
			// Make the TTL check inside getWarehouses() see the list as stale.
			$db->query("UPDATE `" . DB_PREFIX . "np_warehouses` SET updated_at = DATE_SUB(NOW(), INTERVAL " . (self::WAREHOUSE_TTL_DAYS + 1) . " DAY) WHERE city_ref = '" . $db->escape($ref) . "'");
			if (self::getWarehouses($db, $ref, $apiKey)) {
				$done++;
			}
		}
		return $done;
	}

	/** A Nova Poshta Ref (UUID), not the widget's «manual» marker or a typed name. */
	public static function isRef($ref): bool {
		return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string)$ref);
	}

	/** City Ref by its exact name from the directory ('' when absent or ambiguous). */
	public static function cityRefByName($db, string $name): string {
		$name = trim($name);
		if ($name === '') {
			return '';
		}
		$rows = $db->query("SELECT ref FROM `" . DB_PREFIX . "np_cities` WHERE description = '" . $db->escape($name) . "' LIMIT 2")->rows;
		return count($rows) === 1 ? (string)$rows[0]['ref'] : '';
	}

	// --- tariffs ----------------------------------------------------------------

	/** Whole kilograms, rounded up: the tariff steps by weight. */
	private static function weightKey(float $weightKg): int {
		return max(1, (int)ceil($weightKg));
	}

	private static function ensureRateTable($db): void {
		$db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "np_rate_cache` (
			`sender_city_ref` varchar(64) NOT NULL,
			`recipient_city_ref` varchar(64) NOT NULL,
			`weight_kg` int NOT NULL,
			`cost` decimal(15,2) NOT NULL,
			`updated_at` datetime NOT NULL,
			PRIMARY KEY (`sender_city_ref`, `recipient_city_ref`, `weight_kg`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
	}

	/** Remember a tariff the API just quoted: the outage fallback for this route and weight. */
	public static function rememberRate($db, string $sender, string $recipient, float $weightKg, float $cost): void {
		if ($cost <= 0 || !self::isRef($sender) || !self::isRef($recipient)) {
			return;
		}
		// Both refs are validated UUIDs (isRef), so they go into the SQL as they are,
		// without $db->escape(): on OpenCart 4.0.2.x with the PDO driver escape()
		// binds a parameter, and a statement that fails (table missing — files
		// updated without «Setup») leaves that parameter bound for the next query
		// of the request. The retry below and, at the end of the request, the
		// session write then fail too — measured on 4.0.2.3/PDO: the live tariff
		// was quoted but the order got the flat price.
		$sql = "INSERT INTO `" . DB_PREFIX . "np_rate_cache` SET sender_city_ref = '" . strtolower($sender) . "', recipient_city_ref = '" . strtolower($recipient) . "', weight_kg = " . self::weightKey($weightKg) . ", cost = " . (float)$cost . ", updated_at = NOW() ON DUPLICATE KEY UPDATE cost = VALUES(cost), updated_at = VALUES(updated_at)";
		try {
			$db->query($sql);
		} catch (\Throwable $e) {
			// Files updated without pressing «Setup» — create the table once.
			try {
				self::ensureRateTable($db);
				$db->query($sql);
			} catch (\Throwable $e2) {
				// A tariff memo is never worth failing a quote over.
			}
		}
	}

	/** The last tariff the API quoted for this route and weight, or null. */
	public static function lastRate($db, string $sender, string $recipient, float $weightKg): ?float {
		if (!self::isRef($sender) || !self::isRef($recipient)) {
			return null;
		}
		try {
			// No escape() here either (validated refs) — see rememberRate().
			$row = $db->query("SELECT cost FROM `" . DB_PREFIX . "np_rate_cache` WHERE sender_city_ref = '" . strtolower($sender) . "' AND recipient_city_ref = '" . strtolower($recipient) . "' AND weight_kg = " . self::weightKey($weightKg))->row;
		} catch (\Throwable $e) {
			return null;
		}
		return ($row && (float)$row['cost'] > 0) ? (float)$row['cost'] : null;
	}

	public static function syncCities($db, string $apiKey): array {
		if ($apiKey === '') return ['ok' => false, 'inserted' => 0];
		$client = new Client($apiKey, $db, 15);
		$page = 1;
		$inserted = 0;
		while ($page < 500) { // safety bound
			$resp = $client->call('Address', 'getCities', ['Limit' => '150', 'Page' => (string)$page]);
			if (empty($resp['success']) || !is_array($resp['data'] ?? null) || !$resp['data']) {
				break;
			}
			foreach ($resp['data'] as $row) {
				$ref  = (string)($row['Ref'] ?? '');
				$desc = (string)($row['Description'] ?? '');
				$area = (string)($row['AreaDescription'] ?? '');
				if ($ref === '') continue;
				$db->query("INSERT INTO `" . DB_PREFIX . "np_cities` SET ref = '" . $db->escape($ref) . "', description = '" . $db->escape($desc) . "', area_description = '" . $db->escape($area) . "', updated_at = NOW() ON DUPLICATE KEY UPDATE description = VALUES(description), area_description = VALUES(area_description), updated_at = VALUES(updated_at)");
				$inserted++;
			}
			if (count($resp['data']) < 150) break;
			$page++;
		}
		return ['ok' => true, 'inserted' => $inserted];
	}
}
