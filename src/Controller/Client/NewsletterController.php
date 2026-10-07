<?php

namespace Base\Newsletter\Controller\Client;

use Base\Attributes\Attribute\Sitemap;
use Base\Newsletter\Entity\Subscriber;
use Base\Newsletter\Form\SubscribeType;
use Base\Newsletter\Repository\SubscriberRepository;
use Base\Newsletter\Service\NewsletterMailer;
use Base\Newsletter\Service\SubscribeGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Signing up, confirming, leaving. Double opt-in: the address confirms from
 * its mail before anything else is sent to it. The answer to a sign-up never
 * says whether the address was already on the list - the same words for a
 * new one, a pending one, a confirmed one -, so the form cannot be used to
 * find out who reads the letters.
 */
class NewsletterController extends AbstractController
{
    public function __construct(
        private readonly SubscriberRepository $subscribers,
        private readonly EntityManagerInterface $entityManager,
        private readonly SubscribeGuard $guard,
        private readonly NewsletterMailer $mailer,
        #[Autowire('%newsletter.double_opt_in%')] private readonly bool $doubleOptIn = true,
    ) {
    }

    /** A page of its own: what the letter is, how often, the form. */
    #[Sitemap(priority: 0.4, changefreq: 'monthly')]
    #[Route('/newsletter', name: 'newsletter_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@Newsletter/client/index.html.twig', [
            'newsletter_form' => $this->form('newsletter')->createView(),
        ]);
    }

    #[Route('/newsletter', name: 'newsletter_subscribe', methods: ['POST'])]
    public function subscribe(Request $request): Response
    {
        $form = $this->form();
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            // Refused by the form's guard for its haste: said as such; anything else, the form is invalid.
            $tooFast = false;
            foreach ($form->getErrors() as $error) {
                $tooFast = $tooFast || \in_array($error->getCause(), ['too_fast', 'stale'], true);
            }
            $this->addFlash('newsletter', $tooFast ? 'flash.'.SubscribeGuard::TOO_FAST : 'flash.invalid');

            return $this->back($request);
        }

        $reason = $this->guard->check($form, $request);
        // A robot in the trap is thanked and forgotten: it learns nothing.
        if (SubscribeGuard::TRAPPED === $reason) {
            $this->addFlash('newsletter', $this->doubleOptIn ? 'flash.check_inbox' : 'flash.subscribed');

            return $this->back($request);
        }
        if ($reason) {
            $this->addFlash('newsletter', 'flash.'.$reason);

            return $this->back($request);
        }

        $email = (string) $form->get('email')->getData();
        $subscriber = $this->subscribers->findOneByEmail($email);
        if (!$subscriber) {
            $source = preg_replace('/[^a-z0-9_\-]/', '', mb_strtolower((string) $form->get('source')->getData())) ?: 'site';
            $subscriber = new Subscriber($email, $request->getLocale(), $source, $request->getClientIp());
            $this->entityManager->persist($subscriber);
        }
        $subscriber->resubscribe($this->doubleOptIn);
        if (!$this->doubleOptIn) {
            $subscriber->confirm();
        }
        $this->entityManager->flush();

        // Only an address that has not confirmed gets a mail; one already on
        // the list gets nothing - and the page says the same either way.
        if (!$subscriber->isConfirmed()) {
            $this->mailer->confirm($subscriber);
        }
        $this->addFlash('newsletter', $this->doubleOptIn ? 'flash.check_inbox' : 'flash.subscribed');

        return $this->back($request);
    }

    #[Route('/newsletter/confirm/{token}', name: 'newsletter_confirm', requirements: ['token' => '[0-9a-f]{32}'], methods: ['GET'])]
    public function confirm(string $token): Response
    {
        $subscriber = $this->subscribers->findOneByToken($token) ?? throw $this->createNotFoundException('No subscriber with this token.');
        $subscriber->confirm();
        $this->entityManager->flush();

        return $this->render('@Newsletter/client/confirmed.html.twig', ['subscriber' => $subscriber]);
    }

    /**
     * GET from the mail's link: a page with one button (mail scanners follow
     * links, they must not unsubscribe anyone). POST from that button, or
     * from a mail client's one-click List-Unsubscribe (RFC 8058) - which
     * sends no CSRF token: the token of the address is the secret. The row
     * stays, marked; an unknown token is answered "done" all the same.
     */
    #[Route('/newsletter/unsubscribe/{token}', name: 'newsletter_unsubscribe', requirements: ['token' => '[0-9a-f]{32}'], methods: ['GET', 'POST'])]
    public function unsubscribe(Request $request, string $token): Response
    {
        $subscriber = $this->subscribers->findOneByToken($token);
        if ($request->isMethod('POST')) {
            if ($subscriber) {
                $subscriber->unsubscribe();
                $this->entityManager->flush();
            }

            return $this->render('@Newsletter/client/unsubscribe.html.twig', ['done' => true, 'token' => $token]);
        }

        return $this->render('@Newsletter/client/unsubscribe.html.twig', [
            'done' => null === $subscriber || $subscriber->isUnsubscribed(),
            'token' => $token,
        ]);
    }

    /** The form a page embeds (the Twig function newsletter_form(), @Newsletter/client/_form.html.twig). */
    public function form(string $source = 'site'): FormInterface
    {
        return $this->createForm(SubscribeType::class, null, [
            'action' => $this->generateUrl('newsletter_subscribe'),
            'source' => $source,
        ]);
    }

    /** Back to the page the form was filled on, at the form; this site's pages only. */
    private function back(Request $request): Response
    {
        $referer = (string) $request->headers->get('referer');
        $host = parse_url($referer, \PHP_URL_HOST);
        if ($referer && $host === $request->getHost()) {
            return $this->redirect(preg_replace('/#.*$/', '', $referer).'#newsletter');
        }

        return $this->redirect($this->generateUrl('newsletter_index').'#newsletter');
    }
}
