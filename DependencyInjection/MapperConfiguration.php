<?php declare(strict_types = 1);

namespace Vairogs\Component\Mapper\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Vairogs\Bundle\DependencyInjection\AbstractDependencyConfiguration;
use Vairogs\Bundle\VairogsBundle;

use function dirname;
use function sprintf;

final class MapperConfiguration extends AbstractDependencyConfiguration
{
    public function addSection(
        ArrayNodeDefinition $rootNode,
        callable $enableIfStandalone,
        string $component,
    ): void {
        $rootNode
            ->children()
                ->arrayNode($component)
                ->{$enableIfStandalone(sprintf('%s/%s', VairogsBundle::VAIROGS, $component), self::class)}()
                ->children()
                    ->arrayNode('voters')
                        ->addDefaultsIfNotSet()
                        ->children()
                            ->booleanNode('role_voter')->defaultValue(false)->end()
                            ->booleanNode('operation_voter')->defaultValue(false)->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    public function registerConfiguration(
        ContainerConfigurator $container,
        ContainerBuilder $builder,
        string $component,
    ): void {
        $container->import(dirname(__DIR__) . '/Resources/config/services.php');

        if (VairogsBundle::p($builder, $component, 'voters.role_voter')) {
            $container->import(dirname(__DIR__) . '/Resources/config/voters/role.php');
        }

        if (VairogsBundle::p($builder, $component, 'voters.operation_voter')) {
            $container->import(dirname(__DIR__) . '/Resources/config/voters/operation.php');
        }
    }

    public function usesDoctrine(): bool
    {
        return true;
    }
}
