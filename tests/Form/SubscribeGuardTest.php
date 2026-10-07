<?php

namespace Base\Newsletter\Tests\Form;

use Base\Newsletter\Form\SubscribeType;
use Base\Service\FormGuard;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The sign-up form is guarded as glitchr/omnibase guards a form (option `guard`, action "newsletter"),
 * in place of its own `website` trap and `opened_at` time: a filled trap, a sign-up sent faster than
 * its delay and a missing captcha token are refused on the form; a sign-up made as a person makes it
 * goes through. Without a captcha (`challenge: false`), the trap and the time alone. Run by a host
 * application that loads the bundle (its kernel; its test captcha: omniguard's "fixed" gateway).
 */
final class SubscribeGuardTest extends KernelTestCase
{
    protected function setUp(): void
    {
        $_SERVER['KERNEL_CLASS'] ??= $_ENV['KERNEL_CLASS'] ?? 'App\\Kernel';
        if (!class_exists($_SERVER['KERNEL_CLASS'])) {
            self::markTestSkipped('Needs a host application (its kernel).');
        }
        if (!class_exists(FormGuard::class)) {
            self::markTestSkipped('Needs a glitchr/omnibase with the forms\' guard (before it, the form keeps its own trap).');
        }
        self::bootKernel();
        if (!static::getContainer()->hasParameter('newsletter.min_delay')) {
            self::markTestSkipped('The host application does not load omnibase/newsletter.');
        }
        static::getContainer()->get('request_stack')->push(Request::create('https://localhost/newsletter', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']));
    }

    /** @param array<string, mixed> $guard */
    private function form(array $guard = []): FormInterface
    {
        $options = ['csrf_protection' => false];
        if ($guard) {
            $options['guard'] = $guard + ['action' => 'newsletter'];
        }

        return static::getContainer()->get('form.factory')->create(SubscribeType::class, null, $options);
    }

    /** @param array<string, ?string> $overrides */
    private function send(FormInterface $form, array $overrides = []): FormInterface
    {
        $data = [
            'email' => 'camille@example.org',
            'source' => 'site',
            'guard_website' => '',
            'guard_opened' => static::getContainer()->get(FormGuard::class)->stamp(time() - 10),
        ];
        if ($form->has('guard_captcha')) {
            $data['guard_captcha'] = 'omniguard-fixed-token';
        }
        $form->submit(array_filter($overrides + $data, static fn ($value) => null !== $value));

        return $form;
    }

    /** @return list<string> */
    private function refusals(FormInterface $form): array
    {
        $found = [];
        foreach ($form->getErrors(true) as $error) {
            $found[] = \is_string($error->getCause()) ? $error->getCause() : (string) $error->getOrigin()?->getName();
        }

        return $found;
    }

    public function testTheFormIsGuardedInPlaceOfItsOwnTrap(): void
    {
        $form = $this->form();
        self::assertSame('newsletter', $form->getConfig()->getOption('guard')['action']);
        self::assertFalse($form->has('website'));
        self::assertFalse($form->has('opened_at'));
        self::assertTrue($form->has(FormGuard::TRAP_FIELD));
        self::assertTrue($form->has(FormGuard::STAMP_FIELD));
    }

    public function testASignUpMadeAsAPersonMakesItGoesThrough(): void
    {
        $form = $this->send($this->form());
        self::assertTrue($form->isValid(), implode(', ', $this->refusals($form)));
    }

    public function testAFilledTrapIsRefused(): void
    {
        self::assertContains(FormGuard::TRAPPED, $this->refusals($this->send($this->form(), ['guard_website' => 'https://spam.example'])));
    }

    public function testASignUpSentTooFastIsRefused(): void
    {
        $form = $this->send($this->form(['min_delay' => 3]), ['guard_opened' => static::getContainer()->get(FormGuard::class)->stamp(time() - 1)]);
        self::assertContains(FormGuard::TOO_FAST, $this->refusals($form));
    }

    public function testASignUpWithoutTheCaptchasTokenIsRefused(): void
    {
        $form = $this->form();
        if (!$form->has('guard_captcha')) {
            self::markTestSkipped('The host application has no captcha (glitchr/omniguard).');
        }
        $this->send($form, ['guard_captcha' => '']);
        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, $form->get('guard_captcha')->getErrors()->count());
    }

    public function testWithoutACaptchaTheTrapAndTheTimeAlone(): void
    {
        $form = $this->send($this->form(['challenge' => false]));
        self::assertFalse($form->has('guard_captcha'));
        self::assertTrue($form->isValid(), implode(', ', $this->refusals($form)));
        self::assertFalse($this->send($this->form(['challenge' => false]), ['guard_website' => 'x'])->isValid(), 'the trap still holds');
        self::assertFalse($this->send($this->form(['challenge' => false, 'min_delay' => 3]), ['guard_opened' => static::getContainer()->get(FormGuard::class)->stamp(time())])->isValid(), 'the time still holds');
    }
}
