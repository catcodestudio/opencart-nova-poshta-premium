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
		// Stable across requests, unique-ish per install. AUTH_KEY-style derivation
		// would be better but OC has no per-install secret constant; this is the
		// next best deterministic source.
		$material = DIR_APPLICATION . DIR_OPENCART;
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
