<?php
/**
 * A random token for THIS Magento install, created once and kept in the flag table.
 *
 * The invoice `reference` is `m2-<token>-<increment_id>`. Receivify de-duplicates
 * `reference` per account, not per shop, so a bare increment id collides when two
 * shops share one Receivify account, or a reinstalled shop restarts its order
 * numbers: Receivify would replay the other order's invoice or refuse with 409.
 *
 * The flag table is used rather than core_config_data so the token survives a
 * config import/export and never appears in the admin form. It must never change
 * once created, or pending orders' webhooks would look like another shop's.
 */
declare(strict_types=1);

namespace Receivify\Payments\Model;

use Magento\Framework\FlagManager;

class StoreToken
{
    private const FLAG = 'receivify_store_token';
    private const PLATFORM = 'm2';

    /** @var FlagManager */
    private $flagManager;

    /** @var string|null */
    private $token;

    public function __construct(FlagManager $flagManager)
    {
        $this->flagManager = $flagManager;
    }

    public function get(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }
        $this->loadSdk();
        $token = (string) $this->flagManager->getFlagData(self::FLAG);
        if (!preg_match('/^[a-f0-9]{10}$/', $token)) {
            $token = \Receivify::newStoreToken();
            $this->flagManager->saveFlag(self::FLAG, $token);
        }
        return $this->token = $token;
    }

    /** `m2-<token>-<increment_id>`, used as both `reference` and `Idempotency-Key`. */
    public function reference(string $incrementId): string
    {
        $this->loadSdk();
        return \Receivify::orderReference(self::PLATFORM, $this->get(), $incrementId);
    }

    /** The increment id in a webhook `reference`, or null when it is another shop's. */
    public function incrementIdFromReference(?string $reference): ?string
    {
        $this->loadSdk();
        return \Receivify::parseOrderReference($reference, self::PLATFORM, $this->get());
    }

    private function loadSdk(): void
    {
        if (!class_exists('Receivify')) {
            require_once __DIR__ . '/../Lib/Receivify.php';
        }
    }
}
