<?php

namespace Base\Newsletter\Service;

use Base\Newsletter\Digest\DigestSourceInterface;
use Base\Newsletter\Entity\Campaign;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The monthly digest, drafted: every source asked what it has between two
 * dates, each a part of the letter under its name, its entries in date
 * order - a title with its date, a line, a link. A Campaign in DRAFT comes
 * out, to be read, touched up and sent from the back office; nothing is
 * saved here (Command\DraftDigestCommand persists it), nothing is sent.
 * No source with anything to say: no draft.
 */
final class DigestDrafter
{
    /**
     * @param iterable<DigestSourceInterface> $sources
     */
    public function __construct(
        #[AutowireIterator('newsletter.digest_source')] private readonly iterable $sources = [],
        private readonly ?TranslatorInterface $translator = null,
        #[Autowire('%newsletter.digest.title%')] private readonly ?string $title = null,
    ) {
    }

    /** The coming month: from its first day to the first of the next (exclusive). @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    public static function nextMonth(?\DateTimeImmutable $today = null): array
    {
        $from = ($today ?? new \DateTimeImmutable('today'))->modify('first day of next month')->setTime(0, 0);

        return [$from, $from->modify('+1 month')];
    }

    /**
     * @param string|null $locale the language of the letter and of who receives it; null: everyone, in $fallbackLocale
     */
    public function draft(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $locale = null, string $fallbackLocale = 'en'): ?Campaign
    {
        $language = $locale ?? $fallbackLocale;
        $parts = [];
        foreach ($this->sources as $source) {
            $entries = array_values(array_filter($source->digest($from, $to, $language), fn (array $entry) => '' !== trim((string) ($entry['title'] ?? ''))));
            if (!$entries) {
                continue;
            }
            usort($entries, fn (array $a, array $b) => ($a['date'] ?? $to) <=> ($b['date'] ?? $to));
            $parts[] = $this->part($source->getName(), $entries, $language);
        }
        if (!$parts) {
            return null;
        }

        $month = $this->month($from, $language);
        $subject = $this->title
            ? strtr($this->title, ['{month}' => $month])
            : $this->trans('digest.subject', ['month' => $month], $language, 'Your dates in {month}');
        $intro = $this->trans('digest.intro', ['month' => $month], $language, 'What is coming in {month}.');

        return new Campaign($subject, $intro."\n\n".implode("\n\n", $parts), $locale);
    }

    /** One source's part: its name in capitals, then its entries, a blank line between two. */
    private function part(string $name, array $entries, string $locale): string
    {
        $lines = [mb_strtoupper($name)];
        foreach ($entries as $entry) {
            $date = $entry['date'] ?? null;
            $lines[] = implode("\n", array_filter([
                trim($entry['title']).($date ? ' — '.$this->day($date, $locale) : ''),
                trim((string) ($entry['text'] ?? '')),
                $entry['url'] ?? null,
            ]));
        }

        return implode("\n\n", $lines);
    }

    private function month(\DateTimeImmutable $date, string $locale): string
    {
        if (class_exists(\IntlDateFormatter::class)) {
            $month = (new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'LLLL yyyy'))->format($date);
            if (\is_string($month) && '' !== $month) {
                return mb_convert_case(mb_substr($month, 0, 1), \MB_CASE_TITLE).mb_substr($month, 1);
            }
        }

        return $date->format('F Y');
    }

    private function day(\DateTimeImmutable $date, string $locale): string
    {
        if (class_exists(\IntlDateFormatter::class)) {
            $hasTime = '00:00' !== $date->format('H:i');
            $day = (new \IntlDateFormatter($locale, \IntlDateFormatter::FULL, $hasTime ? \IntlDateFormatter::SHORT : \IntlDateFormatter::NONE))->format($date);
            if (\is_string($day) && '' !== $day) {
                return $day;
            }
        }

        return $date->format('00:00' === $date->format('H:i') ? 'l j F Y' : 'l j F Y, H:i');
    }

    /** The bundle's words when a translator is there and knows them, the English ones otherwise. */
    private function trans(string $key, array $parameters, string $locale, string $english): string
    {
        $text = $this->translator?->trans($key, $parameters, 'newsletter', $locale);
        if (null !== $text && $text !== $key) {
            return $text;
        }

        return strtr($english, array_combine(array_map(fn ($k) => '{'.$k.'}', array_keys($parameters)), array_values($parameters)));
    }
}
