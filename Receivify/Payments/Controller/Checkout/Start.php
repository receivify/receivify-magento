<?php
/**
 * Receivify for Magento 2 — create the invoice and redirect to hosted checkout.
 *
 *   /receivify/checkout/start
 *
 * Magento has already placed the order by the time we get here (the checkout
 * method renderer redirects after placeOrder), so the order increment id exists
 * and becomes the invoice `reference` — that is how the webhook finds its way
 * back to this order.
 *
 * @license MIT
 */
declare(strict_types=1);

namespace Receivify\Payments\Controller\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Receivify\Payments\Model\Config;
use Receivify\Payments\Model\StoreToken;
use Receivify\Payments\Model\Ui\ConfigProvider;

class Start extends Action implements HttpGetActionInterface
{
    /** @var CheckoutSession */
    private $checkoutSession;

    /** @var Config */
    private $config;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var LoggerInterface */
    private $logger;

    /** @var StoreToken */
    private $storeToken;

    public function __construct(
        Context $context,
        CheckoutSession $checkoutSession,
        Config $config,
        OrderRepositoryInterface $orderRepository,
        LoggerInterface $logger,
        StoreToken $storeToken
    ) {
        parent::__construct($context);
        $this->checkoutSession = $checkoutSession;
        $this->config = $config;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
        $this->storeToken = $storeToken;
    }

    /**
     * @return ResultInterface|\Magento\Framework\App\ResponseInterface
     */
    public function execute()
    {
        /** @var Order|null $order */
        $order = $this->checkoutSession->getLastRealOrder();

        if (!$order || !$order->getId()) {
            $this->messageManager->addErrorMessage(__('We could not find your order. Please try again.'));
            return $this->_redirect('checkout/cart');
        }

        if ($order->getPayment() === null
            || $order->getPayment()->getMethod() !== ConfigProvider::CODE) {
            $this->messageManager->addErrorMessage(__('That order is not paid with Receivify.'));
            return $this->_redirect('checkout/cart');
        }

        try {
            // `m2-<store token>-<increment id>`: unique to this shop even when
            // several shops share one Receivify account. See Model/StoreToken.
            $reference = $this->storeToken->reference((string) $order->getIncrementId());
            $invoice = $this->config->createClient((int) $order->getStoreId())->createInvoice(
                [
                    // The grand total as a STRING at the currency's precision.
                    // Money never round-trips through a float.
                    'amount_fiat' => number_format((float) $order->getGrandTotal(), 2, '.', ''),
                    'currency' => (string) $order->getOrderCurrencyCode(),
                    'asset' => $this->config->getAsset((int) $order->getStoreId()),
                    'reference' => $reference,
                    'callback_url' => $this->_url->getUrl('receivify/webhook', ['_secure' => true]),
                    'metadata' => [
                        'increment_id' => $order->getIncrementId(),
                        'entity_id' => $order->getId(),
                        'store_id' => $order->getStoreId(),
                        'source' => 'magento2',
                    ],
                ],
                // Retrying this create can never mint a second invoice: the key
                // AND the `reference` both de-duplicate server-side.
                ['idempotency_key' => $reference]
            );

            if (empty($invoice['checkout_url'])) {
                throw new \RuntimeException('Receivify returned no checkout_url.');
            }

            $order->addCommentToStatusHistory(sprintf(
                'Receivify invoice %s created. Awaiting %s base units at %s.',
                isset($invoice['id']) ? $invoice['id'] : '?',
                isset($invoice['amount_expected']) ? $invoice['amount_expected'] : '?',
                isset($invoice['address']) ? $invoice['address'] : '?'
            ));
            $this->orderRepository->save($order);

            // Straight to the hosted checkout. Not a Magento result object:
            // the target is an external URL.
            $this->getResponse()->setRedirect($invoice['checkout_url']);
            return $this->getResponse();
        } catch (\Exception $e) {
            $detail = ($e instanceof \ReceivifyApiError)
                ? '[' . $e->getErrorCode() . '] ' . $e->getDetail()
                : $e->getMessage();

            $this->logger->error('Receivify createInvoice failed for order ' . $order->getIncrementId() . ': ' . $detail);

            // Put the cart back so the customer can choose another method.
            $this->checkoutSession->restoreQuote();
            $this->messageManager->addErrorMessage(
                __('The crypto payment could not be started. Please try again or choose another payment method.')
            );
            return $this->_redirect('checkout/cart');
        }
    }
}
