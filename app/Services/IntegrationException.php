<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class IntegrationException extends RuntimeException
{
    private bool $logged = false;

    public function __construct(
        string $message,
        public readonly string $operation,
        public readonly ?int $statusCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function logFailure(?string $serviceId): void
    {
        if ($this->logged) {
            return;
        }

        Log::error('CRM integration failed.', [
            'integration' => 'CRM',
            'service_id' => $serviceId,
            'operation' => $this->operation,
            'status_code' => $this->statusCode,
            'message' => $this->getMessage(),
            'request_id' => request()->attributes->get('request_id'),
        ]);
        $this->logged = true;
    }
}
