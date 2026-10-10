<?php
/**
 * Receivify for Magento 2 — the settlement-coin dropdown.
 *
 * A static list on purpose: the admin screen must render without calling out to
 * the API. `GET /api/v1/assets` is the live catalogue if you want to check what
 * is actually enabled on your deployment.
 *
 * @license MIT
 */
declare(strict_types=1);

namespace Receivify\Payments\Model\Adminhtml\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Asset implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        $assets = [
            'btc' => 'Bitcoin (BTC)',
            'ltc' => 'Litecoin (LTC)',
            'doge' => 'Dogecoin (DOGE)',
            'bch' => 'Bitcoin Cash (BCH)',
            'xmr' => 'Monero (XMR)',
            'eth' => 'Ethereum (ETH)',
            'usdt' => 'Tether (USDT)',
            'usdc' => 'USD Coin (USDC)',
        ];

        $options = [];
        foreach ($assets as $value => $label) {
            $options[] = ['value' => $value, 'label' => __($label)];
        }
        return $options;
    }
}
