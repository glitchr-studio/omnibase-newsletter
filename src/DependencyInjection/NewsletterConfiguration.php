<?php

namespace Base\Newsletter\DependencyInjection;

use Base\Bundle\AbstractBaseConfiguration;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class NewsletterConfiguration extends AbstractBaseConfiguration
{
    private bool $childrenDeclared = false;

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = $this->getTreeBuilder();
        if ($this->childrenDeclared) {
            return $treeBuilder;
        }
        $this->childrenDeclared = true;

        $treeBuilder->getRootNode()
            ->children()
                ->booleanNode('double_opt_in')->defaultTrue()
                    ->info('An address receives nothing before it confirms from the mail it was sent. Off, it is signed up at once.')->end()
                ->integerNode('min_delay')->min(0)->defaultValue(3)
                    ->info('Seconds between the form being opened and sent: faster is a robot.')->end()
                ->integerNode('flood_interval')->min(0)->defaultValue(60)
                    ->info('Seconds between two sign-ups from the same address.')->end()
                ->scalarNode('from_name')->defaultNull()
                    ->info('The sender\'s name on the letters; null: the site\'s (base.settings.mail.name).')->end()
                ->arrayNode('digest')->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->info('newsletter:draft-digest drafts at all.')->end()
                        ->scalarNode('title')->defaultNull()->info('The digest\'s subject, {month} replaced; null: "Your dates in {month}", translated.')->end()
                    ->end()
                ->end()
            ->end()
        ->end();

        return $treeBuilder;
    }
}
