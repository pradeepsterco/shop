<?php

namespace App\Helpdesk\Enums;

enum MessageAuthor: string
{
    case Requester = 'requester';
    case Staff = 'staff';

    /** Automatic notes such as status or assignment changes. */
    case System = 'system';
}
