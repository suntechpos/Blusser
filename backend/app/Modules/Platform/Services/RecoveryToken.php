<?php

namespace App\Modules\Platform\Services;

use JsonSerializable;
use LogicException;

final class RecoveryToken implements JsonSerializable
{
    public function __construct(public readonly string $requestId, #[\SensitiveParameter] private ?string $value) {}

    public function revealOnce(): string
    {
        $value = $this->value ?? throw new LogicException('Recovery token already delivered.');
        $this->value = null;

        return $value;
    }

    public function jsonSerialize(): array
    {
        return ['request_id' => $this->requestId];
    }

    public function __debugInfo(): array
    {
        return ['request_id' => $this->requestId, 'token' => '[REDACTED]'];
    }
}
