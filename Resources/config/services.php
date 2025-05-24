<?php declare(strict_types = 1);

/*
 * This file is part of the Vairogs package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <davis@vairogs.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use ApiPlatform\Serializer\Filter\GroupFilter;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Vairogs\Component\Mapper\OpenApi\OpenApiNormalizer;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (
    ContainerConfigurator $container,
): void {
    $services = $container->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(OpenApiNormalizer::class)->args([
        service('.inner'),
    ]);

    $services->load(namespace: 'Vairogs\\Component\\Mapper\\', resource: __DIR__ . '/../../*')
        ->exclude(excludes: [__DIR__ . '/../../{Entity,Resources,Exception,Voter}']);

    $services->set(GroupFilter::class);
};
