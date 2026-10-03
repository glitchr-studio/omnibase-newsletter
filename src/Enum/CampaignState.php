<?php

namespace Base\Newsletter\Enum;

/**
 * Where a letter is: being written, on its way (the message dispatched, the
 * mails going out), sent. A string enum (not omnibase's EnumType) so the
 * column reads plainly in the database and in the admin's filters.
 */
enum CampaignState: string
{
    case DRAFT = 'draft';
    case SENDING = 'sending';
    case SENT = 'sent';
}
