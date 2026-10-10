<?php
/**
 * Receivify for Magento 2 — checkout configuration provider.
 *
 * @license MIT
 */
declare(strict_types=1);

namespace Receivify\Payments\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\UrlInterface;
use Receivify\Payments\Model\Config;

class ConfigProvider implements ConfigProviderInterface
{
    /** The payment method code. Referenced from di.xml and system.xml. */
    public const CODE = 'receivify_payments';

    /** @var Config */
    private $config;

    /** @var UrlInterface */
    private $urlBuilder;

    public function __construct(Config $config, UrlInterface $urlBuilder)
    {
        $this->config = $config;
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * Everything the checkout renderer needs. No secret material is ever placed
     * here — this payload is public to the browser.
     *
     * @return array
     */
    public function getConfig(): array
    {
        if (!$this->config->isActive()) {
            return [];
        }

        return [
            'payment' => [
                self::CODE => [
                    'title' => $this->config->getTitle(),
                    // Where the method renderer sends the browser once Magento
                    // has placed the order.
                    'redirectUrl' => $this->urlBuilder->getUrl('receivify/checkout/start', ['_secure' => true]),
                    'instructions' => __(
                        'You will be taken to Receivify to pay. Your order is confirmed once the payment '
                        . 'is confirmed on-chain — nothing ships before then.'
                    )->render(),
                ],
            ],
        ];
    }
}
