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
use ApiPlatform\OpenApi\Model\Parameter;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\QueryBuilder;
use ReflectionException;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Vairogs\Bundle\ApiPlatform\Constants\MappingType;
use Vairogs\Bundle\Traits\_GetReadProperty;
use Vairogs\Bundle\Traits\_LoadReflection;
use Vairogs\Component\Mapper\Filter\AbstractResourceFilter;
use Vairogs\Component\Mapper\Filter\ORM\ORMValueInFilter;
use Vairogs\Component\Mapper\Traits\_MapFromAttribute;

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

        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use _MapFromAttribute;
            };
        }

        new ORMValueInFilter(
            $this->managerRegistry,
            $this->memoize,
            $this->logger,
            $this->properties,
            $this->nameConverter,
            $this->state,
        )->apply($queryBuilder, $queryNameGenerator, $_helper->mapFromAttribute($resourceClass, $this->memoize), $operation, $context);
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
                $description[$name = sprintf('%s[%s]', $property, $type)] = [
                    'openapi' => new Parameter(
                        $name,
                        'query',
                        description: sprintf('Filter by multiple%s values -> Comma separated values, no spaces between values.', $t),
                        required: false,
                        schema: ['type' => 'mixed'],
                        example: sprintf('status[%s]=DRAFT,RECEIVED, id[%s]=1,2,3', $type, $type),
                    ),
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
        $filtered = [];

        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use _GetReadProperty;
                use _LoadReflection;
                use _MapFromAttribute;
            };
        }

        foreach ($this->getProperties($resourceClass) as $type => $items) {
            if ($this->state->isMappedType($type, MappingType::RESOURCE)) {
                $rp = $_helper->getReadProperty($type, $this->memoize);

                foreach ($items as $item => $unused) {
                    $filtered[] = [$name = $item . '.' . $rp => $name];
                }

                continue;
            }

            if ('array' === $type) {
                foreach ($items as $item => $property) {
                    /** @var ReflectionProperty $property */
                    if (!$property->getType()?->isBuiltin()) {
                        $rev = $_helper->mapFromAttribute($resourceClass, $this->memoize);
                        $pp = $_helper->loadReflection($rev, $this->memoize)->getProperty($item);
                        $orm = array_merge_recursive($pp->getAttributes(ManyToMany::class), $pp->getAttributes(OneToMany::class));

                        if ([] !== $orm && $this->state->isMapped($targetEntity = $orm[0]->getArguments()['targetEntity'])) {
                            $colRef = $_helper->loadReflection($_helper->mapFromAttribute($targetEntity, $this->memoize), $this->memoize);
                            $rp = $_helper->getReadProperty($colRef->getName(), $this->memoize);
                            $filtered[] = [$name = $item . '.' . $rp => $name];
                        }

                        continue 2;
                    }
                }

                // $filtered[] = array_map(static fn (ReflectionProperty $property) => $property->getName(), $items);

                continue;
            }

            $filtered[] = array_map(static fn (ReflectionProperty $property) => $property->getName(), $items);
        }

        return array_merge(...$filtered);
    }
}
