<?php

namespace Tests\Support;

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use RuntimeException;

class FailingBroadcaster implements Broadcaster
{
    public function __construct(private readonly ?string $failType = null) {}

    public static function activate(?string $failType = null): void
    {
        Broadcast::extend('timeout', fn (): Broadcaster => new self($failType));
        config([
            'broadcasting.connections.timeout' => ['driver' => 'timeout'],
            'broadcasting.default' => 'timeout',
        ]);
    }

    public function auth($request)
    {
        return true;
    }

    public function validAuthenticationResponse($request, $result)
    {
        return $result;
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        if ($this->failType === null || ($payload['type'] ?? null) === $this->failType) {
            throw new RuntimeException('pusher timeout');
        }
    }
}
