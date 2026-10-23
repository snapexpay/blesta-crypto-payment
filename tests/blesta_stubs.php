<?php
/**
 * The bits of Blesta the gateway touches, for tests. Signatures follow Blesta 6.0 (components/gateways/lib,
 * minPHP Record/Input/Language/Form). GatewayPayments::processNotification() keeps the rules of the real one
 * (components/gateway_payments/gateway_payments.php): a transaction is found by transaction_id + gateway and
 * updated instead of added again, an approved status is not moved backwards, and only amounts not yet applied
 * are applied to invoices. State lives in $GLOBALS['blesta'].
 */

if (! defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
if (! defined('ROOTWEBDIR')) {
    define('ROOTWEBDIR', dirname(__DIR__).DS.'src'.DS);
}

function blesta_stub_boot()
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    Record::$pdo = $pdo;
    Configure::$values = [
        'Blesta.company_id' => 1,
        'Blesta.gw_callback_url' => 'https://billing.host.example/callback/gw/',
    ];
    $GLOBALS['blesta'] = [
        'meta' => [],
        'transactions' => [],   // id => ['transaction_id', 'client_id', 'amount', 'currency', 'status', 'message', 'applied' => [invoice => amount]]
        'invoices' => [],       // id => ['client_id', 'currency', 'due']
        'currencies' => ['USD' => 1.0, 'EUR' => 0.8],
        'log' => [],
        'notification_errors' => [],
    ];
    $_SESSION = [];
    SnapexPay::$request = null;
}

class Configure
{
    public static $values = [];

    public static function get($key)
    {
        return isset(self::$values[$key]) ? self::$values[$key] : null;
    }

    public static function set($key, $value)
    {
        self::$values[$key] = $value;
    }
}

class Language
{
    public static $lang = [];

    public static function loadLang($file, $language = null, $dir = null)
    {
        $lang = [];
        include $dir.($language ?: 'en_us').DS.$file.'.php';
        self::$lang = $lang + self::$lang;
    }

    public static function _($key, $return = false)
    {
        $args = array_slice(func_get_args(), 2);
        $text = isset(self::$lang[$key]) ? self::$lang[$key] : $key;
        $text = $args ? vsprintf($text, $args) : $text;
        if ($return) {
            return $text;
        }
        echo $text;
    }
}

class Loader
{
    public static function loadComponents($parent, array $names)
    {
        foreach ($names as $name) {
            $class = 'Stub'.$name;
            $parent->$name = new $class;
        }
    }

    public static function loadModels($parent, array $names)
    {
        self::loadComponents($parent, $names);
    }

    public static function loadHelpers($parent, array $names)
    {
        foreach ($names as $name) {
            $class = 'Stub'.$name;
            $parent->$name = new $class;
            if (isset($parent->view) && is_object($parent->view)) {
                $parent->view->$name = $parent->$name;
            }
        }
    }
}

/** minPHP Record: only query($sql, array $params) is used by the gateway. */
class Record
{
    /** @var PDO */
    public static $pdo;

    public function query($sql, $params = [])
    {
        $statement = self::$pdo->prepare($sql);
        $statement->execute((array) $params);

        return $statement;
    }
}

/** Blesta Input: rules with a callable 'rule' (value → bool), errors keyed [field][rule] => message. */
class StubInput
{
    private $rules = [];

    private $errors = [];

    public function setRules(array $rules)
    {
        $this->rules = $rules;
    }

    public function validates(array $vars)
    {
        foreach ($this->rules as $field => $checks) {
            foreach ($checks as $name => $check) {
                $value = isset($vars[$field]) ? $vars[$field] : null;
                if (! call_user_func($check['rule'], $value)) {
                    $this->errors[$field][$name] = $check['message'];
                }
            }
        }

        return empty($this->errors);
    }

    public function setErrors(array $errors)
    {
        $this->errors = $errors;
    }

    public function errors()
    {
        return $this->errors ?: false;
    }
}

class StubHtml
{
    public function safe($str)
    {
        return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
    }
}

class StubForm
{
    public function label($name, $for = null, array $attributes = null)
    {
        echo '<label for="'.$for.'">'.htmlspecialchars($name).'</label>';
    }

    public function fieldText($name, $value = null, $attributes = [])
    {
        echo '<input type="text" name="'.$name.'" value="'.htmlspecialchars((string) $value, ENT_QUOTES).'">';
    }

    public function fieldCheckbox($name, $value = null, $checked = false, $attributes = [])
    {
        echo '<input type="checkbox" name="'.$name.'" value="'.$value.'"'.($checked ? ' checked' : '').'>';
    }

    public function fieldSelect($name, $options = [], $selected = null, $attributes = [])
    {
        echo '<select name="'.$name.'">';
        foreach ($options as $value => $label) {
            echo '<option value="'.$value.'"'.((string) $value === (string) $selected ? ' selected' : '').'>'.htmlspecialchars($label).'</option>';
        }
        echo '</select>';
    }
}

class StubView
{
    private $file;

    private $vars = [];

    public function __construct($file)
    {
        $this->file = $file;
    }

    public function set($name, $value)
    {
        $this->vars[$name] = $value;
    }

    public function _($key, $return = false)
    {
        return call_user_func_array(['Language', '_'], func_get_args());
    }

    public function fetch()
    {
        extract($this->vars);
        ob_start();
        include $this->file;

        return ob_get_clean();
    }
}

class StubCurrencies
{
    public function get($code, $companyId)
    {
        $rates = $GLOBALS['blesta']['currencies'];

        return isset($rates[$code]) ? (object) ['code' => $code, 'exchange_rate' => $rates[$code]] : false;
    }

    public function convert($amount, $from, $to, $companyId)
    {
        $rates = $GLOBALS['blesta']['currencies'];

        return round($amount * ($rates[$to] / $rates[$from]), 4);
    }
}

/** components/gateways/lib/gateway.php + nonmerchant_gateway.php, reduced to what the gateway uses. */
abstract class NonmerchantGateway
{
    protected $gateway_id = 7;

    protected $config;

    abstract public function setCurrency($currency);

    abstract public function setMeta(array $meta = null);

    abstract public function getSettings(array $meta = null);

    abstract public function editSettings(array $meta);

    abstract public function encryptableFields();

    abstract public function validate(array $get, array $post);

    abstract public function success(array $get, array $post);

    public function getName()
    {
        return Language::_($this->config->name, true);
    }

    public function getVersion()
    {
        return $this->config->version;
    }

    public function getCurrencies()
    {
        return $this->config->currencies;
    }

    public function errors()
    {
        return isset($this->Input) ? $this->Input->errors() : false;
    }

    public function setGatewayId($id)
    {
        $this->gateway_id = $id;
    }

    protected function loadConfig($file)
    {
        $this->config = json_decode(file_get_contents($file));
    }

    protected function makeView($file, $view = 'default', $view_path = null)
    {
        return new StubView(ROOTWEBDIR.$view_path.'views'.DS.$view.DS.$file.'.pdt');
    }

    protected function log($url, $data = null, $direction = 'input', $success = false)
    {
        $GLOBALS['blesta']['log'][] = [$url, $data, $direction, $success];
    }

    protected function getCommonError($type)
    {
        return [$type => ['response' => $type]];
    }
}

/** GatewayPayments::processNotification() for a non-merchant gateway, with the same recording rules. */
class StubGatewayPayments
{
    public function processNotification($gatewayName, array $get, array $post)
    {
        $gateway = new SnapexPay;
        $gateway->setMeta($GLOBALS['blesta']['meta']);
        $gateway->setGatewayId(7);
        $gateway->setCurrency(null);
        $response = $gateway->validate($get, $post);
        if (! is_array($response)) {
            if ($errors = $gateway->errors()) {
                $GLOBALS['blesta']['notification_errors'][] = $errors;
            }

            return null;
        }

        $tx = &$GLOBALS['blesta']['transactions'];
        $id = null;
        foreach ($tx as $key => $row) {
            if ($row['transaction_id'] === $response['transaction_id'] && $row['gateway_id'] === 7) {
                $id = $key;
            }
        }
        if ($id !== null) {
            // status may only move forward (pending → approved), client is never reassigned
            if ($tx[$id]['status'] === 'pending' || $response['status'] === $tx[$id]['status']) {
                $tx[$id]['status'] = $response['status'];
            }
            $tx[$id]['amount'] = $response['amount'];
            $tx[$id]['message'] = isset($response['message']) ? $response['message'] : '';
        } else {
            $id = count($tx) + 1;
            $tx[$id] = [
                'transaction_id' => $response['transaction_id'], 'gateway_id' => 7, 'client_id' => $response['client_id'],
                'amount' => $response['amount'], 'currency' => $response['currency'], 'status' => $response['status'],
                'reference_id' => isset($response['reference_id']) ? $response['reference_id'] : null,
                'message' => isset($response['message']) ? $response['message'] : '', 'applied' => [],
            ];
        }

        if ($response['status'] === 'approved' && $tx[$id]['status'] === 'approved' && ! empty($response['invoices'])) {
            foreach ($response['invoices'] as $invoice) {
                $invoiceId = (int) $invoice['id'];
                $already = isset($tx[$id]['applied'][$invoiceId]) ? $tx[$id]['applied'][$invoiceId] : 0;
                $amount = min((float) $invoice['amount'] - $already, $GLOBALS['blesta']['invoices'][$invoiceId]['due']);
                if ($amount > 0) {
                    $tx[$id]['applied'][$invoiceId] = $already + $amount;
                    $GLOBALS['blesta']['invoices'][$invoiceId]['due'] -= $amount;
                }
            }
        }

        return (object) $tx[$id];
    }
}
