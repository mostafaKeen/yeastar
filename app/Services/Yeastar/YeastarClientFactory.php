<?php

namespace App\Services\Yeastar;

use App\Enums\YeastarTransportMode;
use App\Models\YeastarConnection;
use App\Services\Yeastar\Contracts\YeastarTransportInterface;
use App\Services\Yeastar\Transports\DirectHttpsTransport;
use App\Services\Yeastar\Transports\TunnelAgentTransport;

class YeastarClientFactory
{
    public function __construct(
        private readonly DirectHttpsTransport $directTransport,
        private readonly TunnelAgentTransport $tunnelTransport,
    ) {}

    public function for(YeastarConnection $connection): YeastarTransportInterface
    {
        return match ($connection->transport_mode) {
            YeastarTransportMode::TunnelAgent => $this->tunnelTransport,
            default => $this->directTransport,
        };
    }
}
