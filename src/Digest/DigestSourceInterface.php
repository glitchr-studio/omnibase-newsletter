<?php

namespace Base\Newsletter\Digest;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Something the site announces in the monthly digest: the concerts of an
 * agenda, the posts of a blog, the new recordings. A service implementing
 * it is found by itself (tag newsletter.digest_source) and asked, for the
 * coming month, what it has.
 */
#[AutoconfigureTag('newsletter.digest_source')]
interface DigestSourceInterface
{
    /** The heading of its part of the letter: "Concerts", "On the blog". */
    public function getName(): string;

    /**
     * What happens (or was published) between $from and $to, in $locale.
     *
     * @return list<array{title: string, text: string, url: ?string, date: ?\DateTimeImmutable}>
     */
    public function digest(\DateTimeImmutable $from, \DateTimeImmutable $to, string $locale): array;
}
