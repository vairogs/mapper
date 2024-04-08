<?php declare(strict_types = 1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Vairogs\Component\Mapper\Voter\OperationRoleVoter;

return static function (
    ContainerConfigurator $container,
): void {
    $services = $container->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(OperationRoleVoter::class);
};
