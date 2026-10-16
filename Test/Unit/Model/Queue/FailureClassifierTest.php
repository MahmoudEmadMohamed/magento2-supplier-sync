<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Test\Unit\Model\Queue;

use Company\SupplierSync\Model\Queue\FailureClassifier;
use Magento\Framework\DB\Adapter\ConnectionException;
use Magento\Framework\DB\Adapter\DeadlockException;
use Magento\Framework\DB\Adapter\LockWaitException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FailureClassifierTest extends TestCase
{
    /**
     * Classification and error code of each failure type.
     *
     * @param \Throwable $failure
     * @param string $class
     * @param string $code
     * @return void
     */
    #[DataProvider('failures')]
    public function testClassify(\Throwable $failure, string $class, string $code): void
    {
        $classifier = new FailureClassifier();
        $this->assertSame($class, $classifier->classify($failure));
        $this->assertSame($code, $classifier->errorCode($failure));
    }

    /**
     * Failures.
     *
     * @return array<string, array{\Throwable, string, string}>
     */
    public static function failures(): array
    {
        $lost = new \PDOException('MySQL server has gone away');
        $lost->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];
        $syntax = new \PDOException('syntax error');
        $syntax->errorInfo = ['42000', 1064, 'syntax'];
        return [
            'deadlock' => [new DeadlockException('d'), FailureClassifier::RETRYABLE, 'db_deadlock'],
            'lock wait timeout' => [new LockWaitException('l'), FailureClassifier::RETRYABLE, 'db_lock_wait_timeout'],
            'connection exception' => [new ConnectionException('c'), FailureClassifier::RETRYABLE, 'db_connection'],
            'connection lost (2006)' => [$lost, FailureClassifier::RETRYABLE, 'db_connection'],
            'wrapped deadlock' => [
                new \RuntimeException('outer', 0, new DeadlockException('inner')),
                FailureClassifier::RETRYABLE,
                'transient_error',
            ],
            'logic error' => [new \LogicException('bug'), FailureClassifier::PERMANENT, 'processing_error'],
            'type error' => [new \TypeError('bug'), FailureClassifier::PERMANENT, 'processing_error'],
            'domain error' => [
                new LocalizedException(new Phrase('domain')),
                FailureClassifier::PERMANENT,
                'processing_error',
            ],
            'unknown runtime error (bounded retry)' => [
                new \RuntimeException('?'),
                FailureClassifier::RETRYABLE,
                'transient_error',
            ],
            'other PDO error (bounded retry)' => [$syntax, FailureClassifier::RETRYABLE, 'db_error'],
        ];
    }
}
