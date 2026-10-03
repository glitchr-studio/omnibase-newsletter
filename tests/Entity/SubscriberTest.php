<?php

namespace Base\Newsletter\Tests\Entity;

use Base\Newsletter\Entity\Campaign;
use Base\Newsletter\Entity\Subscriber;
use PHPUnit\Framework\TestCase;

/** An address on the list: pending, confirmed, gone, back. */
final class SubscriberTest extends TestCase
{
    public function testANewAddressIsLowercasedAndWaits(): void
    {
        $subscriber = new Subscriber('  Clara.Weiss@Example.COM ', 'DE', 'footer', '203.0.113.7');

        self::assertSame('clara.weiss@example.com', $subscriber->getEmail());
        self::assertSame('de', $subscriber->getLocale());
        self::assertSame('footer', $subscriber->getSource());
        self::assertSame('203.0.113.7', $subscriber->getIp());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $subscriber->getToken());
        self::assertTrue($subscriber->isPending());
        self::assertFalse($subscriber->isConfirmed());
        self::assertFalse($subscriber->isActive());
        self::assertSame('clara.weiss@example.com', Subscriber::normalize(' CLARA.WEISS@example.com'));
    }

    public function testTwoAddressesNeverShareAToken(): void
    {
        self::assertNotSame((new Subscriber('a@example.com'))->getToken(), (new Subscriber('a@example.com'))->getToken());
    }

    public function testAnEmptySourceIsTheSite(): void
    {
        self::assertSame('site', (new Subscriber('a@example.com', 'en', ' '))->getSource());
        self::assertSame(40, mb_strlen((new Subscriber('a@example.com', 'en', str_repeat('x', 60)))->getSource()));
    }

    public function testConfirmingPutsItOnTheListOnceTheFirstDateKept(): void
    {
        $subscriber = new Subscriber('a@example.com');
        $subscriber->confirm();
        $first = $subscriber->getConfirmedAt();

        self::assertTrue($subscriber->isConfirmed());
        self::assertTrue($subscriber->isActive());
        self::assertFalse($subscriber->isPending());
        self::assertSame($first, $subscriber->confirm()->getConfirmedAt());
    }

    public function testLeavingKeepsTheRowAndTheHistory(): void
    {
        $subscriber = (new Subscriber('a@example.com'))->confirm()->unsubscribe();
        $left = $subscriber->getUnsubscribedAt();

        self::assertTrue($subscriber->isUnsubscribed());
        self::assertTrue($subscriber->isConfirmed());
        self::assertFalse($subscriber->isActive());
        self::assertFalse($subscriber->isPending());
        self::assertNotNull($subscriber->getConfirmedAt());
        self::assertSame($left, $subscriber->unsubscribe()->getUnsubscribedAt());
    }

    public function testAnOldConfirmLinkDoesNotBringBackAnAddressThatLeft(): void
    {
        $subscriber = (new Subscriber('a@example.com'))->unsubscribe()->confirm();

        self::assertFalse($subscriber->isConfirmed());
        self::assertFalse($subscriber->isActive());
    }

    public function testComingBackWithDoubleOptInIsANewConfirmationUnderANewToken(): void
    {
        $subscriber = (new Subscriber('a@example.com'))->confirm()->unsubscribe();
        $token = $subscriber->getToken();
        $subscriber->resubscribe();

        self::assertFalse($subscriber->isUnsubscribed());
        self::assertFalse($subscriber->isConfirmed());
        self::assertTrue($subscriber->isPending());
        self::assertNotSame($token, $subscriber->getToken());
        self::assertTrue($subscriber->confirm()->isActive());
    }

    public function testComingBackWithoutDoubleOptInIsOnTheListAtOnce(): void
    {
        $subscriber = (new Subscriber('a@example.com'))->unsubscribe()->resubscribe(false);

        self::assertTrue($subscriber->isActive());
    }

    public function testResubscribingAnAddressStillOnTheListChangesNothing(): void
    {
        $subscriber = (new Subscriber('a@example.com'))->confirm();
        $token = $subscriber->getToken();
        $confirmed = $subscriber->getConfirmedAt();
        $subscriber->resubscribe();

        self::assertSame($token, $subscriber->getToken());
        self::assertSame($confirmed, $subscriber->getConfirmedAt());

        $pending = new Subscriber('b@example.com');
        self::assertTrue($pending->resubscribe()->isPending());
    }

    public function testItRemembersTheLastLetterItWasSent(): void
    {
        $campaign = new Campaign('November', 'Text.');
        (new \ReflectionProperty(Campaign::class, 'id'))->setValue($campaign, 12);
        $other = new Campaign('December', 'Text.');
        (new \ReflectionProperty(Campaign::class, 'id'))->setValue($other, 13);
        $subscriber = new Subscriber('a@example.com');

        self::assertFalse($subscriber->wasSent($campaign));
        $subscriber->sent($campaign);
        self::assertTrue($subscriber->wasSent($campaign));
        self::assertFalse($subscriber->wasSent($other));
        self::assertSame(12, $subscriber->getLastCampaign());
        // A letter not saved yet was sent to no one.
        self::assertFalse($subscriber->wasSent(new Campaign('Draft', 'Text.')));
    }
}
