<?php

namespace Base\Newsletter\Service;

use Base\Entity\User\Notification;
use Base\Newsletter\Entity\Campaign;
use Base\Newsletter\Entity\Subscriber;
use Base\Notifier\NotifierInterface;
use Base\Notifier\Recipient\Recipient;
use Base\Service\SettingBagInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Notifier\Recipient\EmailRecipientInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The newsletter's two mails.
 *
 * The confirmation goes through omnibase's notifier, as every mail of the
 * site does (importance "email", from the site's address).
 *
 * A letter does not: omnibase's Notification builds its e-mail itself and
 * has no way to add a header, and a letter must carry List-Unsubscribe and
 * List-Unsubscribe-Post (RFC 8058) - what Gmail and the others ask of a
 * sender of bulk mail, and what gives the reader the "unsubscribe" button of
 * their mail client. So it is a TemplatedEmail sent with Symfony's mailer,
 * from the same address the notifier uses.
 *
 * Both templates are overridden from templates/bundles/NewsletterBundle/email/.
 */
final class NewsletterMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly NotifierInterface $notifier,
        private readonly UrlGeneratorInterface $urls,
        private readonly SettingBagInterface $settings,
        private readonly TranslatorInterface $translator,
        #[Autowire('%newsletter.from_name%')] private readonly ?string $fromName = null,
    ) {
    }

    /** "Confirm your address": the link that puts it on the list. */
    public function confirm(Subscriber $subscriber): void
    {
        $subject = $this->translator->trans('email.confirm.subject', ['site' => $this->siteTitle() ?? 'none'], 'newsletter', $subscriber->getLocale());

        $notification = new Notification('@Newsletter/email/confirm.html.twig');
        $notification->setSubject($subject);
        $notification->setTitle($subject);
        $notification->setHtmlTemplate('@Newsletter/email/confirm.html.twig', [
            'subject' => $subject,
            'site_title' => $this->siteTitle(),
            'locale' => $subscriber->getLocale(),
            'confirm' => $this->urls->generate('newsletter_confirm', ['token' => $subscriber->getToken()], UrlGeneratorInterface::ABSOLUTE_URL),
            'home' => $this->home(),
        ]);
        $notification->send('email', new Recipient($subscriber->getEmail(), null, $subscriber->getLocale()));
    }

    /**
     * A letter to one subscriber, with its way out: the link in the text,
     * and the two headers of a one-click unsubscribe. A test is marked so in
     * its subject.
     */
    public function campaign(Subscriber $subscriber, Campaign $campaign, bool $test = false): void
    {
        $unsubscribe = $this->urls->generate('newsletter_unsubscribe', ['token' => $subscriber->getToken()], UrlGeneratorInterface::ABSOLUTE_URL);
        $link = $campaign->getLink();
        if ($link && str_starts_with($link, '/')) {
            $link = rtrim($this->home(), '/').$link;
        }

        $email = (new TemplatedEmail())
            ->from($this->sender())
            ->to($subscriber->getEmail())
            ->subject(($test ? '[Test] ' : '').$campaign->getSubject())
            ->htmlTemplate('@Newsletter/email/campaign.html.twig')
            ->context([
                'subject' => $campaign->getSubject(),
                'campaign' => $campaign,
                'link' => $link,
                'site_title' => $this->siteTitle(),
                'locale' => $subscriber->getLocale(),
                'unsubscribe' => $unsubscribe,
                'home' => $this->home(),
                'test' => $test,
            ]);
        // The subscriber's language for the template's own words (Symfony 6.4+).
        if (method_exists($email, 'locale')) {
            $email->locale($subscriber->getLocale());
        }
        $email->getHeaders()
            ->addTextHeader('List-Unsubscribe', '<'.$unsubscribe.'>')
            ->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $this->mailer->send($email);
    }

    /** The site's address, as the notifier sends from it; the name newsletter.from_name says, if any. */
    private function sender(): Address
    {
        $recipient = $this->notifier->getTechnicalRecipient();
        if (!$recipient instanceof EmailRecipientInterface || !$recipient->getEmail()) {
            throw new \LogicException('No address to send the newsletter from: set base.settings.mail (or the notifier\'s technical recipient).');
        }
        $address = Address::create($recipient->getEmail());

        return new Address($address->getAddress(), $this->fromName ?? $address->getName());
    }

    /** base.settings.title, when the site has one and it can be read. */
    private function siteTitle(): ?string
    {
        try {
            $title = $this->settings->getScalar('base.settings.title');
        } catch (\Throwable) {
            return null;
        }

        return \is_string($title) && '' !== trim($title) ? trim($title) : null;
    }

    /**
     * The site's home, absolute: the newsletter page's address, its last
     * segment taken off. In a Messenger worker it is what
     * framework.router.default_uri says - set it.
     */
    private function home(): string
    {
        $url = $this->urls->generate('newsletter_index', [], UrlGeneratorInterface::ABSOLUTE_URL);

        return preg_replace('#newsletter/?$#', '', $url);
    }
}
