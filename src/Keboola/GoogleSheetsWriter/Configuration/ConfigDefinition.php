<?php
/**
 * Created by PhpStorm.
 * User: miroslavcillik
 * Date: 10/08/16
 * Time: 15:50
 */

namespace Keboola\GoogleSheetsWriter\Configuration;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class ConfigDefinition implements ConfigurationInterface
{
    const ACTION_CREATE = 'create';
    const ACTION_UPDATE = 'update';
    const ACTION_APPEND = 'append';

    /**
     * Generates the configuration tree builder.
     *
     * @return \Symfony\Component\Config\Definition\Builder\TreeBuilder The tree builder
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('parameters');

        $rootNode
            ->children()
                ->scalarNode('data_dir')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('tables')
                    ->isRequired()
                    ->prototype('array')
                        ->children()
                            ->integerNode('id')
                                ->isRequired()
                                ->min(0)
                            ->end()
                            ->scalarNode('fileId')
                            ->end()
                            ->scalarNode('title')
                            ->end()
                            ->arrayNode('folder')
                                ->children()
                                    ->scalarNode('id')
                                    ->end()
                                    ->scalarNode('title')
                                    ->end()
                                ->end()
                            ->end()
                            ->enumNode('action')
                                ->values(['create', 'update', 'append'])
                            ->end()
                            ->scalarNode('tableId')
                            ->end()
                            ->booleanNode('enabled')
                                ->defaultValue(true)
                            ->end()
                            ->scalarNode('sheetId')
                            ->end()
                            ->scalarNode('sheetTitle')
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
