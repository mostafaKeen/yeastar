<?php

namespace App\Enums;

enum YeastarTransportMode: string
{
    case DirectHttps = 'direct_https';
    case TunnelAgent = 'tunnel_agent';

    public function label(): string
    {
        return match ($this) {
            self::DirectHttps => 'Direct HTTPS (FQDN / RAS)',
            self::TunnelAgent => 'On-Premise Tunnel Agent',
        };
    }
}
