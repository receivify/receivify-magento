<?php
/**
 * Receivify for Magento 2 — typed access to the method's configuration, and the
 * one place that builds a configured SDK client.
 *
 * The API key and both webhook secrets are stored with
 * Magento\Config\Model\Config\Backend\Encrypted, so they must be decrypted on
 * the way out. They are never logged and never leave the server.
 *
 * @license MIT
 */
declare(strict_types=1);

namespace Receivify\Payments\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Receivify\Payments\Model\Ui\ConfigProvider;

class Config
{
    private const PATH = 'payment/' . ConfigProvider::CODE . '/';

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    /** @var EncryptorInterface */
    private $encryptor;

    public function __construct(ScopeConfigInterface $scopeConfig, EncryptorInterface $encryptor)
    {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
    }

    public function isActive($storeId = null): bool
    {
        return (bool) $this->value('active', $storeId);
    }

    public function getTitle($storeId = null): string
    {
        return (string) ($this->value('title', $storeId) ?: 'Bitcoin & crypto (Receivify)');
    }

    public function getApiUrl($storeId = null): string
    {
        return rtrim((string) $this->value('api_url', $storeId), '/');
    }

    public function getApiKey($storeId = null): string
    {
        return $this->decrypt((string) $this->value('api_key', $storeId));
    }

    public function getAsset($storeId = null): string
    {
        return (string) ($this->value('asset', $storeId) ?: 'btc');
    }

    public function getNewOrderStatus($storeId = null): string
    {
        return (string) ($this->value('order_status', $storeId) ?: 'pending_payment');
    }

    /**
     * Current secret first, previous second.
     *
     * A rotation keeps the previous secret acceptable until its grace window
     * closes (24h by default) while Receivify keeps SIGNING with the current
     * one, so during a rotation two secrets are legitimately valid and the
     * receiver must try both.
     *
     * @return string[]
     */
    public function getWebhookSecrets($storeId = null): array
    {
        return array_values(array_filter([
            $this->decrypt((string) $this->value('webhook_secret', $storeId)),
            $this->decrypt((string) $this->value('webhook_secret_previous', $storeId)),
        ]));
    }

    /**
     * A configured SDK client. Every caller goes through here so there is
     * exactly one place that knows how credentials are stored.
     *
     * @return \Receivify
     */
    public function createClient($storeId = null)
    {
        if (!class_exists('Receivify')) {
            require_once __DIR__ . '/../Lib/Receivify.php';
        }

        return new \Receivify(
            $this->getApiUrl($storeId),
            $this->getApiKey($storeId),
            [
                'webhook_secrets' => $this->getWebhookSecrets($storeId),
                'timeout' => 30,
                'user_agent' => 'magento2',
            ]
        );
    }

    /**
     * @param string $field
     * @param int|string|null $storeId
     * @return mixed
     */
    private function value(string $field, $storeId)
    {
        return $this->scopeConfig->getValue(self::PATH . $field, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function decrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }
        try {
            return (string) $this->encryptor->decrypt($value);
        } catch (\Exception $e) {
            // A value saved before the encryption backend was applied comes back
            // as plain text; using it verbatim is better than failing closed on
            // every webhook.
            return $value;
        }
    }
}
