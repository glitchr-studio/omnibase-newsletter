<?php

namespace Base\Newsletter\Tests\Service;

use Base\Newsletter\Service\SignedTimestamp;
use PHPUnit\Framework\TestCase;

/** The form's opening time: read back only when we signed it. */
final class SignedTimestampTest extends TestCase
{
    public function testWhatWasSignedIsReadBack(): void
    {
        self::assertSame(1767225600, SignedTimestamp::verify(SignedTimestamp::sign(1767225600, 'secret'), 'secret'));
    }

    public function testAnotherSecretATamperedTimeOrNothingAreRefused(): void
    {
        $signed = SignedTimestamp::sign(1767225600, 'secret');

        self::assertNull(SignedTimestamp::verify($signed, 'another'));
        self::assertNull(SignedTimestamp::verify('1767000000'.substr($signed, 10), 'secret'));
        self::assertNull(SignedTimestamp::verify('1767225600', 'secret'));
        self::assertNull(SignedTimestamp::verify('', 'secret'));
        self::assertNull(SignedTimestamp::verify(null, 'secret'));
        self::assertNull(SignedTimestamp::verify('abc.def', 'secret'));
    }
}
