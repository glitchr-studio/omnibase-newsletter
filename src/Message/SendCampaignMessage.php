<?php

namespace Base\Newsletter\Message;

/**
 * "Send this letter": dispatched by the back office's send button, handled
 * by MessageHandler\SendCampaignHandler. Routed to an async transport by the
 * host (messenger.yaml: Base\Newsletter\Message\SendCampaignMessage: async),
 * so the button answers at once whatever the list's length.
 */
final readonly class SendCampaignMessage
{
    public function __construct(public int $campaignId)
    {
    }
}
