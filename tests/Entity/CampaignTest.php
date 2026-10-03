<?php

namespace Base\Newsletter\Tests\Entity;

use Base\Newsletter\Entity\Campaign;
use Base\Newsletter\Enum\CampaignState;
use PHPUnit\Framework\TestCase;

/** A letter: a draft, on its way, sent - and only a draft goes. */
final class CampaignTest extends TestCase
{
    public function testANewLetterIsADraftForEveryone(): void
    {
        $campaign = new Campaign(' November ', "First.\r\n\r\nSecond.", '');

        self::assertSame('November', $campaign->getSubject());
        self::assertSame("First.\n\nSecond.", $campaign->getBody());
        self::assertNull($campaign->getLocale());
        self::assertSame(CampaignState::DRAFT, $campaign->getState());
        self::assertTrue($campaign->isDraft());
        self::assertSame(0, $campaign->getRecipients());
        self::assertSame(['First.', 'Second.'], $campaign->getParagraphs());
    }

    public function testItGoesThenItIsSentWithItsCount(): void
    {
        $campaign = (new Campaign('November', 'Text.', 'FR'))->markSending();
        self::assertSame('fr', $campaign->getLocale());
        self::assertSame(CampaignState::SENDING, $campaign->getState());
        self::assertFalse($campaign->isDraft());

        $campaign->markSent(42);
        self::assertTrue($campaign->isSent());
        self::assertSame(42, $campaign->getRecipients());
        self::assertNotNull($campaign->getSentAt());
    }

    public function testOnlyADraftGoes(): void
    {
        $campaign = (new Campaign('November', 'Text.'))->markSending();

        $this->expectException(\LogicException::class);
        $campaign->markSending();
    }
}
