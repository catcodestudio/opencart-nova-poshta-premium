<?php
namespace Opencart\System\Library\NovaPoshta;

/**
 * Store zone (oblast) for a Nova Poshta city.
 *
 * The NP classifier names the oblast in Cyrillic ("Київська"), while most
 * stores keep the Ukraine zone list transliterated ("Kyivs'ka Oblast'",
 * "Kyivska"). A raw comparison never matched, so the order kept whatever the
 * hidden native address form held — the FIRST zone of the country
 * («Автономна Республіка Крим») for a Kyiv branch. Keys are compared after
 * transliteration; with no unambiguous match nothing is returned — an empty
 * region is honest, a random one is not.
 */
class Zones {
	private const TRANSLIT = [
		'А' => 'a', 'Б' => 'b', 'В' => 'v', 'Г' => 'h', 'Ґ' => 'g', 'Д' => 'd', 'Е' => 'e', 'Є' => 'ie',
		'Ж' => 'zh', 'З' => 'z', 'И' => 'y', 'І' => 'i', 'Ї' => 'i', 'Й' => 'i', 'К' => 'k', 'Л' => 'l',
		'М' => 'm', 'Н' => 'n', 'О' => 'o', 'П' => 'p', 'Р' => 'r', 'С' => 's', 'Т' => 't', 'У' => 'u',
		'Ф' => 'f', 'Х' => 'kh', 'Ц' => 'ts', 'Ч' => 'ch', 'Ш' => 'sh', 'Щ' => 'shch', 'Ь' => '',
		'Ю' => 'iu', 'Я' => 'ia', 'Ы' => 'y', 'Э' => 'e', 'Ъ' => '', 'Ё' => 'e',
		'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'є' => 'ie',
		'ж' => 'zh', 'з' => 'z', 'и' => 'y', 'і' => 'i', 'ї' => 'i', 'й' => 'i', 'к' => 'k', 'л' => 'l',
		'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
		'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ь' => '',
		'ю' => 'iu', 'я' => 'ia', 'ы' => 'y', 'э' => 'e', 'ъ' => '', 'ё' => 'e',
	];

	/** Cities with a zone of their own — never to be confused with the oblast around them. */
	private const CITY_ZONES = ['kyiv', 'sevastopol'];

	private static array $cache = [];

	/** Comparison key: transliterated, lowercased, a-z only, without the word "oblast". */
	public static function key(string $value): string {
		$s = (string)preg_replace('/\s*(область|обл\.|oblast\'?|oblast’?)\s*/iu', ' ', $value);
		$s = strtolower(strtr($s, self::TRANSLIT));
		return (string)preg_replace('/[^a-z]/', '', $s);
	}

	/**
	 * @return array{zone_id:int, code:string, name:string}|array{} empty when there is no unambiguous match
	 */
	public static function resolve($db, string $area, string $city = '', int $languageId = 0, int $countryId = 0): array {
		$rows = self::all($db, $languageId, $countryId);
		if (!$rows) {
			return [];
		}

		// Kyiv / Sevastopol: NP files the city under the surrounding oblast
		// («Київ» → «Київська»), but the store has a separate city zone for it.
		$cityKey = self::key((string)preg_replace('/^\s*(м\.|місто)\s*/u', '', $city));
		if (in_array($cityKey, self::CITY_ZONES, true)) {
			foreach ($rows as $row) {
				if ($row['key'] === $cityKey) {
					return self::out($row);
				}
			}
		}

		$key = self::key($area);
		if ($key === '') {
			return [];
		}
		foreach ($rows as $row) {
			if ($row['key'] === $key) {
				return self::out($row);
			}
		}
		// «Київська» → «Kyivs'ka Oblast'» / «Kyivskaya»: the zone name starts with the key.
		foreach ($rows as $row) {
			if (strpos($row['key'], $key) === 0) {
				return self::out($row);
			}
		}
		// The other way round, but never onto a city zone («kyivska» must not land on «Kyiv»).
		foreach ($rows as $row) {
			if (!in_array($row['key'], self::CITY_ZONES, true) && strlen($row['key']) >= 4 && strpos($key, $row['key']) === 0) {
				return self::out($row);
			}
		}
		return [];
	}

	private static function out(array $row): array {
		return ['zone_id' => (int)$row['zone_id'], 'code' => (string)$row['code'], 'name' => (string)$row['name']];
	}

	/**
	 * All active zones of the country. OpenCart 4.1 keeps zone names per
	 * language in `zone_description`, 4.0 on `zone.name` — both are handled.
	 * Keys come from every language (a store may list zones only in Latin or
	 * only in Cyrillic); the name written to the order is the storefront one.
	 */
	private static function all($db, int $languageId, int $countryId): array {
		$ck = $languageId . ':' . $countryId;
		if (isset(self::$cache[$ck])) {
			return self::$cache[$ck];
		}
		if ($countryId <= 0) {
			$row = $db->query("SELECT country_id FROM `" . DB_PREFIX . "country` WHERE iso_code_2 = 'UA'")->row;
			$countryId = $row ? (int)$row['country_id'] : 0;
		}
		if ($countryId <= 0) {
			return self::$cache[$ck] = [];
		}

		$perLanguage = (bool)$db->query("SHOW TABLES LIKE '" . DB_PREFIX . "zone_description'")->num_rows;
		if ($perLanguage) {
			$sql = "SELECT z.zone_id, z.code, zd.name, zd.language_id FROM `" . DB_PREFIX . "zone` z LEFT JOIN `" . DB_PREFIX . "zone_description` zd ON (zd.zone_id = z.zone_id) WHERE z.country_id = " . (int)$countryId . " AND z.status = '1' ORDER BY z.zone_id";
		} else {
			$sql = "SELECT zone_id, code, name, 0 AS language_id FROM `" . DB_PREFIX . "zone` WHERE country_id = " . (int)$countryId . " AND status = '1' ORDER BY zone_id";
		}

		$out = [];
		$display = [];
		foreach ($db->query($sql)->rows as $r) {
			$name = (string)($r['name'] ?? '');
			$key  = self::key($name);
			if ($key === '') {
				continue;
			}
			$zid = (int)$r['zone_id'];
			if (!isset($display[$zid]) || (int)$r['language_id'] === $languageId) {
				$display[$zid] = $name;
			}
			$out[] = ['zone_id' => $zid, 'code' => (string)($r['code'] ?? ''), 'name' => $name, 'key' => $key];
		}
		foreach ($out as &$row) {
			$row['name'] = $display[$row['zone_id']];
		}
		unset($row);

		return self::$cache[$ck] = $out;
	}
}
