<?php

namespace Base\Newsletter;

use Base\Bundle\AbstractBaseBundle;
use Base\Traits\SingletonTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A newsletter with no account behind it: addresses that confirmed
 * (Subscriber), letters written in the back office and sent through
 * Messenger (Campaign), a monthly digest drafted from what the site has to
 * announce (Digest\DigestSourceInterface).
 */
class NewsletterBundle extends AbstractBaseBundle
{
    use SingletonTrait;

    public function __construct()
    {
        parent::__construct();
    }

    /** Modern layout: the class lives in src/, the bundle root is the package root. */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // App\-wins, as omnibase does for its own entities: an application may
        // declare App\Entity\Newsletter\Subscriber extending ours and take over.
        $this->setMapping($this->getPath().'/src/Entity', 'Base\Newsletter\Entity', 'App\Entity\Newsletter');
        $this->setMapping($this->getPath().'/src/Repository', 'Base\Newsletter\Repository', 'App\Repository\Newsletter');
    }
}
