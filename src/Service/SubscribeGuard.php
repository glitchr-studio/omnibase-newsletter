<?php

namespace Base\Newsletter\Service;

use Base\Newsletter\Repository\SubscriberRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * What stops a robot - or someone using the form to flood a stranger's
 * inbox with confirmation mails: the trap field filled, a form sent faster
 * than a person types (or with a time it did not get from us), a second
 * sign-up from the same address within the flood interval, more than the
 * limiter allows in the hour. Each answers with a reason the controller
 * turns into a flash - except the trap, which is thanked and dropped so the
 * robot learns nothing.
 */
final class SubscribeGuard
{
    public const TRAPPED = 'trapped';
    public const TOO_FAST = 'too_fast';
    public const FLOOD = 'flood';
    public const TOO_MANY = 'too_many';

    public function __construct(
        private readonly SubscriberRepository $subscribers,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        #[Autowire('%newsletter.min_delay%')] private readonly int $minDelay = 3,
        #[Autowire('%newsletter.flood_interval%')] private readonly int $floodInterval = 60,
        // framework.rate_limiter.newsletter_subscribe (NewsletterExtension), when symfony/rate-limiter is there.
        private readonly RateLimiterFactoryInterface|RateLimiterFactory|null $limiter = null,
    ) {
    }

    /** null when the address may be signed up. */
    public function check(FormInterface $form, Request $request): ?string
    {
        if ('' !== trim((string) $form->get('website')->getData())) {
            return self::TRAPPED;
        }
        $opened = SignedTimestamp::verify((string) $form->get('opened_at')->getData(), $this->secret);
        if ($this->minDelay > 0 && (null === $opened || time() - $opened < $this->minDelay)) {
            return self::TOO_FAST;
        }
        $ip = $request->getClientIp();
        if ($this->floodInterval > 0 && $ip) {
            $last = $this->subscribers->findLastFromIp($ip);
            if ($last && time() - $last->getCreatedAt()->getTimestamp() < $this->floodInterval) {
                return self::FLOOD;
            }
        }
        if ($this->limiter && !$this->limiter->create($ip ?? 'unknown')->consume()->isAccepted()) {
            return self::TOO_MANY;
        }

        return null;
    }
}
