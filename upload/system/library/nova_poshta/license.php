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

	/** Trial endpoint; override via CATCODE_TRIAL_API. */
	private const DEFAULT_TRIAL_ENDPOINT = 'https://catcode.com.ua/wp-json/catcode/v1/trial';

	/** Free trial length in days. Paid licences are annual (1–5 years). */
	public const TRIAL_DAYS = 7;

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

	public static function trialEndpoint(): string {
		if (defined('CATCODE_TRIAL_API')) {
			return (string)constant('CATCODE_TRIAL_API');
		}
		return self::DEFAULT_TRIAL_ENDPOINT;
	}

	// -----------------------------------------------------------------------
	// Trial
	// -----------------------------------------------------------------------

	/** A key is stored, whether or not the server has blessed it yet. */
	public static function hasKey($config): bool {
		return trim((string)$config->get('shipping_nova_poshta_license_key')) !== '';
	}

	/** Timestamp the merchant started the trial, 0 when never started. */
	public static function trialStarted($config): int {
		return (int)$config->get('shipping_nova_poshta_trial_started');
	}

	/**
	 * The trial is offered once per install and never while a key is present.
	 * Nothing starts it implicitly — not installing, not saving settings.
	 */
	public static function trialAvailable($config): bool {
		return self::trialStarted($config) <= 0 && !self::hasKey($config);
	}

	public static function trialDaysLeft($config): int {
		$started = self::trialStarted($config);
		if ($started <= 0) {
			return self::TRIAL_DAYS;
		}
		return max(0, self::TRIAL_DAYS - (int)floor((time() - $started) / 86400));
	}

	/** True while Pro is unlocked by a trial key rather than a purchase. */
	public static function trialActive($config): bool {
		return self::trialStarted($config) > 0 && self::isPro($config);
	}

	/** Expiry the server reported for the current key ('' when open-ended). */
	public static function expiresAt($config): string {
		$raw = (string)$config->get('shipping_nova_poshta_license_expires_at');
		if ($raw === '') {
			return '';
		}
		$ts = (int)strtotime($raw);
		return $ts > 0 ? date('Y-m-d', $ts) : '';
	}

	/**
	 * Mint a 7-day trial key for this shop and activate it.
	 *
	 * The trial is a real licence issued by the same machinery as a purchase,
	 * so everything downstream (activation slots, expiry, renewals) is
	 * identical — only the term differs. `trial_started` is written only after
	 * a key actually arrived, so a rejected request leaves the trial on offer.
	 */
	public static function startTrial($config, $modelSetting, string $email): array {
		$email = trim($email);

		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return ['ok' => false, 'error' => 'bad_email'];
		}
		if (!self::trialAvailable($config)) {
			return ['ok' => false, 'error' => 'trial_used_site'];
		}

		$response = self::post(self::trialEndpoint(), [
			'email'       => $email,
			'module_slug' => self::EXPECTED_SLUG,
			'site_url'    => self::siteUrl(),
		]);

		if (empty($response['ok']) || empty($response['key'])) {
			return $response;
		}

		$key    = (string)$response['key'];
		$result = self::call($key, 'activate');

		self::store($modelSetting, $config, $key, $result, [
			'shipping_nova_poshta_trial_started' => (string)time(),
		]);

		$result['key']        = $key;
		$result['expires_at'] = (string)($response['expires_at'] ?? ($result['expires_at'] ?? ''));

		return $result;
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
			return 'не перевірено';
		}
		if ($status === 'valid') {
			if ($checkedAt === '') {
				return 'дійсна (ще не перевірялася повторно)';
			}
			$delta = (int)floor((time() - (int)strtotime($checkedAt)) / 86400);
			$left  = self::GRACE_DAYS - $delta;
			if ($left > 0) {
				return 'дійсна (перевірена ' . $delta . ' дн. тому, ще ' . $left . ' дн. пільгового періоду)';
			}
			return 'дійсна (пільговий період минув — очікує повторної перевірки)';
		}
		// invalid
		if ($checkedAt !== '') {
			return 'недійсна (остання перевірка ' . $checkedAt . ')';
		}
		return $status;
	}

	/**
	 * Activate the current key against the server.
	 *
	 * Two-step flow: verify first, then activate. verify() doesn't consume
	 * an activation slot, so a customer who pastes a key meant for a
	 * different CatCode product (or a typo) doesn't burn their limit.
	 * Only after we confirm product_slug matches do we call the real
	 * activate which records the site in the server's slot table.
	 */
	public static function activate($config, $modelSetting, string $key): array {
		$key = trim($key);
		if ($key === '') {
			return ['ok' => false, 'error' => 'missing_key'];
		}

		// Pre-check: verify the key + product binding without consuming a
		// server slot. If this fails (wrong product, unknown key, network) we
		// do NOT touch the stored state — a previously activated valid key
		// must survive a user typing in a bad one.
		$pre = self::call($key, 'verify');
		if (empty($pre['ok'])) {
			return $pre;
		}

		// Slug already verified inside call(); safe to consume a slot now.
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
		$current['shipping_nova_poshta_license_expires_at'] = '';
		$current['shipping_nova_poshta_license_data']       = '';
		$modelSetting->editSetting('shipping_nova_poshta', $current);

		foreach (['key', 'status', 'checked_at', 'expires_at', 'data'] as $suffix) {
			$config->set('shipping_nova_poshta_license_' . $suffix, '');
		}
		return $result;
	}

	/**
	 * POST to license endpoint. Returns {ok, ...} merged with http + verified_at.
	 * On network failure returns ok=false without claiming the server said so,
	 * so a transient outage doesn't flip status to 'invalid' (grace covers it).
	 */
	private static function call(string $key, string $action): array {
		$data = self::post(self::endpoint(), [
			'key'      => $key,
			'site_url' => self::siteUrl(),
			'action'   => $action,
		]);

		// Per-product enforcement: server identified the key as belonging to
		// a different product → refuse. Older server builds may omit
		// product_slug — treat empty as "trust, no info" for back-compat,
		// but a present-and-mismatched value hard-fails.
		if (!empty($data['ok']) && isset($data['product_slug']) && $data['product_slug'] !== ''
			&& $data['product_slug'] !== self::EXPECTED_SLUG) {
			$data['ok']    = false;
			$data['error'] = 'wrong_product';
		}

		// Map server-side flags to actionable error codes so the admin UI
		// shows the right "what to do" message, not a generic "key invalid".
		// Server's verify response sets `expired:true` / signals revoked via
		// 401 + error=revoked, but for `verify` it leaves the error field
		// blank when ok=false — we normalize here.
		if (empty($data['ok']) && empty($data['error'])) {
			if (!empty($data['expired'])) {
				$data['error'] = 'expired';
			} elseif ((int)($data['http'] ?? 0) === 401) {
				$data['error'] = 'invalid_key';
			} elseif ((int)($data['http'] ?? 0) === 403) {
				$data['error'] = 'limit_reached';
			}
		}

		return $data;
	}

	/**
	 * POST JSON and decode. A transport failure returns ok=false without an
	 * error the server never sent, so an outage never flips status to invalid.
	 */
	private static function post(string $url, array $payload): array {
		if (!function_exists('curl_init')) {
			return ['ok' => false, 'error' => 'no_curl', 'http' => 0];
		}

		$body = json_encode($payload);

		$ch = curl_init($url);
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
	private static function store($modelSetting, $config, string $key, array $result, array $extra = []): void {
		$current = $modelSetting->getSetting('shipping_nova_poshta');
		if (!is_array($current)) { $current = []; }
		$current['shipping_nova_poshta_license_key']        = $key;
		$current['shipping_nova_poshta_license_status']     = !empty($result['ok']) ? 'valid' : 'invalid';
		$current['shipping_nova_poshta_license_checked_at'] = (string)($result['verified_at'] ?? date('Y-m-d H:i:s'));
		$current['shipping_nova_poshta_license_expires_at'] = (string)($result['expires_at'] ?? '');
		$current['shipping_nova_poshta_license_data']       = json_encode($result, JSON_UNESCAPED_UNICODE);

		foreach ($extra as $k => $v) {
			$current[$k] = $v;
		}

		$modelSetting->editSetting('shipping_nova_poshta', $current);

		// The live config still holds the pre-call values; patch it so code
		// further down this same request sees the new state.
		foreach ($current as $k => $v) {
			if (strpos($k, 'shipping_nova_poshta_license') === 0 || $k === 'shipping_nova_poshta_trial_started') {
				$config->set($k, $v);
			}
		}
	}
}
