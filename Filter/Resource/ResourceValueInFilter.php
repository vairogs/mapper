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

use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\QueryBuilder;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Vairogs\Component\Mapper\Constants\MappingType;
use Vairogs\Component\Mapper\Filter\AbstractResourceFilter;
use Vairogs\Component\Mapper\Filter\ORM\ORMValueInFilter;

use function array_map;
use function array_merge;
use function array_merge_recursive;
use function sprintf;

#[AutoconfigureTag('api_platform.filter')]
class ResourceValueInFilter extends AbstractResourceFilter
{
    public function apply(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if (!$this->checkApply($resourceClass, $context)) {
            return;
        }

        (new ORMValueInFilter(
            $this->managerRegistry,
            $this->requestCache,
            $this->logger,
            $this->properties,
            $this->nameConverter,
            $this->mapper,
        ))->apply($queryBuilder, $queryNameGenerator, $this->mapper->mapFromAttribute($resourceClass, $this->requestCache), $operation, $context);
    }

    public function getDescription(
        string $resourceClass,
    ): array {
        if (!$this->checkApply($resourceClass, early: true)) {
            return [];
        }

        $description = [];

        foreach ($this->properties as $property => $unused) {
            foreach (['in' => '', 'notIn' => ' not'] as $type => $t) {
                $description[sprintf('%s[%s]', $property, $type)] = [
                    'property' => $this->normalizePropertyName($property),
                    'type' => 'mixed',
                    'required' => false,
                    'description' => sprintf('Filter by multiple%s values -> Comma separated values, no spaces between values.', $t),
                    'openapi' => [
                        'example' => sprintf('status[%s]=DRAFT,RECEIVED, id[%s]=1,2,3', $type, $type),
                    ],
                ];
            }
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
        $filtered = [];
        foreach ($this->getProperties($resourceClass) as $type => $items) {
            if ($this->mapper->isMappedType($type, MappingType::RESOURCE)) {
                $rp = $this->mapper->getReadProperty($type, $this->requestCache);
                foreach ($items as $item => $unused) {
                    $filtered[] = [$name = $item . '.' . $rp => $name];
                }
                continue;
            }

            if ('array' === $type) {
                foreach ($items as $item => $property) {
                    $rev = $this->mapper->mapFromAttribute($resourceClass, $this->requestCache);
                    $pp = $this->mapper->loadReflection($rev, $this->requestCache)->getProperty($item);
                    $orm = array_merge_recursive($pp->getAttributes(ManyToMany::class), $pp->getAttributes(OneToMany::class));
                    if ([] !== $orm && $this->mapper->isMapped($targetEntity = $orm[0]->getArguments()['targetEntity'])) {
                        $colRef = $this->mapper->loadReflection($this->mapper->mapFromAttribute($targetEntity, $this->requestCache), $this->requestCache);
                        $rp = $this->mapper->getReadProperty($colRef->getName(), $this->requestCache);
                        $filtered[] = [$name = $item . '.' . $rp => $name];
                    }
                }
                continue;
            }

            $filtered[] = array_map(static fn (ReflectionProperty $property) => $property->getName(), $items);
        }

        return array_merge(...$filtered);
    }
}
