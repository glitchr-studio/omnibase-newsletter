<?php

namespace Base\Newsletter\Form;

use Base\Newsletter\Service\SignedTimestamp;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The sign-up form - one address. On top of it, for the robots: a trap
 * field (`website`, hidden from people, filled by robots) and the time the
 * form was opened (`opened_at`), signed with the kernel's secret so it
 * cannot be made up. The guard reads both (Service\SubscribeGuard). `source`
 * says which page it was filled on.
 */
final class SubscribeType extends AbstractType
{
    public function __construct(
        #[Autowire('%kernel.secret%')] private readonly string $secret,
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
            // The trap: hidden from people (newsletter.css .newsletter-trap), filled by robots.
            ->add('website', TextType::class, ['required' => false, 'label' => false, 'row_attr' => ['class' => 'newsletter-trap', 'aria-hidden' => 'true'], 'attr' => ['tabindex' => '-1', 'autocomplete' => 'off']])
            ->add('opened_at', HiddenType::class, ['data' => SignedTimestamp::sign(time(), $this->secret)])
            ->add('source', HiddenType::class, ['data' => $options['source'], 'constraints' => [new Assert\Length(max: 40)]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'newsletter',
            'csrf_token_id' => 'newsletter_subscribe',
            'source' => 'site',
        ]);
        $resolver->setAllowedTypes('source', 'string');
    }

    public function getBlockPrefix(): string
    {
        return 'newsletter';
    }
}
