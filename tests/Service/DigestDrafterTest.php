<?php

namespace Base\Newsletter\Tests\Service;

use Base\Newsletter\Digest\DigestSourceInterface;
use Base\Newsletter\Enum\CampaignState;
use Base\Newsletter\Service\DigestDrafter;
use PHPUnit\Framework\TestCase;

/** The digest drafted from stub sources: a DRAFT letter, a part per source, its entries in date order. */
final class DigestDrafterTest extends TestCase
{
    private static function source(string $name, array $entries, ?array &$asked = null): DigestSourceInterface
    {
        return new class($name, $entries, $asked) implements DigestSourceInterface {
            public function __construct(private string $name, private array $entries, private ?array &$asked)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function digest(\DateTimeImmutable $from, \DateTimeImmutable $to, string $locale): array
            {
                $this->asked = [$from, $to, $locale];

                return $this->entries;
            }
        };
    }

    public function testTheComingMonthRunsFromItsFirstDayToTheFirstOfTheNext(): void
    {
        [$from, $to] = DigestDrafter::nextMonth(new \DateTimeImmutable('2026-10-20 15:30'));

        self::assertSame('2026-11-01 00:00', $from->format('Y-m-d H:i'));
        self::assertSame('2026-12-01 00:00', $to->format('Y-m-d H:i'));

        [$from, $to] = DigestDrafter::nextMonth(new \DateTimeImmutable('2026-12-31'));
        self::assertSame('2027-01-01', $from->format('Y-m-d'));
        self::assertSame('2027-02-01', $to->format('Y-m-d'));
    }

    public function testEachSourceIsAskedForThePeriodInTheLettersLanguage(): void
    {
        $from = new \DateTimeImmutable('2026-11-01');
        $to = new \DateTimeImmutable('2026-12-01');
        $drafter = new DigestDrafter([self::source('Concerts', [], $asked)]);

        $drafter->draft($from, $to, 'de');
        self::assertSame([$from, $to, 'de'], $asked);

        // Everyone: the site's language.
        $drafter->draft($from, $to, null, 'fr');
        self::assertSame('fr', $asked[2]);
    }

    public function testNothingToAnnounceIsNoDraft(): void
    {
        $drafter = new DigestDrafter([
            self::source('Concerts', []),
            self::source('Blog', [['title' => '  ', 'text' => 'No title: skipped.', 'url' => null, 'date' => null]]),
        ]);

        self::assertNull($drafter->draft(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-12-01')));
        self::assertNull((new DigestDrafter([]))->draft(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-12-01')));
    }

    public function testADraftLetterWithAPartPerSourceItsEntriesInDateOrder(): void
    {
        $drafter = new DigestDrafter([
            self::source('Concerts', [
                ['title' => 'Basel, Stadtcasino', 'text' => 'Ravel, Introduction et Allegro', 'url' => 'https://example.com/concerts/basel', 'date' => new \DateTimeImmutable('2026-11-21 19:30')],
                ['title' => 'Lyon, Auditorium', 'text' => 'Debussy, Danses', 'url' => null, 'date' => new \DateTimeImmutable('2026-11-07')],
            ]),
            self::source('Empty', []),
            self::source('Recordings', [
                ['title' => 'Harp Sonatas', 'text' => 'Out on the 14th.', 'url' => null, 'date' => null],
            ]),
        ]);

        $campaign = $drafter->draft(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-12-01'), 'en');

        self::assertNotNull($campaign);
        self::assertSame(CampaignState::DRAFT, $campaign->getState());
        self::assertNull($campaign->getId());
        self::assertSame('en', $campaign->getLocale());
        self::assertStringContainsString('November 2026', $campaign->getSubject());

        $body = $campaign->getBody();
        self::assertStringContainsString('CONCERTS', $body);
        self::assertStringContainsString('RECORDINGS', $body);
        self::assertStringNotContainsString('EMPTY', $body);
        self::assertLessThan(strpos($body, 'Basel'), strpos($body, 'Lyon'));
        self::assertLessThan(strpos($body, 'RECORDINGS'), strpos($body, 'CONCERTS'));
        self::assertStringContainsString("Ravel, Introduction et Allegro\nhttps://example.com/concerts/basel", $body);
        self::assertStringContainsString('Harp Sonatas', $body);
        // A paragraph per entry: the letter's template renders them one by one.
        self::assertContains("Harp Sonatas\nOut on the 14th.", $campaign->getParagraphs());
    }

    public function testTheSitesTitleReplacesTheDefaultSubject(): void
    {
        $drafter = new DigestDrafter([self::source('Concerts', [['title' => 'Basel', 'text' => '', 'url' => null, 'date' => null]])], null, 'Clara Weiss · {month}');

        $campaign = $drafter->draft(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-12-01'), null, 'en');

        self::assertStringStartsWith('Clara Weiss · ', $campaign->getSubject());
        self::assertStringContainsString('2026', $campaign->getSubject());
        self::assertNull($campaign->getLocale());
    }
}
