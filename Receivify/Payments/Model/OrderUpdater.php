<?php
/**
 * Receivify for Magento 2 — turns a verified event into an order transition.
 *
 * The mapping is shared with every other Receivify cart plugin via
 * Receivify::classifyEvent(), so the platforms agree on what each event means.
 *
 * @license MIT
 */
declare(strict_types=1);

namespace Receivify\Payments\Model;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DB\Transaction;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderCommentSender;
use Magento\Sales\Model\Service\InvoiceService;
use Psr\Log\LoggerInterface;

class OrderUpdater
{
    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var SearchCriteriaBuilder */
    private $searchCriteriaBuilder;

    /** @var InvoiceService */
    private $invoiceService;

    /** @var TransactionFactory */
    private $transactionFactory;

    /** @var OrderCommentSender */
    private $commentSender;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        OrderCommentSender $commentSender,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->commentSender = $commentSender;
        $this->logger = $logger;
    }

    /**
     * @param string $eventName the raw `event` from the envelope
     * @param array  $data      the `data` payload
     */
    public function apply(string $eventName, array $data): void
    {
        $incrementId = isset($data['reference']) ? (string) $data['reference'] : '';
        if ($incrementId === '') {
            return;
        }

        $order = $this->loadByIncrementId($incrementId);
        if ($order === null) {
            $this->logger->warning('Receivify: webhook for unknown order ' . $incrementId);
            return;
        }

        $invoiceId = isset($data['id']) ? (string) $data['id'] : '';

        switch (\Receivify::classifyEvent($eventName)) {
            case 'paid':
                $this->markPaid($order, $eventName, $invoiceId);
                break;

            case 'confirming':
                // Seen on-chain but NOT final. Do not release anything.
                $this->comment($order, 'Receivify: payment detected, awaiting confirmations. Do not ship yet.');
                break;

            case 'underpaid':
            case 'underpaid_expired':
                $this->comment($order, sprintf(
                    'Receivify: under-payment — received %s of %s base units. Needs a human.',
                    isset($data['amount_received']) ? (string) $data['amount_received'] : '?',
                    isset($data['amount_expected']) ? (string) $data['amount_expected'] : '?'
                ));
                break;

            case 'expired':
            case 'cancelled':
                $this->cancel($order, 'Receivify: invoice ' . $eventName . ' without payment.');
                break;

            case 'failed':
                $this->comment($order, 'Receivify: settlement failed. Needs review.', Order::STATE_HOLDED);
                break;

            // 'test'   — POST /api/v1/webhooks/test. Acknowledge, change nothing.
            // 'ignore' — forward.* and any event name added after this module was
            //            written. Receivers MUST ignore names they do not know.
        }
    }

    private function markPaid(OrderInterface $order, string $eventName, string $invoiceId): void
    {
        /** @var Order $order */
        if ($order->getState() === Order::STATE_CANCELED || $order->getState() === Order::STATE_CLOSED) {
            $this->comment($order, 'Receivify: payment arrived for a ' . $order->getState() . ' order (' . $eventName . '). Needs a human.');
            return;
        }

        $payment = $order->getPayment();
        if ($payment !== null && $invoiceId !== '') {
            $payment->setLastTransId($invoiceId);
            $payment->setTransactionId($invoiceId);
            $payment->setIsTransactionClosed(true);
        }

        // Invoice the order so it reads as Paid in the admin. Offline capture:
        // the money moved on-chain, not through Magento.
        if ($order->canInvoice()) {
            try {
                $invoice = $this->invoiceService->prepareInvoice($order);
                $invoice->setRequestedCaptureCase(Order\Invoice::CAPTURE_OFFLINE);
                if ($invoiceId !== '') {
                    $invoice->setTransactionId($invoiceId);
                }
                $invoice->register();

                /** @var Transaction $transaction */
                $transaction = $this->transactionFactory->create();
                $transaction->addObject($invoice)->addObject($invoice->getOrder())->save();
            } catch (\Exception $e) {
                $this->logger->error('Receivify: could not invoice order ' . $order->getIncrementId() . ': ' . $e->getMessage());
            }
        }

        $order->setState(Order::STATE_PROCESSING);
        $order->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING));
        $this->comment($order, 'Receivify: payment confirmed on-chain (' . $eventName . '). Invoice ' . $invoiceId . '.', null, true);
    }

    private function cancel(OrderInterface $order, string $message): void
    {
        /** @var Order $order */
        if ($order->canCancel()) {
            $order->cancel();
        }
        $this->comment($order, $message);
    }

    /**
     * Add a status-history comment, optionally moving the order, and save.
     */
    private function comment(OrderInterface $order, string $message, ?string $state = null, bool $notify = false): void
    {
        /** @var Order $order */
        if ($state !== null) {
            $order->setState($state);
            $order->setStatus($order->getConfig()->getStateDefaultStatus($state));
        }

        // addCommentToStatusHistory(), not the deprecated addStatusHistoryComment().
        $history = $order->addCommentToStatusHistory($message, false, false);
        $history->setIsCustomerNotified($notify);

        try {
            $this->orderRepository->save($order);
            if ($notify) {
                $this->commentSender->send($order, true, $message);
            }
        } catch (\Exception $e) {
            $this->logger->error('Receivify: could not save order ' . $order->getIncrementId() . ': ' . $e->getMessage());
        }
    }

    private function loadByIncrementId(string $incrementId): ?OrderInterface
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $incrementId)
            ->setPageSize(1)
            ->create();

        $orders = $this->orderRepository->getList($criteria)->getItems();
        return $orders ? array_values($orders)[0] : null;
    }
}
