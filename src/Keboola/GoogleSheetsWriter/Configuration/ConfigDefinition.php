<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter\Configuration;

use InvalidArgumentException;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class ConfigDefinition implements ConfigurationInterface
{
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_APPEND = 'append';

    public const SHEET_MODE_REPLACE = 'replace';
    public const SHEET_MODE_ADD = 'add';

    /**
     * Generates the configuration tree builder.
     *
     * @return \Symfony\Component\Config\Definition\Builder\TreeBuilder The tree builder
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('parameters');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('data_dir')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('#serviceAccount')
                    ->info('Service account credentials JSON (encrypted)')
                ->end()
                ->arrayNode('tables')
                    ->isRequired()
                    ->arrayPrototype('array')
                        ->children()
                            ->scalarNode('id')
                                ->isRequired()
                                ->validate()
                                    ->always(function ($value) {
                                        if (is_int($value)) {
                                            return $value;
                                        }
                                        if (is_string($value) && ctype_digit($value)) {
                                            return (int) $value;
                                        }
                                        throw new InvalidArgumentException(
                                            sprintf('The value "%s" is not a valid integer.', $value)
                                        );
                                    })
                                ->end()
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
                                ->validate()
                                    ->ifString()
                                    ->then(function ($value) {
                                        return intval($value);
                                    })
                                ->end()
                            ->end()
                            ->scalarNode('sheetTitle')
                            ->end()
                            ->enumNode('sheetMode')
                                ->values(['replace', 'add'])
                                ->defaultValue('replace')
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
