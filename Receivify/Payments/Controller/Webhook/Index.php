<?php
/**
 * Receivify for Magento 2 — the signed webhook receiver.
 *
 *   POST /receivify/webhook
 *
 * Order of operations, and none of it is negotiable:
 *   1. read the RAW body — never re-encoded JSON, or the HMAC cannot match;
 *   2. verify the signature against the CURRENT and PREVIOUS secrets, in
 *      constant time, with a 300-second replay window;
 *   3. claim (event, sequence), because delivery is at-least-once and a manual
 *      replay re-sends the identical bytes;
 *   4. only then touch the order.
 *
 * CSRF form-key validation is disabled for this route, as it must be for any
 * machine-to-machine callback. The HMAC is the authentication.
 *
 * @license MIT
 */
declare(strict_types=1);

namespace Receivify\Payments\Controller\Webhook;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Psr\Log\LoggerInterface;
use Receivify\Payments\Model\Config;
use Receivify\Payments\Model\EventGuard;
use Receivify\Payments\Model\OrderUpdater;
use Receivify\Payments\Model\StoreToken;

class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /** @var RequestInterface */
    private $request;

    /** @var ResultFactory */
    private $resultFactory;

    /** @var Config */
    private $config;

    /** @var EventGuard */
    private $eventGuard;

    /** @var OrderUpdater */
    private $orderUpdater;

    /** @var LoggerInterface */
    private $logger;

    /** @var StoreToken */
    private $storeToken;

    public function __construct(
        RequestInterface $request,
        ResultFactory $resultFactory,
        Config $config,
        EventGuard $eventGuard,
        OrderUpdater $orderUpdater,
        LoggerInterface $logger,
        StoreToken $storeToken
    ) {
        $this->request = $request;
        $this->resultFactory = $resultFactory;
        $this->config = $config;
        $this->eventGuard = $eventGuard;
        $this->orderUpdater = $orderUpdater;
        $this->logger = $logger;
        $this->storeToken = $storeToken;
    }

    /** No form key on a machine callback. */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /** The HMAC signature is the authentication for this route. */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * @return ResultInterface
     */
    public function execute()
    {
        // 1. THE RAW BYTES, exactly as they arrived.
        $raw = (string) $this->request->getContent();
        if ($raw === '') {
            $raw = (string) file_get_contents('php://input');
        }
        $header = (string) $this->request->getHeader('X-Receivify-Signature');

        // 2. Verify before trusting anything at all.
        $event = $this->config->createClient()->readWebhook($raw, $header);
        if ($event === null) {
            $this->logger->warning('Receivify: webhook rejected — signature verification failed.');
            return $this->respond(401, 'bad signature');
        }

        $name = \Receivify::eventName($event);
        $data = \Receivify::webhookData($event);
        $dedupeKey = \Receivify::dedupeKey($event);           // "invoice.paid:42"
        // The reference is `m2-<store token>-<increment id>` (or a bare increment
        // id on invoices created before that). Another shop's invoice maps to
        // null and is acknowledged without touching any order here.
        $incrementId = $this->storeToken->incrementIdFromReference(
            isset($data['reference']) ? (string) $data['reference'] : null
        );
        if ($incrementId === null) {
            return $this->respond(200, 'not for this store');
        }
        $data['reference'] = $incrementId;   // OrderUpdater looks orders up by increment id

        // 3. Claim it. A repeat delivery is acknowledged and changes nothing.
        //    A database error is NOT a duplicate: answer 500 so Receivify retries.
        try {
            if (!$this->eventGuard->claim($dedupeKey, $incrementId)) {
                return $this->respond(200, 'duplicate');
            }
        } catch (\Exception $e) {
            $this->logger->critical('Receivify: could not record ' . $dedupeKey . ': ' . $e->getMessage());
            return $this->respond(500, 'retry');
        }

        try {
            $this->orderUpdater->apply($name, $data);
        } catch (\Exception $e) {
            // Give the claim back and answer 500, so Receivify's retry (with
            // backoff, for up to 24 hours) gets another go. Acknowledging here
            // would lose a paid event to a transient error such as a deadlock.
            // apply() is safe to repeat: markPaid() checks canInvoice() first.
            $this->logger->critical('Receivify: failed to apply ' . $dedupeKey . ', will retry: ' . $e->getMessage());
            $this->eventGuard->release($dedupeKey);
            return $this->respond(500, 'retry');
        }

        // 4. Any 2xx marks the delivery delivered; anything else is retried with
        //    exponential backoff for up to 24 hours.
        return $this->respond(200, 'ok');
    }

    private function respond(int $status, string $body): ResultInterface
    {
        /** @var Raw $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHttpResponseCode($status);
        $result->setHeader('Content-Type', 'text/plain');
        $result->setContents($body);
        return $result;
    }
}
