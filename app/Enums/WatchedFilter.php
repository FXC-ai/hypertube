<?php

namespace App\Enums;

enum WatchedFilter: string
{
    case All = 'all';
    case Watched = 'watched';
    case Unwatched = 'unwatched';
}
