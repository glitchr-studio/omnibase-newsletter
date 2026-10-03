<?php

namespace Base\Newsletter\Entity;

use Base\Newsletter\Enum\CampaignState;
use Base\Newsletter\Repository\CampaignRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A letter: a subject, a text (plain, a blank line between two paragraphs),
 * maybe a button to a page, and who it goes to - the subscribers of one
 * language, or everyone. Written in the back office, tried on oneself, then
 * sent: SENDING while the mails go out, SENT with the number they went to.
 */
#[ORM\Entity(repositoryClass: CampaignRepository::class)]
#[ORM\Table(name: 'newsletter_campaign')]
class Campaign
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    protected ?string $subject = null;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    protected ?string $body = null;

    /** The button's page: a path of the site ("/concerts") or a full address. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Assert\Regex(pattern: '#^(https?://|/)#', message: 'A path ("/concerts") or a full address (https://...).')]
    protected ?string $link = null;

    #[ORM\Column(length: 80, nullable: true)]
    #[Assert\Length(max: 80)]
    protected ?string $linkLabel = null;

    /** Who it goes to: the subscribers of this language; null: everyone. */
    #[ORM\Column(length: 5, nullable: true)]
    protected ?string $locale = null;

    #[ORM\Column(type: 'string', length: 16, enumType: CampaignState::class)]
    protected CampaignState $state = CampaignState::DRAFT;

    #[ORM\Column(type: 'datetime_immutable')]
    protected \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $sentAt = null;

    /** The addresses it went to. */
    #[ORM\Column(type: 'integer')]
    protected int $recipients = 0;

    public function __construct(?string $subject = null, ?string $body = null, ?string $locale = null)
    {
        $this->setSubject($subject);
        $this->setBody($body);
        $this->setLocale($locale);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return (string) $this->subject;
    }

    public function getId(): ?int { return $this->id; }

    public function getSubject(): ?string { return $this->subject; }
    public function setSubject(?string $subject): self { $this->subject = self::clean($subject); return $this; }

    public function getBody(): ?string { return $this->body; }
    public function setBody(?string $body): self { $this->body = self::clean(null !== $body ? str_replace(["\r\n", "\r"], "\n", $body) : null); return $this; }

    public function getLink(): ?string { return $this->link; }
    public function setLink(?string $link): self { $this->link = self::clean($link); return $this; }

    public function getLinkLabel(): ?string { return $this->linkLabel; }
    public function setLinkLabel(?string $linkLabel): self { $this->linkLabel = self::clean($linkLabel); return $this; }

    public function getLocale(): ?string { return $this->locale; }
    public function setLocale(?string $locale): self { $this->locale = self::clean($locale) ? mb_strtolower(trim($locale)) : null; return $this; }

    public function getState(): CampaignState { return $this->state; }
    public function isDraft(): bool { return CampaignState::DRAFT === $this->state; }
    public function isSent(): bool { return CampaignState::SENT === $this->state; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function getRecipients(): int { return $this->recipients; }

    /** On its way: the message is dispatched. Only a draft goes. */
    public function markSending(): self
    {
        if (!$this->isDraft()) {
            throw new \LogicException(sprintf('The campaign "%s" is %s: only a draft can be sent.', $this->subject, $this->state->value));
        }
        $this->state = CampaignState::SENDING;

        return $this;
    }

    public function markSent(int $recipients): self
    {
        $this->state = CampaignState::SENT;
        $this->recipients = $recipients;
        $this->sentAt = new \DateTimeImmutable();

        return $this;
    }

    /** @return list<string> the paragraphs: what a blank line separates */
    public function getParagraphs(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', (string) $this->body) ?: [])));
    }

    private static function clean(?string $value): ?string
    {
        $value = null !== $value ? trim($value) : '';

        return '' !== $value ? $value : null;
    }
}
