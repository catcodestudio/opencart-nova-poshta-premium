<?php
namespace Opencart\System\Library\NovaPoshta;

/**
 * At-rest obfuscation for stored secrets (API key, license key).
 * NOT cryptographic-grade encryption — defense in depth against casual DB-dump
 * leaks. Uses XOR with a deterministic per-install secret derived from PHP path
 * constants. For real PCI-grade storage use OpenSSL with HSM-backed keys.
 */
class Crypto {
	private const PREFIX = 'np$';

	private static function secret(): string {
		// Stable across application contexts (admin/catalog/cron/cli).
		// DIR_APPLICATION differs per app, so we use DIR_OPENCART (root) +
		// DB_DATABASE (defined in config.php) which are identical everywhere.
		$material = DIR_OPENCART . (defined('DB_DATABASE') ? DB_DATABASE : '');
		return hash('sha256', 'NovaPoshtaPremium|' . $material, true);
	}

	public static function encrypt(string $plain): string {
		if ($plain === '') {
			return '';
		}
		$secret = self::secret();
		$bytes = '';
		for ($i = 0, $n = strlen($plain); $i < $n; $i++) {
			$bytes .= chr(ord($plain[$i]) ^ ord($secret[$i % strlen($secret)]));
		}
		return self::PREFIX . base64_encode($bytes);
	}

	public static function decrypt(string $stored): string {
		if ($stored === '') {
			return '';
		}
		if (!str_starts_with($stored, self::PREFIX)) {
			// Legacy plaintext (pre-encryption) — return as-is.
			return $stored;
		}
		$bytes = base64_decode(substr($stored, strlen(self::PREFIX)), true);
		if ($bytes === false) {
			return '';
		}
		$secret = self::secret();
		$out = '';
		for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
			$out .= chr(ord($bytes[$i]) ^ ord($secret[$i % strlen($secret)]));
		}
		return $out;
	}
}
