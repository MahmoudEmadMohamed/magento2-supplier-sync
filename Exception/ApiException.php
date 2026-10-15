<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Exception;

use Magento\Framework\Phrase;
use Magento\Framework\Webapi\Exception as WebapiException;

/**
 * REST error with an explicit HTTP code and a machine-readable error_code.
 *
 * Rendered by Magento as {"message": "<template>", "parameters": {"error_code": ..., ...}}: the message keeps its
 * %placeholders and the values travel in parameters, following Magento's own error format.
 */
class ApiException extends WebapiException
{
    public const INVALID_IDEMPOTENCY_KEY = 'invalid_idempotency_key';
    public const INVALID_QTY = 'invalid_qty';
    public const INVALID_PRICE = 'invalid_price';
    public const INVALID_CURRENCY = 'invalid_currency';
    public const INVALID_SKU = 'invalid_sku';
    public const CURRENCY_MISMATCH = 'currency_mismatch';
    public const UNKNOWN_SKU = 'unknown_sku';
    public const UNSUPPORTED_PRODUCT_TYPE = 'unsupported_product_type';
    public const PRODUCT_NOT_IN_WEBSITE = 'product_not_in_website';
    public const UNASSIGNED_SKU = 'unassigned_sku';
    public const UNKNOWN_WEBSITE = 'unknown_website';
    public const UNKNOWN_SUPPLIER = 'unknown_supplier';
    public const SUPPLIER_DISABLED = 'supplier_disabled';
    public const WEBSITE_DISABLED = 'website_disabled';
    public const IDEMPOTENCY_CONFLICT = 'idempotency_conflict';
    public const ASSIGNMENT_MISMATCH = 'assignment_mismatch';
    public const NOT_FOUND = 'not_found';
    public const INTERNAL_ERROR = 'internal_error';

    /**
     * @var string
     */
    private string $errorCode;

    /**
     * Create an error. $params fill the %placeholders of $message and are returned as parameters.
     *
     * @param string $errorCode
     * @param string $message
     * @param array<string,string|int> $params
     * @param int $httpCode
     */
    public function __construct(
        string $errorCode,
        string $message,
        array $params = [],
        int $httpCode = self::HTTP_BAD_REQUEST
    ) {
        $this->errorCode = $errorCode;
        parent::__construct(new Phrase($message), 0, $httpCode, ['error_code' => $errorCode] + $params);
    }

    /**
     * Get the machine-readable error code.
     *
     * @return string
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
