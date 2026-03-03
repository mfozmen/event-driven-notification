<?php

namespace App\Enums;

enum Channel: string
{
    case SMS = 'sms';
    case EMAIL = 'email';
    case PUSH = 'push';
}
