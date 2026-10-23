<?php
/**
 * Offline tests for the Blesta gateway: stub Blesta (gateway base, Record on in-memory SQLite, transactions and
 * invoices in $GLOBALS['blesta']) and a fake SnapEX Pay API. Run: php integrations/blesta/tests/run.php
 */

require __DIR__.'/blesta_stubs.php';
require __DIR__.'/../src/components/gateways/nonmerchant/snapex_pay/snapex_pay.php';
blesta_stub_boot();

use SnapexPayBlesta\Api;
use SnapexPayBlesta\Store;

// ---- fake SnapEX Pay API (same rules as the real one: Idempotency-Key replay, external_id used once, $1 minimum)
$GLOBALS['remote'] = [];
$GLOBALS['keys'] = [];
$GLOBALS['calls'] = [];
$GLOBALS['api_down'] = false;
Api::$transport = function ($method, $url, $headers, $body) {
    $GLOBALS['calls'][] = [$method, $url, $headers, $body];
    if ($GLOBALS['api_down']) {
        return [503, json_encode(['error' => ['code' => 'unavailable', 'message' => 'Service unavailable.']])];
    }
    if (! in_array('Authorization: Bearer sk_test', $headers, true)) {
        return [401, json_encode(['error' => ['code' => 'unauthorized', 'message' => 'A valid merchant API key is required.']])];
    }
    if ($method === 'POST') {
        $data = json_decode($body, true);
        $key = '';
        foreach ($headers as $h) {
            if (strpos($h, 'Idempotency-Key: ') === 0) {
                $key = substr($h, 17);
            }
        }
        if (isset($GLOBALS['keys'][$key])) {
            $old = $GLOBALS['remote'][$GLOBALS['keys'][$key]];
            if ($old['_request'] !== $data) {
                return [409, json_encode(['error' => ['code' => 'conflict', 'message' => 'Idempotency-Key was already used with a different request.']])];
            }

            return [200, json_encode(['data' => $old])];
        }
        foreach ($GLOBALS['remote'] as $old) {
            if ($old['external_id'] === $data['external_id']) {
                return [422, json_encode(['error' => ['code' => 'validation_error', 'message' => 'The request is invalid.', 'details' => ['amount_usd' => ['external_id has already been used by this merchant.']]]])];
            }
        }
        if ((float) $data['amount_usd'] < 1) {
            return [422, json_encode(['error' => ['code' => 'validation_error', 'message' => 'The request is invalid.', 'details' => ['amount_usd' => ['Minimum invoice is $1.']]]])];
        }
        $id = 'inv_'.(count($GLOBALS['remote']) + 1);
        $GLOBALS['keys'][$key] = $id;
        $GLOBALS['remote'][$id] = [
            'invoice_id' => $id, 'environment' => $data['environment'], 'amount_usd' => $data['amount_usd'],
            'status' => 'open', 'pay_url' => 'https://pay.snapex.pro/i/'.$id, 'external_id' => $data['external_id'],
            'expires_at' => gmdate('c', time() + $data['expires_in']), '_request' => $data,
        ];

        return [201, json_encode(['data' => $GLOBALS['remote'][$id]])];
    }
    $id = basename($url);

    return isset($GLOBALS['remote'][$id])
        ? [200, json_encode(['data' => $GLOBALS['remote'][$id]])]
        : [404, json_encode(['error' => ['code' => 'not_found', 'message' => 'The requested resource was not found.']])];
};

$failures = 0;
function check($cond, $label)
{
    global $failures;
    echo ($cond ? '  ok   ' : '  FAIL ').$label."\n";
    if (! $cond) {
        $failures++;
    }
}
function gateway(array $meta = [], $currency = 'USD')
{
    $g = new SnapexPay;
    $g->setMeta($meta + $GLOBALS['blesta']['meta']);
    $g->setCurrency($currency);

    return $g;
}
function contact($clientId)
{
    return ['client_id' => $clientId, 'first_name' => 'Ann', 'last_name' => 'Lee'];
}
function returnUrl($clientId)
{
    return 'https://billing.host.example/client/pay/received/snapex_pay/?client_id='.$clientId;
}
function pay($clientId, $amount, array $invoices, array $meta = [], $currency = 'USD', $returnUrl = null)
{
    $g = gateway($meta, $currency);
    $html = $g->buildProcess(contact($clientId), $amount, $invoices, ['description' => 'Invoice #'.$invoices[0]['id'], 'return_url' => $returnUrl ?: returnUrl($clientId)]);

    return [$html, $g];
}
function payUrl($html)
{
    return preg_match('#href="([^"]+)"#', (string) $html, $m) ? html_entity_decode($m[1]) : null;
}
function webhook($payload, array $meta = [], $secret = 'whsec_x', $ts = null)
{
    $ts = $ts === null ? (string) time() : (string) $ts;
    $body = json_encode($payload);
    SnapexPay::$request = ['body' => $body, 'headers' => ['Webhook-Timestamp' => $ts, 'Webhook-Signature' => 'v1='.hash_hmac('sha256', $ts.'.'.$body, $secret)]];
    $saved = $GLOBALS['blesta']['meta'];
    $GLOBALS['blesta']['meta'] = $meta + $saved;
    $tx = (new StubGatewayPayments)->processNotification('snapex_pay', [0 => 1, 1 => 'snapex_pay'], []);
    $GLOBALS['blesta']['meta'] = $saved;
    SnapexPay::$request = null;

    return $tx;
}
function invoice($id, $clientId, $due, $currency = 'USD')
{
    $GLOBALS['blesta']['invoices'][$id] = ['client_id' => $clientId, 'currency' => $currency, 'due' => (float) $due];

    return [['id' => $id, 'amount' => $due]];
}
function expireClaims()
{
    Record::$pdo->exec("UPDATE snapex_pay_invoices SET claimed_at = '2000-01-01 00:00:00'");
}

$meta = ['api_key' => 'sk_test', 'webhook_secret' => 'whsec_x', 'fee_on' => 'merchant', 'expires_minutes' => '30', 'test_mode' => 'false', 'partner_id' => '', 'api_url' => ''];
$GLOBALS['blesta']['meta'] = $meta;

echo "gateway\n";
$g = gateway();
check($g->getName() === 'SnapEX Pay (Crypto)' && $g->getVersion() === SnapexPay::VERSION, 'name and version from config.json + language');
check(in_array('USD', $g->getCurrencies(), true) && in_array('EUR', $g->getCurrencies(), true), 'offered for USD and other currencies');
check($g->encryptableFields() === ['api_key', 'webhook_secret'], 'API key and webhook secret stored encrypted');
$g->install();
check(Record::$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name LIKE 'snapex_pay_invoices_%'")->fetchColumn() == 2, 'install() creates the table and indexes');
$g->install();
check(true, 'install() twice does not fail');

echo "settings\n";
$html = gateway()->getSettings($meta);
check(strpos($html, 'value="https://billing.host.example/callback/gw/1/snapex_pay/"') !== false, 'settings show the webhook URL for the cabinet');
check(strpos($html, 'name="partner_id"') !== false && strpos($html, '<option value="merchant" selected>') !== false, 'settings render Partner ID and fee payer');
$g = gateway();
$saved = $g->editSettings(['api_key' => ' sk_test ', 'webhook_secret' => 'whsec_x', 'fee_on' => 'customer', 'expires_minutes' => '', 'partner_id' => ' p-1 ']);
check(! $g->errors() && $saved['test_mode'] === 'false' && $saved['expires_minutes'] === '60' && $saved['api_key'] === 'sk_test' && $saved['partner_id'] === 'p-1' && $saved['api_url'] === SnapexPay::DEFAULT_API_URL, 'defaults filled, values trimmed');
$g = gateway();
$g->editSettings(['api_key' => '', 'webhook_secret' => '', 'fee_on' => 'x', 'expires_minutes' => '5000', 'partner_id' => str_repeat('a', 33), 'api_url' => 'ftp://x']);
check(array_keys($g->errors()) === ['api_key', 'webhook_secret', 'fee_on', 'expires_minutes', 'partner_id', 'api_url'], 'invalid settings rejected (webhook secret is required: Blesta has no gateway cron)');

echo "buildProcess\n";
$inv = invoice(101, 5, '45.00');
list($html, $g) = pay(5, 45, $inv);
check(payUrl($html) === 'https://pay.snapex.pro/i/inv_1' && strpos($html, 'Pay with crypto') !== false, 'button opens the SnapEX payment page');
$req = $GLOBALS['remote']['inv_1']['_request'];
check(preg_match('/^blesta-[0-9a-f]{10}-[0-9a-f]{16}-0-live-\d+$/', $req['external_id']) && $req['environment'] === 'live' && $req['expires_in'] === 1800
    && $req['fee_on'] === 'merchant' && $req['amount_usd'] === '45.00' && $req['description'] === 'Invoice #101', 'invoice request fields');
check($req['success_url'] === returnUrl(5).'&snapex_ref='.$req['external_id'] && $req['cancel_url'] === returnUrl(5), 'client returns to "payment received" with the payment reference');
check(! array_key_exists('partner_ref', $req), 'no Partner ID → no partner_ref');
check(end($GLOBALS['calls'])[2][3] === 'Idempotency-Key: '.$req['external_id'], 'Idempotency-Key is the external_id');
$row = (new Store(new Record))->find('inv_1');
check($row && $row->client_id == 5 && $row->invoices === '101=45.0000' && $row->amount === '45.0000' && $row->currency === 'USD', 'payment stored: client, invoices, amount');
list($again) = pay(5, 45, $inv);
check(payUrl($again) === payUrl($html) && count($GLOBALS['remote']) === 1, 'page reload reuses the open invoice');
Record::$pdo->exec("DELETE FROM snapex_pay_invoices");
list($race) = pay(5, 45, $inv);
check(payUrl($race) === payUrl($html) && count($GLOBALS['remote']) === 1, 'parallel load (row not stored yet): same key, one SnapEX invoice');

$invEur = invoice(102, 6, '40.00', 'EUR');
list($html, $g) = pay(6, 40, $invEur, [], 'EUR');
$eur = end($GLOBALS['remote']);
check($eur['amount_usd'] === '50.00' && (new Store(new Record))->find($eur['invoice_id'])->amount === '40.0000', 'EUR converted to USD with Blesta exchange rates; Blesta amount kept in EUR');
list($html, $g) = pay(6, 40, invoice(103, 6, '40.00', 'GBP'), [], 'GBP');
check($html === null && strpos(current(current($g->errors())), 'add USD') !== false, 'currency without a rate: clear error, no invoice');
unset($GLOBALS['blesta']['currencies']['USD']);
list($html, $g) = pay(6, 40, $invEur, [], 'EUR');
check($html === null && isset($g->errors()['currency']), 'USD missing in Blesta: clear error');
$GLOBALS['blesta']['currencies']['USD'] = 1.0;

list($html, $g) = pay(7, 0.5, invoice(104, 7, '0.50'));
check($html === null && current(current($g->errors())) === 'Could not start the crypto payment: Minimum invoice is $1.', 'API validation message reaches the client');
list($html, $g) = pay(7, 10, invoice(105, 7, '10.00'), ['api_key' => 'wrong']);
check(current(current($g->errors())) === 'Could not start the crypto payment: A valid merchant API key is required.', 'bad API key explained');

$count = count($GLOBALS['remote']);
list($html) = pay(8, 20, invoice(106, 8, '20.00'), ['test_mode' => 'true']);
check(payUrl($html) === null && strpos($html, 'being set up') !== false && count($GLOBALS['remote']) === $count, 'test mode: clients get no button and no invoice');
$_SESSION['blesta_staff_id'] = 1;
list($html) = pay(8, 20, invoice(106, 8, '20.00'), ['test_mode' => 'true']);
$_SESSION = [];
check(payUrl($html) !== null && end($GLOBALS['remote'])['environment'] === 'test' && strpos($html, 'Test mode') !== false, 'test mode: staff get a test invoice');

list($html) = pay(9, 20, invoice(107, 9, '20.00'), ['partner_id' => ' int-42 ']);
check((end($GLOBALS['remote'])['_request']['partner_ref'] ?? null) === 'int-42', 'Partner ID → partner_ref');

list($html) = pay(10, 12, invoice(108, 10, '12.00'), [], 'USD', 'https://billing.host.example/order/checkout/complete/vps/1234');
$req = end($GLOBALS['remote'])['_request'];
check($req['success_url'] === 'https://billing.host.example/order/checkout/complete/vps/1234', 'order form: client returns to the order page (webhook records the payment)');

echo "webhook\n";
$GLOBALS['blesta']['invoices'][101]['due'] = 45.0;
$paid = ['type' => 'invoice.paid', 'data' => ['invoice_id' => 'inv_1']];
$body = json_encode($paid);
SnapexPay::$request = ['body' => $body, 'headers' => ['Webhook-Timestamp' => (string) time(), 'Webhook-Signature' => 'v1=bad']];
check((new StubGatewayPayments)->processNotification('snapex_pay', [], []) === null && SnapexPay::$lastStatus === 401, 'bad signature 401');
check(webhook($paid, [], 'whsec_x', time() - 3600) === null && SnapexPay::$lastStatus === 400, 'stale timestamp 400');
check(webhook($paid, ['webhook_secret' => '']) === null && SnapexPay::$lastStatus === 503, 'no secret configured 503');
check(webhook($paid) === null && SnapexPay::$lastStatus === null && $GLOBALS['blesta']['transactions'] === [], 'body alone never pays: API still says open → nothing recorded');
$GLOBALS['remote']['inv_1']['status'] = 'paid';
$GLOBALS['api_down'] = true;
check(webhook($paid) === null && SnapexPay::$lastStatus === 502, 'API unreachable: 502 so SnapEX retries');
$GLOBALS['api_down'] = false;
$tx = webhook($paid);
check($tx && $tx->status === 'approved' && $tx->transaction_id === 'inv_1' && $tx->client_id === 5 && $tx->amount === '45.0000' && $tx->currency === 'USD', 'paid webhook records an approved transaction');
check($GLOBALS['blesta']['invoices'][101]['due'] == 0, 'Blesta invoice paid');
check(webhook($paid) === null && count($GLOBALS['blesta']['transactions']) === 1, 'immediate redelivery: claimed, nothing new');
expireClaims();
webhook($paid);
check(count($GLOBALS['blesta']['transactions']) === 1 && $GLOBALS['blesta']['invoices'][101]['due'] == 0 && $GLOBALS['blesta']['transactions'][1]['applied'] === [101 => 45.0], 'later redelivery: Blesta dedupes by transaction ID, no double payment');
check(webhook(['type' => 'invoice.paid', 'data' => ['invoice_id' => 'inv_999']]) === null && SnapexPay::$lastStatus === null, 'invoice of another shop ignored with 200');
check(webhook(['type' => 'payout.completed', 'data' => ['payout_id' => 'po_1']]) === null && SnapexPay::$lastStatus === null, 'other events ignored with 200');

echo "return page\n";
$inv = invoice(201, 11, '30.00');
list($html) = pay(11, 30, $inv);
$id = basename(payUrl($html));
$ref = $GLOBALS['remote'][$id]['external_id'];
$back = gateway()->success(['client_id' => 11, 'snapex_ref' => $ref], []);
check($back['status'] === 'pending' && $GLOBALS['blesta']['invoices'][201]['due'] == 30.0, 'return before payment: pending, nothing recorded');
$GLOBALS['remote'][$id]['status'] = 'paid';
$back = gateway()->success(['client_id' => 11, 'snapex_ref' => $ref], []);
check($back['status'] === 'approved' && $back['transaction_id'] === $id && $back['invoices'] === [['id' => 201, 'amount' => '30.0000']], 'return after payment: approved');
check($GLOBALS['blesta']['invoices'][201]['due'] == 0, 'return records the payment at once (through Blesta notification path)');
$n = count($GLOBALS['blesta']['transactions']);
webhook(['type' => 'invoice.paid', 'data' => ['invoice_id' => $id]]);
expireClaims();
webhook(['type' => 'invoice.paid', 'data' => ['invoice_id' => $id]]);
check(count($GLOBALS['blesta']['transactions']) === $n && $GLOBALS['blesta']['invoices'][201]['due'] == 0, 'webhook after the return does not double-pay');
check(gateway()->success(['client_id' => 12, 'snapex_ref' => $ref], []) === null, 'another client cannot read the payment');
check(gateway()->success(['client_id' => 11], []) === null, 'no reference: nothing to report');
check((new StubGatewayPayments)->processNotification('snapex_pay', ['check' => $ref, 'token' => 'forged'], []) === null
    && isset(end($GLOBALS['blesta']['notification_errors'])['check']), 'check link with a forged token refused');

echo "edge cases\n";
list($html) = pay(12, 30, invoice(301, 12, '30.00'));
$id = basename(payUrl($html));
$GLOBALS['remote'][$id]['status'] = 'partially_paid';
$GLOBALS['remote'][$id]['payment'] = ['received_amount' => '12.5', 'symbol' => 'USDT'];
$tx = webhook(['type' => 'invoice.partially_paid', 'data' => ['invoice_id' => $id]]);
check($tx && $tx->status === 'pending' && $tx->applied === [] && strpos($tx->message, '12.5 USDT') !== false && $GLOBALS['blesta']['invoices'][301]['due'] == 30.0, 'partial payment: pending transaction for review, nothing applied');

list($html) = pay(13, 30, invoice(302, 13, '30.00'));
$id = basename(payUrl($html));
$GLOBALS['remote'][$id]['status'] = 'paid';
$GLOBALS['remote'][$id]['amount_usd'] = '3.00';
check(webhook(['type' => 'invoice.paid', 'data' => ['invoice_id' => $id]]) === null && $GLOBALS['blesta']['invoices'][302]['due'] == 30.0, 'amount mismatch not recorded');
$GLOBALS['remote'][$id]['amount_usd'] = '30.00';
$GLOBALS['remote'][$id]['external_id'] = 'blesta-other';
check(webhook(['type' => 'invoice.paid', 'data' => ['invoice_id' => $id]]) === null && $GLOBALS['blesta']['invoices'][302]['due'] == 30.0, 'external_id mismatch not recorded');

$_SESSION['blesta_staff_id'] = 1;
list($html) = pay(14, 30, invoice(303, 14, '30.00'), ['test_mode' => 'true']);
$_SESSION = [];
$id = basename(payUrl($html));
$GLOBALS['remote'][$id]['status'] = 'paid';
check(webhook(['type' => 'invoice.paid', 'data' => ['invoice_id' => $id]]) === null && $GLOBALS['blesta']['invoices'][303]['due'] == 30.0, 'test payment ignored once test mode is off');
$tx = webhook(['type' => 'invoice.paid', 'data' => ['invoice_id' => $id]], ['test_mode' => 'true']);
check($tx && $tx->status === 'approved' && $tx->reference_id === 'test' && $GLOBALS['blesta']['invoices'][303]['due'] == 0, 'test payment recorded in test mode, marked "test"');

list($html) = pay(15, 30, invoice(304, 15, '30.00'));
$first = basename(payUrl($html));
$GLOBALS['remote'][$first]['status'] = 'expired';
webhook(['type' => 'invoice.expired', 'data' => ['invoice_id' => $first]]);
check((new Store(new Record))->find($first)->status === 'expired' && count(array_filter($GLOBALS['blesta']['transactions'], function ($t) use ($first) { return $t['transaction_id'] === $first; })) === 0, 'expired: status kept, no transaction');
list($html) = pay(15, 30, invoice(304, 15, '30.00'));
$second = basename(payUrl($html));
check($second !== $first && $GLOBALS['remote'][$second]['external_id'] !== $GLOBALS['remote'][$first]['external_id'], 'after expiry a new invoice with a new external_id');

check(SnapexPay::unserializeInvoices(SnapexPay::serializeInvoices([['id' => 1, 'amount' => 2.5], ['id' => 7, 'amount' => '10']])) === [['id' => 1, 'amount' => '2.5000'], ['id' => 7, 'amount' => '10.0000']], 'invoice list round-trips');
check(SnapexPay::unserializeInvoices('x=1|5') === [], 'garbage invoice list ignored');

echo "ru\n";
$ru = [];
$en = [];
$lang = [];
include __DIR__.'/../src/components/gateways/nonmerchant/snapex_pay/language/ru_ru/snapex_pay.php';
$ru = $lang;
$lang = [];
include __DIR__.'/../src/components/gateways/nonmerchant/snapex_pay/language/en_us/snapex_pay.php';
$en = $lang;
check(array_keys($ru) === array_keys($en), 'ru_ru has every en_us key');
$used = [];
foreach (glob(__DIR__.'/../src/components/gateways/nonmerchant/snapex_pay/{*.php,views/default/*.pdt}', GLOB_BRACE) as $file) {
    preg_match_all("/'(SnapexPay\.[A-Za-z0-9_.!]+)'/", file_get_contents($file), $m);
    $used = array_merge($used, $m[1]);
}
$used = array_filter(array_unique($used), function ($k) { return substr($k, -1) !== '.'; });
check(array_diff($used, array_keys($en)) === [], 'every language key used in code exists');

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
