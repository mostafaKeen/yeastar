<?php

namespace App\Enums;

enum CallDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
    case Internal = 'internal';
    case Callback = 'callback';
}
