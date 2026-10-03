<?php

namespace Base\Newsletter\Twig;

use Base\Newsletter\Controller\Client\NewsletterController;
use Base\Newsletter\Repository\SubscriberRepository;
use Symfony\Component\Form\FormView;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * What a host's own pages ask the newsletter: the sign-up form for a footer
 * or a home page (its source: the page's key, kept with the address), and
 * how many read the letters.
 */
final class NewsletterExtension extends AbstractExtension
{
    public function __construct(
        private readonly NewsletterController $controller,
        private readonly SubscriberRepository $subscribers,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('newsletter_form', fn (string $source = 'site'): FormView => $this->controller->form($source)->createView()),
            new TwigFunction('newsletter_count', fn (?string $locale = null): int => $this->subscribers->countConfirmed($locale)),
        ];
    }
}
