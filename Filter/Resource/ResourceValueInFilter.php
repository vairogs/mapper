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
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Vairogs\Component\Mapper\Constants\Enum\MappingType;
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
            $this->logger,
            $this->properties,
            $this->nameConverter,
            $this->mapper,
        ))->apply($queryBuilder, $queryNameGenerator, $this->mapper->mapFromAttribute($resourceClass, $context), $operation, $context);
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
     */
    public function getPropertiesForType(
        string $resourceClass,
    ): array {
        $filtered = $i = [];
        foreach ($this->getProperties($resourceClass) as $type => $items) {
            if ($this->mapper->isMappedType($type, MappingType::RESOURCE, $i)) {
                $rp = $this->mapper->getReadProperty($type, $i);
                foreach ($items as $item => $unused) {
                    $filtered[] = [$name = $item . '.' . $rp => $name];
                }
                continue;
            }

            if ('array' === $type) {
                foreach ($items as $item => $property) {
                    $rev = $this->mapper->mapFromAttribute($resourceClass, $i);
                    $pp = $this->mapper->loadReflection($rev, $i)->getProperty($item);
                    $orm = array_merge_recursive($pp->getAttributes(ManyToMany::class), $pp->getAttributes(OneToMany::class));
                    if ([] !== $orm) {
                        $colRef = new ReflectionClass($this->mapper->mapFromAttribute($orm[0]->getArguments()['targetEntity'], $i));
                        $rp = $this->mapper->getReadProperty($colRef->getName(), $i);
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
