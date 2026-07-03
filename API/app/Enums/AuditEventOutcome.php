<?php

namespace App\Enums;

enum AuditEventOutcome: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Denied = 'denied';
}
