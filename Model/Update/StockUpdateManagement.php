<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Update;

use Company\SupplierSync\Api\Data\UpdateReceiptInterface;
use Company\SupplierSync\Api\StockUpdateManagementInterface;
use Company\SupplierSync\Api\SupplierRepositoryInterface;
use Company\SupplierSync\Exception\ApiException;
use Company\SupplierSync\Model\Data\UpdateReceipt;
use Company\SupplierSync\Model\Db\TransactionRunner;
use Company\SupplierSync\Model\Queue\UpdatePublisher;
use Company\SupplierSync\Model\UpdateStatus;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * Stock update intake: validate, de-duplicate, persist with an outbox row, publish after commit.
 */
class StockUpdateManagement implements StockUpdateManagementInterface
{
    /**
     * @param PayloadNormalizer $normalizer
     * @param SupplierRepositoryInterface $supplierRepository
     * @param AdmissionValidator $admissionValidator
     * @param UpdateStore $updateStore
     * @param TransactionRunner $transactionRunner
     * @param ResourceConnection $resource
     * @param UpdatePublisher $publisher
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly PayloadNormalizer $normalizer,
        private readonly SupplierRepositoryInterface $supplierRepository,
        private readonly AdmissionValidator $admissionValidator,
        private readonly UpdateStore $updateStore,
        private readonly TransactionRunner $transactionRunner,
        private readonly ResourceConnection $resource,
        private readonly UpdatePublisher $publisher,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function submit(
        string $supplierCode,
        string $idempotencyKey,
        string $sku,
        string $quantity,
        string $unitPrice,
        string $currency,
        string $websiteCode
    ): UpdateReceiptInterface {
        try {
            return $this->accept(
                $this->normalizer->normalize(
                    $supplierCode,
                    $idempotencyKey,
                    $sku,
                    $quantity,
                    $unitPrice,
                    $currency,
                    $websiteCode
                )
            );
        } catch (ApiException $e) {
            $this->logger->info('Stock update refused.', [
                'error_code' => $e->getErrorCode(),
                'http_code' => $e->getHttpCode(),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            $reference = bin2hex(random_bytes(6));
            $this->logger->error('Stock update intake failed.', [
                'reference' => $reference,
                'exception' => get_class($e),
                'code' => $e->getCode(),
            ]);
            throw new ApiException(
                ApiException::INTERNAL_ERROR,
                'The update could not be processed. Reference: %reference',
                ['reference' => $reference],
                500
            );
        }
    }

    /**
     * Replay check first, then admission rules for a new key, then the transactional insert.
     *
     * @param NormalizedPayload $payload
     * @return UpdateReceiptInterface
     * @throws ApiException
     */
    private function accept(NormalizedPayload $payload): UpdateReceiptInterface
    {
        try {
            $supplier = $this->supplierRepository->getByCode($payload->supplierCode);
        } catch (NoSuchEntityException) {
            throw new ApiException(ApiException::UNKNOWN_SUPPLIER, 'Supplier %supplier does not exist.', [
                'supplier' => $payload->supplierCode,
            ]);
        }
        $supplierId = (int)$supplier->getSupplierId();
        $connection = $this->resource->getConnection();

        $existing = $this->updateStore->findByKey($connection, $supplierId, $payload->idempotencyKey);
        if ($existing !== null) {
            return $this->replay($existing, $payload);
        }

        $websiteId = $this->admissionValidator->validate($payload, $supplier);
        try {
            $updateId = $this->transactionRunner->run(
                fn (AdapterInterface $c): int =>
                    $this->updateStore->insertAccepted($c, $supplierId, $websiteId, $payload)
            );
        } catch (DuplicateException $e) {
            // A concurrent request accepted the same key first. The runner has rolled back, so this read is
            // a fresh snapshot that sees the winner's committed row.
            $existing = $this->updateStore->findByKey($connection, $supplierId, $payload->idempotencyKey);
            if ($existing === null) {
                throw $e;
            }
            return $this->replay($existing, $payload);
        }
        $this->logger->info('Stock update accepted.', ['update_id' => $updateId, 'supplier_id' => $supplierId]);
        $this->publisher->publishAccepted($updateId);
        return new UpdateReceipt($updateId, UpdateStatus::QUEUED, false);
    }

    /**
     * Return the stored receipt for an identical payload, or 409 for a different one under the same key.
     *
     * @param array<string,mixed> $existing
     * @param NormalizedPayload $payload
     * @return UpdateReceiptInterface
     * @throws ApiException
     */
    private function replay(array $existing, NormalizedPayload $payload): UpdateReceiptInterface
    {
        if (!hash_equals((string)$existing['payload_hash'], $payload->hash)) {
            throw new ApiException(
                ApiException::IDEMPOTENCY_CONFLICT,
                'Idempotency key %key was already used for a different payload.',
                ['key' => $payload->idempotencyKey],
                409
            );
        }
        $this->logger->info('Stock update replayed.', ['update_id' => (int)$existing['update_id']]);
        return new UpdateReceipt((int)$existing['update_id'], (string)$existing['status'], true);
    }
}
