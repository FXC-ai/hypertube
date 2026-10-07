<?php

namespace App\Enums;

enum DownloadStatus: string
{
    case Pending = 'pending';
    case Downloading = 'downloading';
    case Completed = 'completed';
    case Failed = 'failed';
}
