<?php declare(strict_types = 1);

/*
 * This file is part of the Vairogs package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <davis@vairogs.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vairogs\Component\Mapper\Filter\Resource;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Vairogs\Component\Mapper\Filter\AbstractResourceFilter;

use function array_merge;

#[AutoconfigureTag('api_platform.filter')]
class ResourceBooleanFilter extends AbstractResourceFilter
{
    public function apply(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        string|Operation|null $operation = null,
        array $context = [],
    ): void {
        if (!$this->checkApply($resourceClass, $context)) {
            return;
        }

        (new BooleanFilter(
            $this->managerRegistry,
            $this->logger,
            $this->properties,
            $this->nameConverter,
        ))->apply($queryBuilder, $queryNameGenerator, $this->mapper->mapFromAttribute($resourceClass, $this->requestCache, skipGlobal: true), $operation, $context);
    }

    public function getDescription(
        string $resourceClass,
    ): array {
        if (!$this->checkApply($resourceClass, early: true)) {
            return [];
        }

        $description = [];

        foreach ($this->properties as $property => $unused) {
            $propertyName = $this->normalizePropertyName($property);
            $description[$propertyName] = [
                'property' => $propertyName,
                'type' => 'bool',
                'required' => false,
            ];
        }

        return $description;
    }

    /**
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    public function getPropertiesForType(
        string $resourceClass,
    ): array {
        return array_merge($this->properties ?? [], $this->getProperties($resourceClass)['bool'] ?? []);
    }
}
