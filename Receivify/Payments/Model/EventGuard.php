<?php
/**
 * Receivify for Magento 2 — webhook de-duplication.
 *
 * Delivery is at-least-once, and both a retry and a manual replay re-send the
 * IDENTICAL bytes with the same `created_at` and `sequence`. `(event, sequence)`
 * is the de-duplication key — there is no separate event id — so claiming it on
 * a primary key is what makes a repeat delivery a no-op.
 *
 * @license MIT
 */
declare(strict_types=1);

namespace Receivify\Payments\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\DuplicateException;
use Psr\Log\LoggerInterface;

class EventGuard
{
    private const TABLE = 'receivify_webhook_event';

    /** @var ResourceConnection */
    private $resource;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(ResourceConnection $resource, LoggerInterface $logger)
    {
        $this->resource = $resource;
        $this->logger = $logger;
    }

    /**
     * Claim an event. Returns true exactly once per `(event, sequence)`, and
     * false when it has already been claimed (including by a concurrent
     * delivery that won the primary-key race).
     *
     * Any OTHER database failure (table missing, connection lost) is thrown,
     * not reported as a duplicate: the caller answers 500 so Receivify retries,
     * instead of acknowledging an event that was never applied.
     *
     * @throws \Exception on a database error other than a duplicate key
     */
    public function claim(string $eventKey, ?string $incrementId): bool
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);

        $existing = $connection->fetchOne(
            $connection->select()->from($table, 'event_key')->where('event_key = ?', $eventKey)
        );
        if ($existing) {
            return false;
        }

        try {
            $connection->insert($table, [
                'event_key' => $eventKey,
                'increment_id' => $incrementId,
            ]);
        } catch (DuplicateException $e) {
            return false;   // a concurrent delivery claimed it between the read and the insert
        }
        return true;
    }

    /**
     * Give a claim back after the event failed to apply, so the retry Receivify
     * sends for the 500 can claim and apply it again.
     */
    public function release(string $eventKey): void
    {
        try {
            $connection = $this->resource->getConnection();
            $connection->delete($this->resource->getTableName(self::TABLE), ['event_key = ?' => $eventKey]);
        } catch (\Exception $e) {
            $this->logger->critical('Receivify: could not release ' . $eventKey . ' — its retry will be treated as a duplicate: ' . $e->getMessage());
        }
    }
}
