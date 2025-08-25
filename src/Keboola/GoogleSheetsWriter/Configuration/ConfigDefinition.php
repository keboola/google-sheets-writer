<?php

declare(strict_types=1);

/* phpcs:disable */

namespace Keboola\GoogleSheetsWriter\Configuration;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class ConfigDefinition implements ConfigurationInterface
{
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_APPEND = 'append';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        // New-style root to keep static analysis happy
        $treeBuilder = new TreeBuilder('parameters');
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('data_dir')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->variableNode('#serviceAccountJson')->defaultNull()->end()
                ->arrayNode('tables')
                    ->isRequired()
                    ->arrayPrototype()
                        ->children()
                            ->integerNode('id')->isRequired()->min(0)->end()
                            ->scalarNode('fileId')->end()
                            ->scalarNode('title')->end()
                            ->arrayNode('folder')
                                ->children()
                                    ->scalarNode('id')->end()
                                    ->scalarNode('title')->end()
                                ->end()
                            ->end()
                            ->enumNode('action')->values([self::ACTION_CREATE, self::ACTION_UPDATE, self::ACTION_APPEND])->end()
                            ->scalarNode('tableId')->end()
                            ->booleanNode('enabled')->defaultValue(true)->end()
                            ->scalarNode('sheetId')
                                ->validate()
                                    ->ifString()
                                    ->then(function ($value) { return (int) $value; })
                                ->end()
                            ->end()
                            ->scalarNode('sheetTitle')->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
