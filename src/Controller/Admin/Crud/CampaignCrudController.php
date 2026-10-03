<?php

namespace Base\Newsletter\Controller\Admin\Crud;

use Base\Admin\Attribute\AdminAction;
use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Controller\AbstractCrudController;
use Base\Admin\Filter\Filters;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\SelectField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Base\Newsletter\Entity\Campaign;
use Base\Newsletter\Entity\Subscriber;
use Base\Newsletter\Message\SendCampaignMessage;
use Base\Newsletter\Repository\SubscriberRepository;
use Base\Newsletter\Service\NewsletterMailer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Writing a letter: a subject, a text, maybe a button, who it goes to (one
 * language, or everyone). Two buttons on a draft: "send me a test" - to the
 * signed-in administrator's own address - and "send", which asks first and
 * hands the letter to Messenger; the list sees SENDING, then SENT and how
 * many it went to.
 */
class CampaignCrudController extends AbstractCrudController
{
    private NewsletterMailer $mailer;
    private MessageBusInterface $bus;
    private SubscriberRepository $subscribers;
    private TranslatorInterface $translator;
    /** @var list<string> */
    private array $locales = ['en', 'fr', 'de'];

    #[Required]
    public function setNewsletterServices(NewsletterMailer $mailer, MessageBusInterface $bus, SubscriberRepository $subscribers, TranslatorInterface $translator, #[Autowire('%kernel.enabled_locales%')] array $locales = []): void
    {
        $this->mailer = $mailer;
        $this->bus = $bus;
        $this->subscribers = $subscribers;
        $this->translator = $translator;
        $this->locales = $locales ?: $this->locales;
    }

    public static function getEntityFqcn(): string
    {
        return Campaign::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-envelope-open-text';
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('state')->add('locale');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('subject', '@newsletter.admin.campaign.subject')->setColumns(8);
        yield SelectField::new('locale', '@newsletter.admin.campaign.locale')->setChoices(array_combine($this->locales, $this->locales))->setRequired(false)->setColumns(4)->setHelp('@newsletter.admin.campaign.locale_help');
        yield SelectField::new('state', '@newsletter.admin.campaign.state')->hideOnForm();
        yield TextareaField::new('body', '@newsletter.admin.campaign.body')->setNumOfRows(14)->hideOnIndex()->setHelp('@newsletter.admin.campaign.body_help');
        yield TextField::new('link', '@newsletter.admin.campaign.link')->setColumns(8)->setRequired(false)->hideOnIndex()->setHelp('@newsletter.admin.campaign.link_help');
        yield TextField::new('linkLabel', '@newsletter.admin.campaign.link_label')->setColumns(4)->setRequired(false)->hideOnIndex();
        yield IntegerField::new('recipients', '@newsletter.admin.campaign.recipients')->hideOnForm();
        yield DateTimeField::new('createdAt', '@newsletter.admin.campaign.created_at')->onlyOnIndex();
        yield DateTimeField::new('sentAt', '@newsletter.admin.campaign.sent_at')->hideOnForm();
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = parent::configureActions($actions);
        $draft = fn ($campaign) => $campaign instanceof Campaign && $campaign->isDraft();
        foreach ([Actions::PAGE_INDEX, Actions::PAGE_DETAIL] as $page) {
            $actions
                ->add($page, Action::new('test', '@newsletter.admin.campaign.action.test', 'fa-solid fa-flask')->linkToCrudAction('test')->displayIf($draft))
                ->add($page, Action::new('send', '@newsletter.admin.campaign.action.send', 'fa-solid fa-paper-plane')->linkToCrudAction('send')->displayIf($draft)
                    ->askConfirmation('@newsletter.admin.campaign.action.send_confirm'));
        }

        return $actions;
    }

    /** The letter as the subscribers will get it, to the signed-in administrator. */
    #[AdminAction('/{entityId}/test')]
    public function test(string $entityId): Response
    {
        /** @var Campaign $campaign */
        $campaign = $this->findEntity($entityId);
        $user = $this->getUser();
        $email = $user && method_exists($user, 'getEmail') ? $user->getEmail() : null;
        if (!$email) {
            $this->addFlash('danger', '@newsletter.admin.campaign.flash.no_email');

            return $this->redirectToIndex();
        }
        // An address that is no subscriber: its unsubscribe link leads nowhere, harmlessly.
        $this->mailer->campaign(new Subscriber($email, $campaign->getLocale() ?? $this->locales[0] ?? 'en', 'test'), $campaign, true);
        $this->addFlash('success', $this->trans('admin.campaign.flash.tested', ['email' => $email]));

        return $this->redirectToIndex();
    }

    #[AdminAction('/{entityId}/send')]
    public function send(string $entityId): Response
    {
        /** @var Campaign $campaign */
        $campaign = $this->findEntity($entityId);
        if (!$campaign->isDraft()) {
            $this->addFlash('danger', '@newsletter.admin.campaign.flash.not_draft');

            return $this->redirectToIndex();
        }
        $campaign->markSending();
        $this->entityManager->flush();
        $this->bus->dispatch(new SendCampaignMessage($campaign->getId()));
        $this->addFlash('success', $this->trans('admin.campaign.flash.sending', ['count' => $this->subscribers->countConfirmed($campaign->getLocale())]));

        return $this->redirectToIndex();
    }

    private function trans(string $key, array $parameters): string
    {
        return $this->translator->trans($key, $parameters, 'newsletter');
    }
}
