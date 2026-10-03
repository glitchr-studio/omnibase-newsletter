<?php

namespace Base\Newsletter\Entity;

use Base\Newsletter\Repository\SubscriberRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * An address on the list - never an account. Double opt-in: it receives
 * nothing before it confirms from the mail it was sent (confirmedAt). Going
 * away keeps the row (unsubscribedAt): the history is known, nothing is sent
 * to it any more, and coming back is a new confirmation, under a new token -
 * the links of the old mails no longer answer.
 *
 * The token is the key of the confirm and unsubscribe links: 32 hex
 * characters, as hard to guess as a password and never shown elsewhere.
 */
#[ORM\Entity(repositoryClass: SubscriberRepository::class)]
#[ORM\Table(name: 'newsletter_subscriber')]
#[ORM\UniqueConstraint(name: 'newsletter_subscriber_email_uniq', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'newsletter_subscriber_token_uniq', columns: ['token'])]
#[ORM\Index(columns: ['ip', 'createdAt'], name: 'newsletter_subscriber_ip_idx')]
class Subscriber
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\Column(length: 180)]
    protected string $email;

    #[ORM\Column(length: 32)]
    protected string $token;

    /** The language of the page it signed up from: its letters are in it. */
    #[ORM\Column(length: 5)]
    protected string $locale;

    #[ORM\Column(type: 'datetime_immutable')]
    protected \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $unsubscribedAt = null;

    /** Where it came from: 'site', 'import', the key of the page whose form it filled. */
    #[ORM\Column(length: 40)]
    protected string $source = 'site';

    #[ORM\Column(length: 45, nullable: true)]
    protected ?string $ip = null;

    /** The last letter it was sent: a send that is retried skips it. */
    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $lastCampaign = null;

    public function __construct(string $email, string $locale = 'en', string $source = 'site', ?string $ip = null)
    {
        $this->email = self::normalize($email);
        $this->locale = mb_strtolower(trim($locale)) ?: 'en';
        $this->source = mb_substr(trim($source), 0, 40) ?: 'site';
        $this->ip = $ip;
        $this->token = self::newToken();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->email;
    }

    /** The way an address is kept and looked up: trimmed, lowercased. */
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function getId(): ?int { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function getToken(): string { return $this->token; }

    public function getLocale(): string { return $this->locale; }
    public function setLocale(string $locale): self { $this->locale = mb_strtolower(trim($locale)) ?: $this->locale; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getConfirmedAt(): ?\DateTimeImmutable { return $this->confirmedAt; }
    public function getUnsubscribedAt(): ?\DateTimeImmutable { return $this->unsubscribedAt; }

    public function getSource(): string { return $this->source; }
    public function getIp(): ?string { return $this->ip; }

    /** It confirmed - whether or not it left since. */
    public function isConfirmed(): bool { return null !== $this->confirmedAt; }
    public function isUnsubscribed(): bool { return null !== $this->unsubscribedAt; }
    /** What the letters go to: confirmed, and still there. */
    public function isActive(): bool { return $this->isConfirmed() && !$this->isUnsubscribed(); }
    /** Signed up, not confirmed yet. */
    public function isPending(): bool { return !$this->isConfirmed() && !$this->isUnsubscribed(); }

    /**
     * The link of its mail was followed. Once is enough (the first date is
     * kept); an address that left is not brought back by an old link.
     */
    public function confirm(): self
    {
        if (!$this->isUnsubscribed()) {
            $this->confirmedAt ??= new \DateTimeImmutable();
        }

        return $this;
    }

    public function unsubscribe(): self
    {
        $this->unsubscribedAt ??= new \DateTimeImmutable();

        return $this;
    }

    /**
     * Back on the list after leaving. With double opt-in it confirms again,
     * under a new token; without, it is on the list at once. An address
     * still on the list is left as it is.
     */
    public function resubscribe(bool $doubleOptIn = true): self
    {
        if (!$this->isUnsubscribed()) {
            return $this;
        }
        $this->unsubscribedAt = null;
        $this->token = self::newToken();
        $this->confirmedAt = $doubleOptIn ? null : new \DateTimeImmutable();

        return $this;
    }

    public function getLastCampaign(): ?int { return $this->lastCampaign; }
    public function wasSent(Campaign $campaign): bool { return null !== $campaign->getId() && $this->lastCampaign === $campaign->getId(); }
    public function sent(Campaign $campaign): self { $this->lastCampaign = $campaign->getId(); return $this; }

    private static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
