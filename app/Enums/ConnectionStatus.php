<?php

namespace App\Enums;

enum ConnectionStatus: string
{
    case Pending = 'pending';
    case Connected = 'connected';
    case Degraded = 'degraded';
    case Disconnected = 'disconnected';
    case Revoked = 'revoked';
}
