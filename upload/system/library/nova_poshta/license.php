<?php
namespace Opencart\System\Library\NovaPoshta;

/**
 * License state helper. Reads from $config (already loaded by OC) and applies
 * the policy: premium features stay unlocked while the key was last verified
 * valid, or for up to GRACE_DAYS since the last successful online check.
 *
 * TTN creation (core feature) is NEVER gated — paying customers are not bricked
 * if our license server is unreachable. Only premium features (webhooks,
 * multi-warehouse) gate after grace expires.
 */
class License {
	public const GRACE_DAYS = 14;

	/** Whether the license is currently valid (online or within offline grace). */
	public static function isPro($config): bool {
		$status = (string)$config->get('shipping_nova_poshta_license_status');
		if ($status === 'valid') {
			return true;
		}
		$checkedAt = (string)$config->get('shipping_nova_poshta_license_checked_at');
		if ($checkedAt !== '' && $status === 'valid') {
			$delta = (time() - strtotime($checkedAt)) / 86400;
			if ($delta <= self::GRACE_DAYS) {
				return true;
			}
		}
		return false;
	}

	/** Human-readable status for the settings page. */
	public static function describe($config): string {
		$status = (string)$config->get('shipping_nova_poshta_license_status');
		$checkedAt = (string)$config->get('shipping_nova_poshta_license_checked_at');
		if ($status === '') {
			return 'not checked';
		}
		if ($status === 'valid') {
			return 'valid (last checked ' . ($checkedAt ?: 'never') . ')';
		}
		if ($checkedAt !== '') {
			$delta = (int)floor((time() - strtotime($checkedAt)) / 86400);
			$left = self::GRACE_DAYS - $delta;
			if ($left > 0) {
				return 'invalid — offline grace: ' . $left . ' day(s) left';
			}
			return 'invalid — grace expired ' . abs($left) . ' day(s) ago';
		}
		return $status;
	}
}
