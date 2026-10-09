<?php

namespace Base\Newsletter\Form;

use Base\Newsletter\Service\SignedTimestamp;
use Base\Service\FormGuard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The sign-up form - one address, and `source`: which page it was filled on.
 * Guarded as glitchr/omnibase guards a form (its option `guard`, action
 * "newsletter", newsletter.min_delay): a trap, the time it takes, the lists,
 * the captcha when the site has glitchr/omnishield. With a glitchr/omnibase
 * from before the guard, the form's own: a trap field (`website`) and the
 * time it was opened (`opened_at`, signed with the kernel's secret), read by
 * Service\SubscribeGuard - until the host takes a core that has the guard.
 */
final class SubscribeType extends AbstractType
{
    public function __construct(
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        #[Autowire('%newsletter.min_delay%')] private readonly int $minDelay = 3,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => false,
                'attr' => ['placeholder' => '@newsletter.form.email', 'autocomplete' => 'email', 'maxlength' => 180],
                'constraints' => [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 180)],
            ])
            ->add('source', HiddenType::class, ['data' => $options['source'], 'constraints' => [new Assert\Length(max: 40)]]);

        if (!class_exists(FormGuard::class)) {
            // A glitchr/omnibase from before the forms' guard: the trap, hidden from people
            // (newsletter.css .newsletter-trap) and filled by robots, and the signed time.
            $builder
                ->add('website', TextType::class, ['required' => false, 'label' => false, 'row_attr' => ['class' => 'newsletter-trap', 'aria-hidden' => 'true'], 'attr' => ['tabindex' => '-1', 'autocomplete' => 'off']])
                ->add('opened_at', HiddenType::class, ['data' => SignedTimestamp::sign(time(), $this->secret)]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'newsletter',
            'csrf_token_id' => 'newsletter_subscribe',
            'source' => 'site',
        ]);
        if (class_exists(FormGuard::class)) {
            $resolver->setDefault('guard', ['action' => 'newsletter', 'min_delay' => $this->minDelay]);
        }
        $resolver->setAllowedTypes('source', 'string');
    }

    public function getBlockPrefix(): string
    {
        return 'newsletter';
    }
}
