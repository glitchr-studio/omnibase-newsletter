<?php

namespace Base\Newsletter\DependencyInjection;

use Base\Bundle\AbstractBaseExtension;
use Base\Newsletter\Service\SubscribeGuard;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\RateLimiter\RateLimiterFactory;

class NewsletterExtension extends AbstractBaseExtension implements PrependExtensionInterface
{
    /** The limiter of the sign-up form: framework.rate_limiter.newsletter_subscribe. */
    public const LIMITER = 'newsletter_subscribe';

    public function getConfiguration(array $config, ContainerBuilder $container): NewsletterConfiguration
    {
        return new NewsletterConfiguration();
    }

    /**
     * A few sign-ups an hour per visitor, so the form cannot be used to flood
     * someone's inbox with confirmation mails. Declared first: a host's own
     * framework.rate_limiter.newsletter_subscribe, read after, wins.
     */
    public function prepend(ContainerBuilder $container): void
    {
        if (!class_exists(RateLimiterFactory::class) || !$container->hasExtension('framework')) {
            return;
        }

        $container->prependExtensionConfig('framework', ['rate_limiter' => [self::LIMITER => [
            'policy' => 'sliding_window',
            'limit' => 5,
            'interval' => '1 hour',
        ]]]);
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'));
        $loader->load('services.php');

        $configuration = new NewsletterConfiguration();
        $config = (new Processor())->processConfiguration($configuration, $configs);

        // Flat parameters: newsletter.double_opt_in, newsletter.digest.title...
        $this->setConfiguration($container, $config, $configuration->getTreeBuilder()->buildTree()->getName());

        // The limiter, when the component is there; null otherwise (or when a
        // host turned it off), and the guard does without.
        if ($container->hasDefinition(SubscribeGuard::class)) {
            $container->getDefinition(SubscribeGuard::class)
                ->setArgument('$limiter', new Reference('limiter.'.self::LIMITER, ContainerInterface::NULL_ON_INVALID_REFERENCE));
        }
    }
}
