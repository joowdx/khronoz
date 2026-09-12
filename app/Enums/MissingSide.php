<?php

namespace App\Enums;

enum MissingSide: string
{
    /** Treat the slot as not worked; minutes become tardy or undertime. */
    case Void = 'void';

    /** Credit the slot to its expected time and flag the punch for review. */
    case Assume = 'assume';
}
