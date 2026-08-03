<?php
// Headings
$_['heading_title']            = 'Nova Poshta Premium';

// Tabs
$_['tab_general']              = 'General';
$_['tab_sender']               = 'Sender';
$_['tab_auto']                 = 'Auto TTN';
$_['tab_appearance']           = 'Appearance';
$_['tab_license']              = 'License';
$_['tab_cron']                 = 'Cron';

// Text
$_['text_extension']           = 'Extensions';
$_['text_success']             = 'Settings saved.';
$_['text_setup_ok']            = 'Setup complete — tables created and events registered.';
$_['text_edit']                = 'Edit Nova Poshta Premium';
$_['text_enabled']             = 'Enabled';
$_['text_disabled']            = 'Disabled';
$_['text_home']                = 'Home';
$_['text_none']                = '--- None ---';
$_['text_yes']                 = 'Yes';
$_['text_no']                  = 'No';
$_['text_test_ok']             = 'Connection OK — %d cargo types returned.';
$_['text_test_fail']           = 'Connection failed:';
$_['text_quote_ok']            = 'Test quote (Kyiv, 1 kg, 500 UAH): %.2f UAH';
$_['text_quote_fail']          = 'Quote failed:';
$_['text_theme_auto']          = 'Auto (match site)';
$_['text_theme_light']         = 'Light';
$_['text_theme_dark']          = 'Dark';
$_['text_license_ok']          = 'License active: %s';
$_['text_license_bad']         = 'License invalid';
$_['text_license_deactivated'] = 'License deactivated and local cache cleared.';
$_['text_license_err_wrong_product'] = 'This key belongs to a different product.';
$_['text_license_err_expired']       = 'This license has expired.';
$_['text_license_err_invalid_key']   = 'Unknown or invalid key.';
$_['text_license_err_limit_reached'] = 'Activation limit reached for this key.';
$_['text_license_err_network']       = 'Could not reach the license server — will retry.';
$_['text_license_err_bad_response']  = 'Unexpected response from the license server.';
$_['text_license_err_revoked']       = 'This key was disabled. Contact support if this is a mistake.';

// Entries
$_['entry_status']             = 'Status';
$_['entry_api_key']            = 'Nova Poshta API key';
$_['entry_live_rate']          = 'Live rate calculation via API';
$_['entry_default_cost']       = 'Shipping cost';
$_['entry_geo_zone']           = 'Geo Zone';
$_['entry_tax_class']          = 'Tax Class';
$_['entry_sort_order']         = 'Sort Order';
$_['entry_sender_city']        = 'Sender city';
$_['entry_sender_warehouse']   = 'Sender warehouse';
$_['entry_sender_counterparty']= 'Sender profile (counterparty)';
$_['entry_sender_contact']     = 'Contact person';
$_['entry_sender_phone']       = 'Sender phone';
$_['entry_auto_ttn']           = 'Auto-create TTN on status';
$_['entry_accent_color']       = 'Accent colour';
$_['entry_radius']             = 'Corner radius';
$_['entry_theme']              = 'Block theme';
$_['entry_license_key']        = 'License key';
$_['entry_license_status']     = 'Status';

// Help
$_['help_api_key']             = 'Free at my.novaposhta.ua → Settings → Security → API. Stored encrypted.';
$_['help_live_rate']           = 'Disabled (default) — checkout shows the cost from the field below. Enabled — the module asks Nova Poshta for the real tariff for the city the customer picked, falling back to the field below if the API does not answer.';
$_['help_default_cost']        = 'Shown at checkout. Set 0 if delivery is paid to the carrier on pickup.';
$_['help_sender']              = 'Search your city, pick the warehouse, then load your counterparty profile and contact person — required for automatic TTN creation.';
$_['help_auto_ttn']            = 'When an order reaches this status the TTN is created automatically. Leave as None to create TTNs manually from the shipments dashboard.';
$_['help_accent_color']        = 'Heading and active-element colour of the checkout block.';
$_['help_radius']              = 'Roundness of the block, fields and menus (0-28px).';
$_['help_theme']               = 'Auto inherits your storefront colours; Light/Dark pins an explicit surface.';
$_['help_cron']                = 'Add these URLs to your system crontab (Pro features). OpenCart 3.0.x has no built-in cron manager.';
$_['help_license']             = 'Premium features (status polling, webhooks, COD reconciliation, return labels) require a valid key from catcode.com.ua.';

// Buttons
$_['button_save']              = 'Save';
$_['button_test']              = 'Test connection';
$_['button_setup']             = 'Setup / Re-install';
$_['button_quote']             = 'Test quote';
$_['button_cancel']            = 'Back';
$_['button_shipments']         = 'Shipments dashboard';
$_['button_license_activate']  = 'Activate / Re-check';
$_['button_license_off']       = 'Deactivate';
$_['button_load_contacts']     = 'Load';

// Errors
$_['error_permission']         = 'Warning: You do not have permission to modify Nova Poshta Premium.';
$_['error_api_key_empty']      = 'API key is empty.';
$_['error_query_empty']        = 'Search query is empty.';
$_['error_city_empty']         = 'City is empty.';
$_['error_sender_city_empty']  = 'Sender city is not configured.';
$_['error_license_empty']      = 'License key is empty.';
