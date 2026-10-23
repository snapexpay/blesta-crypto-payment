<?php

namespace SnapexPayBlesta;

/**
 * Links Blesta payments (client + invoices + amount) to SnapEX Pay invoices. One payment may get several
 * attempts (expired, amount changed). Plain SQL that runs on MySQL/MariaDB (Blesta) and SQLite (tests).
 */
class Store
{
    const TABLE = 'snapex_pay_invoices';

    /** Statuses the client can still pay. */
    const OPEN_STATUSES = ['open', 'awaiting'];

    /** A claim on a payment expires after this many seconds (crashed request) — Blesta dedupes after that. */
    const CLAIM_TTL = 120;

    /** @var object Blesta Record (minPHP PDO wrapper): query($sql, array $params) returns a PDOStatement */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function ensureTable()
    {
        $this->db->query(
            'CREATE TABLE IF NOT EXISTS '.self::TABLE.' ('
            .'snapex_invoice_id VARCHAR(64) NOT NULL PRIMARY KEY, '
            .'external_id VARCHAR(191) NOT NULL, '
            .'company_id INT NOT NULL, '
            .'client_id INT NOT NULL, '
            .'fingerprint CHAR(40) NOT NULL, '
            .'invoices TEXT NOT NULL, '
            .'amount VARCHAR(32) NOT NULL, '
            .'currency CHAR(3) NOT NULL, '
            .'amount_usd VARCHAR(32) NOT NULL, '
            .'environment VARCHAR(8) NOT NULL, '
            .'pay_url TEXT NOT NULL, '
            .'status VARCHAR(24) NOT NULL, '
            .'expires_at DATETIME NULL, '
            .'claimed_at DATETIME NULL, '
            .'created_at DATETIME NOT NULL, '
            .'updated_at DATETIME NOT NULL)',
            []
        );
    }

    /** Called once from install(): indexes for reuse lookups (CREATE INDEX has no IF NOT EXISTS on MySQL). */
    public function addIndexes()
    {
        foreach (['fingerprint', 'external_id'] as $column) {
            try {
                $this->db->query('CREATE INDEX '.self::TABLE.'_'.$column.' ON '.self::TABLE.' ('.$column.')', []);
            } catch (\Exception $e) {
                // already there
            }
        }
    }

    public function insert(array $row)
    {
        $now = self::now();
        $row += ['claimed_at' => null, 'created_at' => $now, 'updated_at' => $now];
        $columns = array_keys($row);
        $this->db->query(
            'INSERT INTO '.self::TABLE.' ('.implode(', ', $columns).') VALUES ('.implode(', ', array_fill(0, count($columns), '?')).')',
            array_values($row)
        );
    }

    public function find($snapexInvoiceId)
    {
        return $this->first('SELECT * FROM '.self::TABLE.' WHERE snapex_invoice_id = ?', [(string) $snapexInvoiceId]);
    }

    public function findByExternalId($externalId)
    {
        return $this->first('SELECT * FROM '.self::TABLE.' WHERE external_id = ?', [(string) $externalId]);
    }

    /** An attempt the client can still pay: same payment, not expiring within a few minutes. */
    public function reusable($fingerprint)
    {
        return $this->first(
            'SELECT * FROM '.self::TABLE.' WHERE fingerprint = ? AND status IN (?, ?) AND expires_at > ? ORDER BY created_at DESC',
            [(string) $fingerprint, self::OPEN_STATUSES[0], self::OPEN_STATUSES[1], gmdate('Y-m-d H:i:s', time() + 300)]
        );
    }

    /** How many SnapEX invoices this payment already has (part of the next external_id). */
    public function countFor($fingerprint)
    {
        $row = $this->first('SELECT COUNT(*) AS n FROM '.self::TABLE.' WHERE fingerprint = ?', [(string) $fingerprint]);

        return $row ? (int) $row->n : 0;
    }

    public function setStatus($snapexInvoiceId, $status)
    {
        $this->db->query(
            'UPDATE '.self::TABLE.' SET status = ?, updated_at = ? WHERE snapex_invoice_id = ?',
            [(string) $status, self::now(), (string) $snapexInvoiceId]
        );
    }

    /**
     * Webhook and return page can arrive together, and Blesta looks a transaction up before adding it,
     * so two parallel requests could both add one. Only the request holding the claim reports the payment;
     * once the claim is stale, Blesta already has the transaction and dedupes by its ID.
     */
    public function claim($snapexInvoiceId)
    {
        $now = time();

        return $this->db->query(
            'UPDATE '.self::TABLE.' SET claimed_at = ?, updated_at = ? WHERE snapex_invoice_id = ? AND (claimed_at IS NULL OR claimed_at < ?)',
            [gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $now), (string) $snapexInvoiceId, gmdate('Y-m-d H:i:s', $now - self::CLAIM_TTL)]
        )->rowCount() === 1;
    }

    private function first($sql, array $params)
    {
        $row = $this->db->query($sql, $params)->fetch(\PDO::FETCH_OBJ);

        return $row ?: null;
    }

    public static function now()
    {
        return gmdate('Y-m-d H:i:s');
    }
}
