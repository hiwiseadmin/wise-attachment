<?php
declare(strict_types=1);

namespace wise\attachment\exception;

use wise\support\circuit\CircuitBreakerExceptionInterface;
use wise\support\exception\GeneralErrorCode;
use wise\support\exception\WiseException;

/**
 * 服务不可用异常（熔断触发）
 *
 * 当 wise-attachment 某存储驱动的断路器处于 OPEN 状态时抛出。
 * 由 AttachmentUploadTrait 的 attachmentUpload() 捕获，
 * 通过 JsonResponse::circuitResponse() 返回 HTTP 503。
 *
 * 错误码：GeneralErrorCode::SERVICE_UNAVAILABLE (151)
 * HTTP：503
 */
class ServiceUnavailableException extends WiseException implements CircuitBreakerExceptionInterface
{
    protected int $retryAfter;

    public function __construct(
        string $message = 'Storage service is temporarily unavailable (circuit breaker open)',
        int $retryAfter = 60,
        string $driver = '',
        ?\Throwable $previous = null
    ) {
        $this->retryAfter = $retryAfter;
        $msg = $driver ? "{$message} [{$driver}]" : $message;
        parent::__construct(
            GeneralErrorCode::SERVICE_UNAVAILABLE->value,
            $msg,
            503,
            $previous,
            ['retry_after' => $retryAfter, 'driver' => $driver],
        );
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
