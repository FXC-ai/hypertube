<?php

namespace App\Enums;

enum ConversionStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Converting = 'converting';
    case Playable = 'playable';
    case Converted = 'converted';
    case Failed = 'failed';
}
