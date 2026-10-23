<?php
/**
 * SnapEX Pay — crypto payments for Blesta (non-merchant gateway).
 *
 * The client pays on the SnapEX Pay page (USDT on several networks or other coins swapped to USDT).
 * The payment is recorded from the signed webhook or when the client comes back to the payment page,
 * and in both cases only after the invoice is read back from the SnapEX Pay API.
 *
 * @link https://pay.snapex.pro
 */

require_once __DIR__.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'Api.php';
require_once __DIR__.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'Store.php';

use SnapexPayBlesta\Api;
use SnapexPayBlesta\ApiException;
use SnapexPayBlesta\Store;

class SnapexPay extends NonmerchantGateway
{
    const VERSION = '1.0.0';

    /** Directory name of the gateway in components/gateways/nonmerchant/. */
    const NAME = 'snapex_pay';

    const DEFAULT_API_URL = 'https://snapex.pro/api/v1/merchant';

    /** Webhook timestamps older than this are rejected (replay protection). */
    const WEBHOOK_TOLERANCE = 300;

    /** @var array|null test hook: ['body' => string, 'headers' => array] instead of php://input and $_SERVER */
    public static $request;

    /** @var int|null HTTP status set for the last callback (tests read it) */
    public static $lastStatus;

    /** @var array gateway settings */
    private $meta = [];

    /** @var string ISO 4217 code of the payment */
    private $currency;

    /** @var object|null Blesta view (public: Loader::loadHelpers() attaches helpers to it) */
    public $view;

    public function __construct()
    {
        Language::loadLang(self::NAME, null, dirname(__FILE__).DS.'language'.DS);
        $this->loadConfig(dirname(__FILE__).DS.'config.json');
        Loader::loadComponents($this, ['Input']);
    }

    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    public function setMeta(array $meta = null)
    {
        $this->meta = (array) $meta;
    }

    // ------------------------------------------------------------------ settings

    public function getSettings(array $meta = null)
    {
        $this->view = $this->makeView('settings', 'default', str_replace(ROOTWEBDIR, '', dirname(__FILE__).DS));
        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('meta', (array) $meta + ['fee_on' => 'merchant', 'expires_minutes' => '60', 'api_url' => self::DEFAULT_API_URL]);
        $this->view->set('webhook_url', self::webhookUrl());
        $this->view->set('fee_options', [
            'merchant' => Language::_('SnapexPay.fee_on.merchant', true),
            'customer' => Language::_('SnapexPay.fee_on.customer', true),
        ]);

        return $this->view->fetch();
    }

    public function editSettings(array $meta)
    {
        foreach (['api_key', 'webhook_secret', 'partner_id', 'api_url', 'expires_minutes'] as $field) {
            $meta[$field] = trim(isset($meta[$field]) ? (string) $meta[$field] : '');
        }
        $meta['test_mode'] = (isset($meta['test_mode']) && $meta['test_mode'] === 'true') ? 'true' : 'false';
        if ($meta['expires_minutes'] === '') {
            $meta['expires_minutes'] = '60';
        }
        if ($meta['api_url'] === '') {
            $meta['api_url'] = self::DEFAULT_API_URL;
        }

        $this->Input->setRules([
            'api_key' => ['empty' => [
                'rule' => function ($value) { return trim((string) $value) !== ''; },
                'message' => Language::_('SnapexPay.!error.api_key.empty', true),
            ]],
            'webhook_secret' => ['empty' => [
                'rule' => function ($value) { return trim((string) $value) !== ''; },
                'message' => Language::_('SnapexPay.!error.webhook_secret.empty', true),
            ]],
            'fee_on' => ['valid' => [
                'rule' => function ($value) { return in_array($value, ['merchant', 'customer'], true); },
                'message' => Language::_('SnapexPay.!error.fee_on.valid', true),
            ]],
            'expires_minutes' => ['valid' => [
                'rule' => function ($value) { return ctype_digit((string) $value) && (int) $value >= 1 && (int) $value <= 1440; },
                'message' => Language::_('SnapexPay.!error.expires_minutes.valid', true),
            ]],
            'partner_id' => ['valid' => [
                'rule' => function ($value) { return strlen((string) $value) <= 32; },
                'message' => Language::_('SnapexPay.!error.partner_id.valid', true),
            ]],
            'api_url' => ['valid' => [
                'rule' => function ($value) { return (bool) preg_match('#^https://[^\s/]+#i', (string) $value); },
                'message' => Language::_('SnapexPay.!error.api_url.valid', true),
            ]],
        ]);
        $this->Input->validates($meta);

        return $meta;
    }

    public function encryptableFields()
    {
        return ['api_key', 'webhook_secret'];
    }

    public function install()
    {
        $store = $this->store();
        $store->ensureTable();
        $store->addIndexes();
    }

    /** The table is kept on uninstall: a late webhook for an old payment can still be matched after a reinstall. */
    public function uninstall($gateway_id, $last_instance)
    {
    }

    public static function webhookUrl()
    {
        return Configure::get('Blesta.gw_callback_url').Configure::get('Blesta.company_id').'/'.self::NAME.'/';
    }

    // ------------------------------------------------------------------ payment page

    /**
     * Creates (or reuses) a SnapEX Pay invoice for this payment and returns the button that opens it.
     * Called when the client confirms the payment (client area) or completes an order (order form).
     */
    public function buildProcess(array $contact_info, $amount, array $invoice_amounts = null, array $options = null)
    {
        $clientId = isset($contact_info['client_id']) ? (int) $contact_info['client_id'] : 0;
        $companyId = (int) Configure::get('Blesta.company_id');
        $currency = strtoupper((string) $this->currency);
        $environment = $this->testMode() ? 'test' : 'live';

        if ($this->testMode() && ! self::isStaff()) {
            return $this->render(['notice' => Language::_('SnapexPay.process.test_mode_clients', true)]);
        }

        $local = self::decimal($amount);
        $usd = $this->toUsd($local, $currency, $companyId);
        if ($usd === null) {
            return $this->fail('currency', Language::_('SnapexPay.!error.currency.usd_missing', true, $currency));
        }
        if ((float) $usd <= 0 || $clientId <= 0) {
            return $this->fail('amount', Language::_('SnapexPay.!error.amount.invalid', true));
        }

        $invoices = self::serializeInvoices((array) $invoice_amounts);
        $fingerprint = sha1(implode('|', [$companyId, $clientId, $invoices, $local, $currency, $usd, $environment]));
        $store = $this->store();
        $store->ensureTable();

        $existing = $store->reusable($fingerprint);
        if ($existing) {
            return $this->render(['pay_url' => $existing->pay_url]);
        }

        $minutes = isset($this->meta['expires_minutes']) ? (int) $this->meta['expires_minutes'] : 60;
        $minutes = max(1, min(1440, $minutes ?: 60));
        // external_id doubles as the Idempotency-Key. SnapEX rejects a reused external_id, so it changes with the
        // attempt number and minute: double loads collapse into one SnapEX invoice, a retry after expiry gets a new one.
        $externalId = self::externalId($fingerprint, $store->countFor($fingerprint), $environment, time());
        $returnUrl = isset($options['return_url']) ? (string) $options['return_url'] : '';
        $data = [
            'amount_usd' => $usd,
            'fee_on' => (isset($this->meta['fee_on']) && $this->meta['fee_on'] === 'customer') ? 'customer' : 'merchant',
            'environment' => $environment,
            'external_id' => $externalId,
            'description' => self::description($options),
            'expires_in' => $minutes * 60,
        ];
        if (preg_match('#^https?://#i', $returnUrl)) {
            $data['success_url'] = self::successUrl($returnUrl, $externalId);
            $data['cancel_url'] = $returnUrl;
        }
        $partnerId = isset($this->meta['partner_id']) ? trim((string) $this->meta['partner_id']) : '';
        if ($partnerId !== '') {
            $data['partner_ref'] = $partnerId;
        }

        try {
            $created = $this->api()->createInvoice($data, $externalId);
        } catch (ApiException $e) {
            $this->log('createInvoice', json_encode(['request' => $data, 'error' => $e->getMessage()]), 'output', false);

            return $this->fail('snapex', Language::_('SnapexPay.!error.create', true, $e->getMessage()));
        }
        $this->log('createInvoice', json_encode(['request' => $data, 'invoice_id' => isset($created['invoice_id']) ? $created['invoice_id'] : null]), 'output', true);
        if (empty($created['invoice_id']) || empty($created['pay_url'])) {
            return $this->fail('snapex', Language::_('SnapexPay.!error.create', true, 'empty response'));
        }

        if (! $store->find($created['invoice_id'])) {
            $store->insert([
                'snapex_invoice_id' => (string) $created['invoice_id'],
                'external_id' => $externalId,
                'company_id' => $companyId,
                'client_id' => $clientId,
                'fingerprint' => $fingerprint,
                'invoices' => $invoices,
                'amount' => $local,
                'currency' => $currency,
                'amount_usd' => $usd,
                'environment' => $environment,
                'pay_url' => (string) $created['pay_url'],
                'status' => isset($created['status']) ? (string) $created['status'] : 'open',
                'expires_at' => self::dbTime(isset($created['expires_at']) ? $created['expires_at'] : null),
            ]);
        }

        return $this->render(['pay_url' => (string) $created['pay_url']]);
    }

    /** blesta-{site}-{payment}-{attempt}-{environment}-{minute}; site: a short hash of the Blesta callback URL. */
    public static function externalId($fingerprint, $attempt, $environment, $time)
    {
        return 'blesta-'.substr(sha1((string) Configure::get('Blesta.gw_callback_url')), 0, 10).'-'.substr((string) $fingerprint, 0, 16)
            .'-'.(int) $attempt.'-'.$environment.'-'.intdiv((int) $time, 60);
    }

    /** USD amount for SnapEX: as is for USD, otherwise through the company's Blesta exchange rates. Null if USD is not set up. */
    private function toUsd($amount, $currency, $companyId)
    {
        if ($currency === 'USD') {
            return self::money($amount);
        }
        Loader::loadModels($this, ['Currencies']);
        $from = $this->Currencies->get($currency, $companyId);
        $to = $this->Currencies->get('USD', $companyId);
        if (! $from || ! $to || (float) $from->exchange_rate <= 0 || (float) $to->exchange_rate <= 0) {
            return null;
        }

        return self::money($this->Currencies->convert($amount, $currency, 'USD', $companyId));
    }

    private static function successUrl($returnUrl, $externalId)
    {
        // Only the client area "payment received" page calls success(); the order form returns to its own page.
        if (strpos($returnUrl, '/pay/received/') === false) {
            return $returnUrl;
        }

        return $returnUrl.(strpos($returnUrl, '?') === false ? '?' : '&').'snapex_ref='.rawurlencode($externalId);
    }

    private static function description($options)
    {
        $text = trim(isset($options['description']) ? (string) $options['description'] : '');
        if ($text === '') {
            $text = 'Blesta payment';
        }

        return function_exists('mb_substr') ? mb_substr($text, 0, 255) : substr($text, 0, 255);
    }

    private function render(array $vars)
    {
        $this->view = $this->makeView('process', 'default', str_replace(ROOTWEBDIR, '', dirname(__FILE__).DS));
        Loader::loadHelpers($this, ['Form', 'Html']);
        $vars += ['pay_url' => null, 'notice' => null, 'test_mode' => $this->testMode()];
        foreach ($vars as $name => $value) {
            $this->view->set($name, $value);
        }

        return $this->view->fetch();
    }

    private function fail($field, $message)
    {
        $this->Input->setErrors([$field => ['response' => $message]]);

        return null;
    }

    // ------------------------------------------------------------------ notifications

    /**
     * Blesta calls this for /callback/gw/{company}/snapex_pay/: the signed SnapEX webhook, or the re-check started by
     * success() when the client is back. Whatever triggered it, the invoice is read from the SnapEX API, and the
     * payment data comes from that response and from the record made in buildProcess(), never from the request.
     *
     * @return array|null transaction data for Blesta, or null when there is nothing to record
     */
    public function validate(array $get, array $post)
    {
        self::$lastStatus = null;
        if (isset($get['check'])) {
            $row = $this->checkRequest($get);
        } else {
            $row = $this->webhookRequest();
        }
        if (! $row) {
            return null;
        }

        try {
            $remote = $this->api()->invoice($row->snapex_invoice_id);
        } catch (ApiException $e) {
            $this->log('getInvoice', $e->getMessage(), 'output', false);
            $this->respond(502); // SnapEX retries the webhook

            return $this->fail('snapex', Language::_('SnapexPay.!error.unreachable', true, $e->getMessage()));
        }

        return $this->transaction($row, $remote);
    }

    /** Signed webhook. Returns the stored record of the invoice it is about, or null. */
    private function webhookRequest()
    {
        list($body, $headers) = self::incoming();
        $secret = isset($this->meta['webhook_secret']) ? trim((string) $this->meta['webhook_secret']) : '';
        if ($secret === '') {
            $this->respond(503);

            return $this->fail('webhook', Language::_('SnapexPay.!error.webhook_secret.empty', true));
        }
        $timestamp = isset($headers['webhook-timestamp']) ? (string) $headers['webhook-timestamp'] : '';
        $signature = isset($headers['webhook-signature']) ? (string) $headers['webhook-signature'] : '';
        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::WEBHOOK_TOLERANCE) {
            $this->respond(400);

            return $this->fail('webhook', Language::_('SnapexPay.!error.webhook.stale', true));
        }
        if (! hash_equals('v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret), $signature)) {
            $this->respond(401);

            return $this->fail('webhook', Language::_('SnapexPay.!error.webhook.signature', true));
        }
        $this->log('webhook', $body, 'input', true);

        $event = json_decode($body, true);
        $type = is_array($event) && isset($event['type']) ? (string) $event['type'] : '';
        $snapexId = is_array($event) && isset($event['data']['invoice_id']) && is_scalar($event['data']['invoice_id']) ? (string) $event['data']['invoice_id'] : '';
        if (strpos($type, 'invoice.') !== 0 || $snapexId === '') {
            return null; // other events: 200, nothing to do
        }
        $row = $this->store()->find($snapexId);
        if (! $row) {
            return null; // the same merchant account may serve other shops
        }
        if ($type === 'invoice.extra_payment') {
            $this->log('webhook', 'Extra payment received for '.$snapexId.' — check the SnapEX Pay cabinet', 'output', true);
        }

        return $row;
    }

    /** Re-check started by success(). The token stops outsiders from using the callback to poll the API. */
    private function checkRequest(array $get)
    {
        $ref = (string) $get['check'];
        $token = isset($get['token']) ? (string) $get['token'] : '';
        if (! hash_equals($this->checkToken($ref), $token)) {
            return $this->fail('check', Language::_('SnapexPay.!error.check.token', true));
        }

        return $this->store()->findByExternalId($ref);
    }

    private function checkToken($ref)
    {
        return hash_hmac('sha256', 'check|'.$ref, (string) (isset($this->meta['api_key']) ? $this->meta['api_key'] : ''));
    }

    /** Turns the SnapEX invoice into Blesta transaction data. */
    private function transaction($row, array $remote)
    {
        $status = isset($remote['status']) ? (string) $remote['status'] : '';
        $remoteUsd = isset($remote['amount_usd']) ? self::money($remote['amount_usd']) : '';
        if ((string) (isset($remote['invoice_id']) ? $remote['invoice_id'] : '') !== (string) $row->snapex_invoice_id
            || (string) (isset($remote['external_id']) ? $remote['external_id'] : '') !== (string) $row->external_id
            || (string) (isset($remote['environment']) ? $remote['environment'] : '') !== (string) $row->environment
            || $remoteUsd !== self::money($row->amount_usd)) {
            $this->log('getInvoice', json_encode(['record' => (array) $row, 'remote' => $remote]), 'output', false);

            return $this->fail('snapex', Language::_('SnapexPay.!error.mismatch', true));
        }
        $this->store()->setStatus($row->snapex_invoice_id, $status);

        if ($row->environment === 'test' && ! $this->testMode()) {
            $this->log('getInvoice', 'Test payment '.$row->snapex_invoice_id.' while test mode is off — not recorded', 'output', false);

            return null;
        }

        $data = [
            'client_id' => (int) $row->client_id,
            'amount' => $row->amount,
            'currency' => $row->currency,
            'reference_id' => $row->environment === 'test' ? 'test' : null,
            'transaction_id' => (string) $row->snapex_invoice_id,
            'parent_transaction_id' => null,
        ];
        if (! in_array($status, ['paid', 'partially_paid'], true)) {
            return null; // open, awaiting, expired: nothing to record
        }
        if (! $this->store()->claim($row->snapex_invoice_id)) {
            return null; // the webhook and the return page raced; the other request records it
        }
        if ($status === 'paid') {
            return $data + ['status' => 'approved', 'invoices' => self::unserializeInvoices($row->invoices)];
        }
        $payment = isset($remote['payment']) && is_array($remote['payment']) ? $remote['payment'] : [];
        $received = isset($payment['received_amount']) ? $payment['received_amount'].' '.(isset($payment['symbol']) ? $payment['symbol'] : '') : '?';

        // Partially paid: pending, nothing applied — staff sees it under the client's transactions and decides.
        return $data + [
            'status' => 'pending',
            'invoices' => [],
            'message' => Language::_('SnapexPay.transaction.partial', true, trim($received)),
        ];
    }

    /**
     * Client is back on "payment received": settle right away through Blesta's own notification path (so the
     * transaction is recorded exactly as for a webhook), then report what Blesta now knows.
     */
    public function success(array $get, array $post)
    {
        $ref = isset($get['snapex_ref']) ? (string) $get['snapex_ref'] : '';
        $row = $ref !== '' ? $this->store()->findByExternalId($ref) : null;
        if (! $row || (isset($get['client_id']) && (int) $get['client_id'] !== (int) $row->client_id)) {
            return null;
        }

        Loader::loadComponents($this, ['GatewayPayments']);
        $this->GatewayPayments->processNotification(self::NAME, ['check' => $ref, 'token' => $this->checkToken($ref)], []);

        $row = $this->store()->findByExternalId($ref);

        return [
            'client_id' => (int) $row->client_id,
            'amount' => $row->amount,
            'currency' => $row->currency,
            'invoices' => self::unserializeInvoices($row->invoices),
            'status' => $row->status === 'paid' ? 'approved' : 'pending',
            'transaction_id' => (string) $row->snapex_invoice_id,
            'parent_transaction_id' => null,
        ];
    }

    public function capture($reference_id, $transaction_id, $amount, array $invoice_amounts = null)
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    public function refund($reference_id, $transaction_id, $amount, $notes = null)
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    public function void($reference_id, $transaction_id, $notes = null)
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    // ------------------------------------------------------------------ helpers

    private function testMode()
    {
        return isset($this->meta['test_mode']) && $this->meta['test_mode'] === 'true';
    }

    /** Staff logged in to the admin area (also when they log in as a client). */
    private static function isStaff()
    {
        return ! empty($_SESSION['blesta_staff_id']);
    }

    private function api()
    {
        $url = isset($this->meta['api_url']) ? trim((string) $this->meta['api_url']) : '';

        return new Api($url !== '' ? $url : self::DEFAULT_API_URL, isset($this->meta['api_key']) ? $this->meta['api_key'] : '');
    }

    private function store()
    {
        return new Store(new Record());
    }

    private function respond($status)
    {
        self::$lastStatus = $status;
        if (! headers_sent()) {
            http_response_code($status);
        }
    }

    /** @return array{0:string,1:array} raw body and lower-cased headers */
    private static function incoming()
    {
        if (self::$request !== null) {
            return [(string) self::$request['body'], array_change_key_case(self::$request['headers'], CASE_LOWER)];
        }
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (strpos($name, 'HTTP_') === 0) {
                $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
            }
        }

        return [(string) file_get_contents('php://input'), $headers];
    }

    /** "12.5" → "12.50": the same amount always gives the same string. */
    public static function money($amount)
    {
        return number_format(round((float) $amount, 2), 2, '.', '');
    }

    private static function decimal($amount)
    {
        return number_format(round((float) $amount, 4), 4, '.', '');
    }

    /** invoice_id=amount|invoice_id=amount */
    public static function serializeInvoices(array $invoices)
    {
        $parts = [];
        foreach ($invoices as $invoice) {
            if (isset($invoice['id'], $invoice['amount'])) {
                $parts[] = (int) $invoice['id'].'='.self::decimal($invoice['amount']);
            }
        }

        return implode('|', $parts);
    }

    public static function unserializeInvoices($str)
    {
        $invoices = [];
        foreach (explode('|', (string) $str) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2 && ctype_digit($parts[0])) {
                $invoices[] = ['id' => (int) $parts[0], 'amount' => $parts[1]];
            }
        }

        return $invoices;
    }

    private static function dbTime($iso)
    {
        if (! is_string($iso) || $iso === '') {
            return null;
        }
        $ts = strtotime($iso);

        return $ts === false ? null : gmdate('Y-m-d H:i:s', $ts);
    }
}
