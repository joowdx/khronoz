<?php

namespace App\Enums;

enum RenditionStatus: string
{
    case Unstored = 'unstored';
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
