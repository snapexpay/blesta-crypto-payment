<?php
// Basics
$lang['SnapexPay.name'] = 'SnapEX Pay (Crypto)';
$lang['SnapexPay.description'] = 'Accept USDT on several networks or other coins swapped to USDT. Funds arrive in your SnapEX Pay merchant balance.';

// Settings
$lang['SnapexPay.api_key'] = 'API key';
$lang['SnapexPay.api_key_note'] = 'SnapEX Pay merchant cabinet → API keys. Scopes: invoices.read and invoices.write.';
$lang['SnapexPay.webhook_secret'] = 'Webhook secret';
$lang['SnapexPay.webhook_secret_note'] = 'In the SnapEX Pay cabinet set the webhook URL below and paste the whsec_… secret here.';
$lang['SnapexPay.webhook_url'] = 'Webhook URL for the SnapEX Pay cabinet';
$lang['SnapexPay.fee_on'] = 'Network fee paid by';
$lang['SnapexPay.fee_on.merchant'] = 'Merchant (client pays the invoice amount)';
$lang['SnapexPay.fee_on.customer'] = 'Client (added on top of the invoice amount)';
$lang['SnapexPay.expires_minutes'] = 'Payment window, minutes';
$lang['SnapexPay.expires_minutes_note'] = 'How long the client has to pay once the payment page is opened (1–1440).';
$lang['SnapexPay.test_mode'] = 'Test mode';
$lang['SnapexPay.test_mode_note'] = 'Test invoices confirm by themselves without real money. Only staff (logged in as a client) see the button while this is on.';
$lang['SnapexPay.partner_id'] = 'Partner ID';
$lang['SnapexPay.partner_id_note'] = 'Optional. The Partner ID of the integrator who set this up (SnapEX Pay referral program). Leave empty if you set it up yourself.';
$lang['SnapexPay.api_url'] = 'API URL';
$lang['SnapexPay.api_url_note'] = 'Leave as is.';

// Payment page
$lang['SnapexPay.buildprocess.submit'] = 'Pay with crypto';
$lang['SnapexPay.process.test_mode'] = 'Test mode: no real money is charged.';
$lang['SnapexPay.process.test_mode_clients'] = 'Crypto payments are being set up. Please choose another payment method for now.';

// Transactions
$lang['SnapexPay.transaction.partial'] = 'SnapEX Pay: partial payment (%1$s received). Review and apply manually.';

// Errors
$lang['SnapexPay.!error.api_key.empty'] = 'Enter the SnapEX Pay API key.';
$lang['SnapexPay.!error.webhook_secret.empty'] = 'Enter the webhook secret: Blesta marks invoices paid from the SnapEX Pay webhook.';
$lang['SnapexPay.!error.fee_on.valid'] = 'Choose who pays the network fee.';
$lang['SnapexPay.!error.expires_minutes.valid'] = 'The payment window must be a whole number of minutes from 1 to 1440.';
$lang['SnapexPay.!error.partner_id.valid'] = 'The Partner ID can be at most 32 characters long.';
$lang['SnapexPay.!error.api_url.valid'] = 'The API URL must start with https://.';
$lang['SnapexPay.!error.currency.usd_missing'] = 'SnapEX Pay works in USD: add USD under Settings → Company → Currencies so %1$s amounts can be converted.';
$lang['SnapexPay.!error.amount.invalid'] = 'Nothing to pay.';
$lang['SnapexPay.!error.create'] = 'Could not start the crypto payment: %1$s';
$lang['SnapexPay.!error.unreachable'] = 'SnapEX Pay could not be reached to confirm the payment: %1$s';
$lang['SnapexPay.!error.mismatch'] = 'The SnapEX Pay invoice does not match the payment it was created for. Nothing was recorded.';
$lang['SnapexPay.!error.webhook.stale'] = 'Webhook timestamp is missing or too old.';
$lang['SnapexPay.!error.webhook.signature'] = 'Webhook signature does not match the webhook secret.';
$lang['SnapexPay.!error.check.token'] = 'Invalid payment check link.';
