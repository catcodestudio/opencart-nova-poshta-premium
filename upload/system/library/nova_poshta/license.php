<?php
namespace Opencart\System\Library\NovaPoshta;

/**
 * License client + state helper. Talks to the CatCode license endpoint
 * (https://catcode.com.ua/wp-json/catcode/v1/license).
 *
 * Pattern mirrors the IBAN Smart Invoice Pro WP client:
 *  - User pastes a purchased key on the settings page → call activate.
 *  - Daily OC cron re-verifies and refreshes cached status.
 *  - isPro() reads the cache; valid (or within GRACE_DAYS of last successful
 *    online check) → premium features unlocked.
 *
 * Per-product enforcement: the server returns `product_slug` on every
 * successful response. We refuse activation when it doesn't match
 * EXPECTED_SLUG — so a key purchased for a different CatCode product
 * cannot unlock Nova Poshta Pro here.
 *
 * Storage (in $config / wp_setting via shipping_nova_poshta prefix):
 *  - shipping_nova_poshta_license_key         — current key
 *  - shipping_nova_poshta_license_status      — 'valid' | 'invalid' | '' (untried)
 *  - shipping_nova_poshta_license_checked_at  — 'Y-m-d H:i:s' of last server roundtrip
 *  - shipping_nova_poshta_license_data        — JSON-encoded last response (for diagnostics)
 *
 * Override endpoint for staging via define('CATCODE_LICENSE_API', '...').
 */
class License {
	/** Server endpoint; override via CATCODE_LICENSE_API. */
	private const DEFAULT_ENDPOINT = 'https://catcode.com.ua/wp-json/catcode/v1/license';

	/** Canonical module slug — MUST match post_name of the module CPT. */
	public const EXPECTED_SLUG = 'opencart-nova-poshta-premium';

	/** Days the cache remains authoritative if the server is unreachable. */
	public const GRACE_DAYS = 14;

	private const HTTP_TIMEOUT = 8;

	public static function endpoint(): string {
		if (defined('CATCODE_LICENSE_API')) {
			return (string)constant('CATCODE_LICENSE_API');
		}
		return self::DEFAULT_ENDPOINT;
	}

	/** Whether the license is currently valid (or within offline grace). */
	public static function isPro($config): bool {
		$status    = (string)$config->get('shipping_nova_poshta_license_status');
		$checkedAt = (string)$config->get('shipping_nova_poshta_license_checked_at');

		if ($status === 'valid') {
			// Cache fresh enough? If never checked or stale beyond grace,
			// premium features lock until verify() succeeds again.
			if ($checkedAt === '') {
				return false;
			}
			$delta = (time() - (int)strtotime($checkedAt)) / 86400;
			return $delta <= self::GRACE_DAYS;
		}
		return false;
	}

	/** Human-readable status for the settings page. */
	public static function describe($config): string {
		$status    = (string)$config->get('shipping_nova_poshta_license_status');
		$checkedAt = (string)$config->get('shipping_nova_poshta_license_checked_at');
		if ($status === '') {
			return 'not checked';
		}
		if ($status === 'valid') {
			if ($checkedAt === '') {
				return 'valid (never re-verified)';
			}
			$delta = (int)floor((time() - (int)strtotime($checkedAt)) / 86400);
			$left  = self::GRACE_DAYS - $delta;
			if ($left > 0) {
				return 'valid (re-verified ' . $delta . ' day(s) ago, ' . $left . ' day(s) grace)';
			}
			return 'valid (grace expired — pending re-verification)';
		}
		// invalid
		if ($checkedAt !== '') {
			return 'invalid (last checked ' . $checkedAt . ')';
		}
		return $status;
	}

	/** Activate the current key against the server. */
	public static function activate($config, $modelSetting, string $key): array {
		$key = trim($key);
		if ($key === '') {
			return ['ok' => false, 'error' => 'missing_key'];
		}
		$result = self::call($key, 'activate');
		self::store($modelSetting, $config, $key, $result);
		return $result;
	}

	/** Verify the stored key — used by daily cron and on-demand "Re-check". */
	public static function verify($config, $modelSetting): array {
		$key = (string)$config->get('shipping_nova_poshta_license_key');
		if ($key === '') {
			return ['ok' => false, 'error' => 'no_key'];
		}
		$result = self::call($key, 'verify');
		self::store($modelSetting, $config, $key, $result);
		return $result;
	}

	/** Deactivate (release server-side slot) AND clear local cache. */
	public static function deactivate($config, $modelSetting): array {
		$key = (string)$config->get('shipping_nova_poshta_license_key');
		$result = $key === '' ? ['ok' => true, 'note' => 'no_key_stored'] : self::call($key, 'deactivate');
		// Clear locally regardless of remote outcome — user explicitly opted out.
		$current = $modelSetting->getSetting('shipping_nova_poshta');
		$current['shipping_nova_poshta_license_key']        = '';
		$current['shipping_nova_poshta_license_status']     = '';
		$current['shipping_nova_poshta_license_checked_at'] = '';
		$current['shipping_nova_poshta_license_data']       = '';
		$modelSetting->editSetting('shipping_nova_poshta', $current);
		return $result;
	}

	/**
	 * POST to license endpoint. Returns {ok, ...} merged with http + verified_at.
	 * On network failure returns ok=false without claiming the server said so,
	 * so a transient outage doesn't flip status to 'invalid' (grace covers it).
	 */
	private static function call(string $key, string $action): array {
		$siteUrl = self::siteUrl();
		$body = json_encode([
			'key'      => $key,
			'site_url' => $siteUrl,
			'action'   => $action,
		]);

		$ch = curl_init(self::endpoint());
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
			CURLOPT_CONNECTTIMEOUT => 5,
		]);
		$raw  = curl_exec($ch);
		$err  = curl_error($ch);
		$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($raw === false) {
			return ['ok' => false, 'error' => 'network', 'message' => $err, 'http' => 0];
		}
		$data = json_decode((string)$raw, true);
		if (!is_array($data)) {
			return ['ok' => false, 'error' => 'bad_response', 'http' => $http, 'raw' => substr((string)$raw, 0, 500)];
		}

		$data['http']        = $http;
		$data['verified_at'] = date('Y-m-d H:i:s');

		// Per-product enforcement: server identified the key as belonging to
		// a different product → refuse. Older server builds may omit
		// product_slug — treat empty as "trust, no info" for back-compat,
		// but a present-and-mismatched value hard-fails.
		if (!empty($data['ok']) && isset($data['product_slug']) && $data['product_slug'] !== ''
			&& $data['product_slug'] !== self::EXPECTED_SLUG) {
			$data['ok']    = false;
			$data['error'] = 'wrong_product';
		}

		return $data;
	}

	/**
	 * Normalize the storefront URL to scheme+host. Server normalizes the
	 * same way so http vs https and trailing slashes don't burn extra
	 * activation slots.
	 */
	private static function siteUrl(): string {
		$base = '';
		if (defined('HTTPS_CATALOG')) {
			$base = (string)constant('HTTPS_CATALOG');
		} elseif (defined('HTTPS_SERVER')) {
			$base = (string)constant('HTTPS_SERVER');
		} elseif (defined('HTTP_CATALOG')) {
			$base = (string)constant('HTTP_CATALOG');
		} elseif (defined('HTTP_SERVER')) {
			$base = (string)constant('HTTP_SERVER');
		}
		$parts = parse_url($base);
		if (!$parts || empty($parts['host'])) {
			return '';
		}
		$scheme = strtolower($parts['scheme'] ?? 'https');
		$host   = strtolower($parts['host']);
		return $scheme . '://' . $host;
	}

	/** Persist key + status + raw response to OC settings. */
	private static function store($modelSetting, $config, string $key, array $result): void {
		$current = $modelSetting->getSetting('shipping_nova_poshta');
		$current['shipping_nova_poshta_license_key']        = $key;
		$current['shipping_nova_poshta_license_status']     = !empty($result['ok']) ? 'valid' : 'invalid';
		$current['shipping_nova_poshta_license_checked_at'] = (string)($result['verified_at'] ?? date('Y-m-d H:i:s'));
		$current['shipping_nova_poshta_license_data']       = json_encode($result, JSON_UNESCAPED_UNICODE);
		$modelSetting->editSetting('shipping_nova_poshta', $current);
	}
}
