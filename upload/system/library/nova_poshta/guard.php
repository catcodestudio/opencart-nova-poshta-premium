<?php
namespace Opencart\System\Library\NovaPoshta;

/**
 * Circuit breaker for api.novaposhta.ua, shared by every request of the shop.
 *
 * When the Nova Poshta API stops answering, every checkout step that asks it
 * something sits out the whole cURL timeout (15 s per call before 1.2.28 /
 * 1.3.13 / 1.3.10), PHP workers pile up behind those requests and the shop goes
 * down with the API — the storefront, the admin, everything on the same pool.
 * Measured on a local stand with 5 php-fpm workers: 8 buyers at once kept the
 * home page waiting 13–16 s.
 *
 * After FAILS_TO_OPEN consecutive transport failures (timeout, refused
 * connection, 5xx, a non-JSON page) the module stops calling the API for
 * OPEN_SECONDS and works from what it has: the city and branch directory in
 * its own tables, the last known tariff or the flat price, the TTN queue.
 * When the pause is over exactly one request probes the API (the others keep
 * skipping it for PROBE_SECONDS); one success closes the breaker, one failure
 * opens it again.
 *
 * The state is one row of its own table, changed by single atomic UPDATEs:
 * a burst of buyers hitting a hung API fails at the same moment, and a
 * read-modify-write counter would lose most of those failures (measured: the
 * breaker never opened in the first wave). The table is created by install()
 * and, for a shop that only replaced the files, on first use. Every query is
 * wrapped — the guard never breaks the request it protects. Without a bound
 * connection (CLI scripts) the guard stays closed.
 */
final class Guard {
	public const FAILS_TO_OPEN = 3;
	public const OPEN_SECONDS  = 300;
	public const PROBE_SECONDS = 20;

	private static $db = null;
	private static ?array $state = null;
	// This request holds the probe slot: its calls go through until one of them
	// settles the state (success closes, failure reopens).
	private static bool $probing = false;

	public static function bind($db): void {
		if ($db !== self::$db) {
			self::$db      = $db;
			self::$state   = null;
			self::$probing = false;
		}
	}

	public static function createTable($db): void {
		$db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "np_api_guard` (
			`id` tinyint NOT NULL,
			`fails` int NOT NULL DEFAULT '0',
			`open_until` int NOT NULL DEFAULT '0',
			`since` int NOT NULL DEFAULT '0',
			`last_ok` int NOT NULL DEFAULT '0',
			`last_error` varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY (`id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
		$db->query("INSERT IGNORE INTO `" . DB_PREFIX . "np_api_guard` SET `id` = 1");
	}

	private static function blank(): array {
		return ['fails' => 0, 'open_until' => 0, 'since' => 0, 'last_ok' => 0, 'last_error' => ''];
	}

	/** Run a statement; on a missing table create it once and retry. Null on failure. */
	private static function run(string $sql) {
		if (!self::$db) {
			return null;
		}
		try {
			return self::$db->query($sql);
		} catch (\Throwable $e) {
			try {
				self::createTable(self::$db);
				return self::$db->query($sql);
			} catch (\Throwable $e2) {
				return null;
			}
		}
	}

	private static function affected(): int {
		try {
			return (int)self::$db->countAffected();
		} catch (\Throwable $e) {
			return 0;
		}
	}

	/** Fresh state from the table (other requests change it all the time). */
	private static function load(): array {
		$state = self::blank();
		$q = self::run("SELECT `fails`, `open_until`, `since`, `last_ok`, `last_error` FROM `" . DB_PREFIX . "np_api_guard` WHERE `id` = 1");
		if ($q && $q->row) {
			foreach ($state as $k => $v) {
				$state[$k] = is_int($v) ? (int)$q->row[$k] : (string)$q->row[$k];
			}
		} elseif ($q) {
			self::run("INSERT IGNORE INTO `" . DB_PREFIX . "np_api_guard` SET `id` = 1");
		}
		return self::$state = $state;
	}

	/** May this request call the API now? Claims the probe slot when the pause is over. */
	public static function allow(): bool {
		if (self::$probing || !self::$db) {
			return true;
		}
		$s   = self::load();
		$now = time();
		if ($s['open_until'] > $now) {
			return false;
		}
		if ($s['fails'] >= self::FAILS_TO_OPEN) {
			// One prober per pause: whoever flips open_until first gets it.
			self::run("UPDATE `" . DB_PREFIX . "np_api_guard` SET `open_until` = " . ($now + self::PROBE_SECONDS) . " WHERE `id` = 1 AND `fails` >= " . self::FAILS_TO_OPEN . " AND `open_until` <= " . $now);
			if (self::affected() !== 1) {
				return false;
			}
			self::$probing = true;
		}
		return true;
	}

	/** Would a call be allowed now? (Does not claim the probe slot.) */
	public static function canTry(): bool {
		return self::$probing || !self::$db || self::load()['open_until'] <= time();
	}

	public static function success(): void {
		self::$probing = false;
		$s = self::$state;
		if ($s !== null && $s['fails'] === 0 && $s['open_until'] === 0 && $s['since'] === 0) {
			return; // healthy before this call too — nothing to write
		}
		self::run("UPDATE `" . DB_PREFIX . "np_api_guard` SET `fails` = 0, `open_until` = 0, `since` = 0, `last_ok` = " . time() . " WHERE `id` = 1");
		self::$state = null;
	}

	public static function failure(string $error): void {
		self::$probing = false;
		if (!self::$db) {
			return;
		}
		$now = time();
		// open_until is computed from the old count, before `fails` changes, so the
		// statement means the same with and without MariaDB's simultaneous assignment.
		self::run("UPDATE `" . DB_PREFIX . "np_api_guard` SET
			`open_until` = IF(`fails` + 1 >= " . self::FAILS_TO_OPEN . ", " . ($now + self::OPEN_SECONDS) . ", `open_until`),
			`since` = IF(`since` = 0, " . $now . ", `since`),
			`last_error` = '" . self::$db->escape(substr($error, 0, 250)) . "',
			`fails` = `fails` + 1
			WHERE `id` = 1");
		self::$state = null;
	}

	public static function reset(): void {
		self::run("UPDATE `" . DB_PREFIX . "np_api_guard` SET `fails` = 0, `open_until` = 0, `since` = 0 WHERE `id` = 1");
		self::$state = null;
	}

	/** For the settings page: is the API considered down right now, since when, and why. */
	public static function status(): array {
		$s = self::load();
		return [
			'down'       => $s['fails'] >= self::FAILS_TO_OPEN,
			'since'      => $s['since'],
			'retry_at'   => $s['open_until'],
			'last_error' => $s['last_error'],
		];
	}
}
