<?php
/**
 * Live check of the gateway without a Blesta licence: the real gateway code on the Blesta stubs, the real SnapEX Pay API
 * (test invoices) and real signed webhooks. Router for the PHP built-in server, state kept in $SNAPEX_LIVE_DIR:
 *
 *   SNAPEX_LIVE_DIR=/tmp/live SNAPEX_LIVE_URL=https://<public url> php -S 127.0.0.1:8099 integrations/blesta/tests/live.php
 *
 * $SNAPEX_LIVE_DIR/keys.json: {"api_key": "smk_…", "secret": "whsec_…"}. Webhook URL in the cabinet:
 * <public url>/callback/gw/1/snapex_pay/.
 *   GET  /create?amount=12.50&invoice=1001  staff in test mode → SnapEX test invoice, prints the payment page URL
 *   GET  /back?invoice=1001                 the client is back on "payment received" (success())
 *   POST /callback/gw/1/snapex_pay/         webhook (or GET with ?check=…&token=… from the return page)
 *   GET  /state                             transactions, Blesta invoices, gateway log
 */

require __DIR__.'/blesta_stubs.php';
require __DIR__.'/../src/components/gateways/nonmerchant/snapex_pay/snapex_pay.php';

$dir = rtrim((string) getenv('SNAPEX_LIVE_DIR'), '/');
$public = rtrim((string) getenv('SNAPEX_LIVE_URL'), '/');
$keys = json_decode((string) @file_get_contents($dir.'/keys.json'), true);
if ($dir === '' || $public === '' || ! is_array($keys)) {
    http_response_code(500);
    exit("Set SNAPEX_LIVE_DIR (with keys.json) and SNAPEX_LIVE_URL.\n");
}

blesta_stub_boot();
Record::$pdo = new PDO('sqlite:'.$dir.'/blesta.sqlite');
Record::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
Configure::set('Blesta.gw_callback_url', $public.'/callback/gw/');
$stateFile = $dir.'/state.json';
if (is_file($stateFile)) {
    $GLOBALS['blesta'] = json_decode((string) file_get_contents($stateFile), true);
}
$GLOBALS['blesta']['meta'] = [
    'api_key' => $keys['api_key'], 'webhook_secret' => $keys['secret'], 'fee_on' => 'merchant', 'expires_minutes' => '60',
    'test_mode' => 'true', 'partner_id' => '', 'api_url' => '',
];

$gateway = new SnapexPay;
$gateway->setMeta($GLOBALS['blesta']['meta']);
$gateway->install();

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$out = null;
$clientId = 5;
$returnUrl = $public.'/back?invoice=';
if ($path === '/create') {
    $id = (int) ($_GET['invoice'] ?? 1001);
    $amount = (string) ($_GET['amount'] ?? '12.50');
    $GLOBALS['blesta']['invoices'][$id] = ['client_id' => $clientId, 'currency' => 'USD', 'due' => (float) $amount];
    $_SESSION['blesta_staff_id'] = 1; // test mode: staff only
    $gateway->setCurrency('USD');
    $html = $gateway->buildProcess(['client_id' => $clientId, 'first_name' => 'Live', 'last_name' => 'Test'], (float) $amount,
        [['id' => $id, 'amount' => $amount]], ['description' => 'Invoice #'.$id, 'return_url' => $returnUrl.$id]);
    $out = ['pay_url' => preg_match('#href="([^"]+)"#', (string) $html, $m) ? html_entity_decode($m[1]) : null, 'errors' => $gateway->errors()];
} elseif ($path === '/back') {
    $row = Record::$pdo->query('SELECT external_id FROM snapex_pay_invoices ORDER BY rowid DESC LIMIT 1')->fetchColumn();
    $out = ['success' => $gateway->success(['client_id' => $clientId, 'snapex_ref' => (string) $row], []), 'errors' => $gateway->errors()];
} elseif (strpos($path, '/callback/gw/') === 0) {
    $tx = (new StubGatewayPayments)->processNotification('snapex_pay', $_GET, $_POST);
    $out = ['transaction' => $tx, 'http' => SnapexPay::$lastStatus ?: 200, 'errors' => end($GLOBALS['blesta']['notification_errors']) ?: null];
    file_put_contents($dir.'/webhooks.log', gmdate('c').' '.json_encode($out)."\n", FILE_APPEND);
} elseif ($path === '/state') {
    $out = $GLOBALS['blesta'];
    unset($out['meta']); // API key and webhook secret
    $out['snapex_pay_invoices'] = Record::$pdo->query('SELECT * FROM snapex_pay_invoices')->fetchAll(PDO::FETCH_ASSOC);
} else {
    http_response_code(404);
    exit;
}

unset($GLOBALS['blesta']['meta']);
file_put_contents($stateFile, json_encode($GLOBALS['blesta'], JSON_PRETTY_PRINT));
header('Content-Type: application/json');
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
