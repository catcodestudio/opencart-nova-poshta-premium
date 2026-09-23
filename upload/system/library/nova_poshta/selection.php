<?php
namespace Opencart\System\Library\NovaPoshta;

/**
 * Keeps the picked city/branch alive across concurrent checkout requests.
 *
 * The selection lives in the session, but OpenCart writes the WHOLE session
 * back at the end of every request. One-page checkouts fire register.save /
 * shipping_method.save / confirm in parallel with our setSelection; a request
 * that started before setSelection and finished after it wrote its older copy
 * of the session back, silently dropping the np_* keys. The order was then
 * built without the city: fallback price, no oblast fix, no draft shipment.
 *
 * Worse, such a request could also write back an OLDER pick (the customer
 * changes Lviv to Kyiv, a slow save puts Lviv back). setSelection therefore also
 * drops the selection into a cookie bound to the session id — the browser keeps
 * the answer of the last setSelection it received — and that cookie is the
 * source of truth whenever it belongs to the current session.
 * The cookie carries nothing the browser didn't post to setSelection anyway,
 * and the oblast is still resolved on the server from the city directory.
 */
class Selection {
	public const COOKIE = 'np_premium_sel';
	private const KEYS = ['city_ref', 'city_name', 'city_area', 'warehouse_ref', 'warehouse_name'];

	public static function remember($session): void {
		$sel = ['sid' => (string)$session->getId()];
		foreach (self::KEYS as $k) {
			$sel[$k] = (string)($session->data['np_recipient_' . $k] ?? '');
		}
		if (headers_sent()) {
			return;
		}
		setcookie(self::COOKIE, base64_encode((string)json_encode($sel)), [
			'expires'  => time() + 86400,
			'path'     => '/',
			'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
			'httponly' => true,
			'samesite' => 'Lax',
		]);
	}

	/** Re-applies the last selection the browser got back, if a concurrent request lost or reverted it. */
	public static function restore($session): void {
		$raw = (string)($_COOKIE[self::COOKIE] ?? '');
		if ($raw === '') {
			return;
		}
		$sel = json_decode((string)base64_decode($raw, true), true);
		if (!is_array($sel) || (string)($sel['sid'] ?? '') !== (string)$session->getId()) {
			return;
		}
		foreach (self::KEYS as $k) {
			$session->data['np_recipient_' . $k] = trim((string)($sel[$k] ?? ''));
		}
	}
}
