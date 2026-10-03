<?php

namespace Base\Newsletter\MessageHandler;

use Base\Newsletter\Entity\Campaign;
use Base\Newsletter\Message\SendCampaignMessage;
use Base\Newsletter\Repository\CampaignRepository;
use Base\Newsletter\Repository\SubscriberRepository;
use Base\Newsletter\Service\NewsletterMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A letter sent: one mail per confirmed subscriber - of its language when
 * it has one -, each remembered on the subscriber as it goes, so a send that
 * fails half-way and is retried does not write twice to the first half. An
 * address its transport refuses is skipped and logged, not fatal. Then the
 * campaign is SENT, with the number of mails.
 */
#[AsMessageHandler]
final class SendCampaignHandler
{
    /** Subscribers remembered per flush. */
    private const BATCH = 50;

    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly SubscriberRepository $subscribers,
        private readonly NewsletterMailer $mailer,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(SendCampaignMessage $message): void
    {
        $campaign = $this->campaigns->find($message->campaignId);
        if (!$campaign instanceof Campaign || $campaign->isSent()) {
            return;
        }

        $sent = 0;
        foreach ($this->subscribers->findConfirmed($campaign->getLocale()) as $i => $subscriber) {
            if ($subscriber->wasSent($campaign)) {
                ++$sent;
                continue;
            }
            try {
                $this->mailer->campaign($subscriber, $campaign);
                $subscriber->sent($campaign);
                ++$sent;
            } catch (TransportExceptionInterface $e) {
                $this->logger->warning('Newsletter "{subject}" not sent to {email}: {error}', ['subject' => $campaign->getSubject(), 'email' => $subscriber->getEmail(), 'error' => $e->getMessage()]);
            }
            if (0 === ($i + 1) % self::BATCH) {
                $this->entityManager->flush();
            }
        }

        $campaign->markSent($sent);
        $this->entityManager->flush();
    }
}
