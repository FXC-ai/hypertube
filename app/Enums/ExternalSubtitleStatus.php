<?php

namespace App\Enums;

enum ExternalSubtitleStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
