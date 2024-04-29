<?php declare(strict_types = 1);

namespace Vairogs\Component\Mapper\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Vairogs\Bundle\DependencyInjection\Dependency;
use Vairogs\Bundle\VairogsBundle;

use function dirname;
use function sprintf;

final readonly class MapperConfiguration implements Dependency
{
    public function addSection(
        ArrayNodeDefinition $rootNode,
        callable $enableIfStandalone,
    ): void {
        $rootNode
            ->children()
            ->arrayNode(Dependency::COMPONENT_MAPPER)
            ->{$enableIfStandalone(sprintf('%s/%s', VairogsBundle::VAIROGS, Dependency::COMPONENT_MAPPER), self::class)}()
            ->children()
                ->arrayNode('voters')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('role_voter')->defaultValue(false)->end()
                        ->booleanNode('operation_voter')->defaultValue(false)->end()
                    ->end()
                ->end()
                ->arrayNode('mapping')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('entity')->isRequired()->end()
                            ->scalarNode('resource')->isRequired()->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ->end();
    }

    public function registerConfiguration(
        ContainerConfigurator $container,
        ContainerBuilder $builder,
    ): void {
        if (!VairogsBundle::enabled($builder, Dependency::COMPONENT_MAPPER)) {
            return;
        }

        $container->import(dirname(__DIR__) . '/Resources/config/services.php');

        if (VairogsBundle::p($builder, Dependency::COMPONENT_MAPPER, 'voters.role_voter')) {
            $container->import(dirname(__DIR__) . '/Resources/config/voters/role.php');
        }

        if (VairogsBundle::p($builder, Dependency::COMPONENT_MAPPER, 'voters.operation_voter')) {
            $container->import(dirname(__DIR__) . '/Resources/config/voters/operation.php');
        }
    }
}
