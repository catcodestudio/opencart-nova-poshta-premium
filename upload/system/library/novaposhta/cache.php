<?php

require_once __DIR__ . '/client.php';

/**
 * Cache-first lookup for cities (full sync nightly) and warehouses (lazy per-city, 7-day TTL).
 * Falls back to live NP API on cache miss + populates the cache.
 */
class NpCache {
	private const WAREHOUSE_TTL_DAYS = 7;

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
		if ($rows) {
			return array_map(fn($r) => ['ref' => (string)$r['ref'], 'name' => (string)$r['description'], 'area' => (string)$r['area_description']], $rows);
		}
		// Miss: live fetch and store this batch (not full sync — incremental warmup).
		if ($apiKey === '') return [];
		$client = new NpClient($apiKey);
		$resp = $client->call('Address', 'getCities', ['FindByString' => $query, 'Limit' => '20']);
		if (empty($resp['success']) || !is_array($resp['data'] ?? null)) return [];
		$out = [];
		foreach ($resp['data'] as $row) {
			$ref  = (string)($row['Ref'] ?? '');
			$desc = (string)($row['Description'] ?? '');
			$area = (string)($row['AreaDescription'] ?? '');
			if ($ref === '') continue;
			$db->query("INSERT INTO `" . DB_PREFIX . "np_cities` SET ref = '" . $db->escape($ref) . "', description = '" . $db->escape($desc) . "', area_description = '" . $db->escape($area) . "', updated_at = NOW() ON DUPLICATE KEY UPDATE description = VALUES(description), area_description = VALUES(area_description), updated_at = VALUES(updated_at)");
			$out[] = ['ref' => $ref, 'name' => $desc, 'area' => $area];
		}
		return $out;
	}

	public static function getWarehouses($db, string $cityRef, string $apiKey): array {
		// Check freshness: any cached row for this city within TTL?
		$fresh = $db->query("SELECT COUNT(*) AS cnt FROM `" . DB_PREFIX . "np_warehouses` WHERE city_ref = '" . $db->escape($cityRef) . "' AND updated_at > DATE_SUB(NOW(), INTERVAL " . self::WAREHOUSE_TTL_DAYS . " DAY)")->row;
		if (!empty($fresh) && (int)$fresh['cnt'] > 0) {
			$rows = $db->query("SELECT ref, number, description, type_ref FROM `" . DB_PREFIX . "np_warehouses` WHERE city_ref = '" . $db->escape($cityRef) . "' ORDER BY CAST(number AS UNSIGNED), number")->rows;
			return array_map(fn($r) => [
				'ref'         => (string)$r['ref'],
				'number'      => (string)$r['number'],
				'description' => (string)$r['description'],
				'type_ref'    => (string)$r['type_ref'],
			], $rows);
		}
		// Miss / stale: fetch live, repopulate, return.
		if ($apiKey === '') return [];
		$client = new NpClient($apiKey);
		$resp = $client->call('Address', 'getWarehouses', ['CityRef' => $cityRef, 'Limit' => '500']);
		if (empty($resp['success']) || !is_array($resp['data'] ?? null)) return [];
		// Wipe stale rows for this city to avoid stale-leftover ghosts.
		$db->query("DELETE FROM `" . DB_PREFIX . "np_warehouses` WHERE city_ref = '" . $db->escape($cityRef) . "'");
		$out = [];
		foreach ($resp['data'] as $row) {
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

	public static function syncCities($db, string $apiKey): array {
		if ($apiKey === '') return ['ok' => false, 'inserted' => 0];
		$client = new NpClient($apiKey);
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
