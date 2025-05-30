<?php declare(strict_types = 1);

/*
 * This file is part of the Vairogs package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <davis@vairogs.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vairogs\Component\Mapper\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Vairogs\Bundle\DependencyInjection\AbstractDependencyConfiguration;
use Vairogs\Bundle\VairogsBundle;

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
        parent::registerConfiguration($container, $builder, $component);

        if (VairogsBundle::p($builder, $component, 'voters.role_voter')) {
            $container->import(__DIR__ . '/../Resources/config/voters/role.php');
        }

        if (VairogsBundle::p($builder, $component, 'voters.operation_voter')) {
            $container->import(__DIR__ . '/../Resources/config/voters/operation.php');
        }
    }

    public function usesDoctrine(): bool
    {
        return true;
    }
}
