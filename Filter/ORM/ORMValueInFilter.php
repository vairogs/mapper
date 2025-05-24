<?php declare(strict_types = 1);

/*
 * This file is part of the Vairogs package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <davis@vairogs.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vairogs\Component\Mapper\Filter\ORM;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use BackedEnum;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Vairogs\Bundle\ApiPlatform\Constants\MappingType;
use Vairogs\Bundle\Traits\_LoadReflection;
use Vairogs\Component\Mapper\State\State;
use Vairogs\Component\Mapper\Traits\_MapFromAttribute;
use Vairogs\Functions\Memoize\MemoizeCache;

use function array_key_exists;
use function array_map;
use function array_merge_recursive;
use function explode;
use function is_array;
use function reset;
use function sprintf;

class ORMValueInFilter extends AbstractFilter
{
    public function __construct(
        ManagerRegistry $managerRegistry,
        private readonly MemoizeCache $memoize,
        ?LoggerInterface $logger = null,
        ?array $properties = null,
        ?NameConverterInterface $nameConverter = null,
        protected readonly ?State $state = null,
    ) {
        parent::__construct($managerRegistry, $logger, $properties, $nameConverter);
    }

    public function getDescription(
        string $resourceClass,
    ): array {
        return [];
    }

    /**
     * @throws ReflectionException
     */
    protected function filterProperty(
        string $property,
        $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if (
            !is_array($value)
            || (!array_key_exists('in', $value) && !array_key_exists('notIn', $value))
            || !$this->isPropertyEnabled($property, $resourceClass)
        ) {
            return;
        }

        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use _LoadReflection;
                use _MapFromAttribute;
            };
        }

        foreach ($value as $type => $filterValue) {
            $alias = $queryBuilder->getRootAliases()[0];
            $field = $property;

            if ($this->isPropertyNested($property, $resourceClass)) {
                [$alias, $field] = $this->addJoinsForNestedProperty($property, $alias, $queryBuilder, $queryNameGenerator, $resourceClass, Join::INNER_JOIN);
            }

            $values = explode(',', $filterValue);

            $reflection = $_helper->loadReflection($resourceClass, $this->memoize);

            if ('m' !== $alias) {
                $exp = explode('.', $property, 2);
                $typeAlias = ($prop = $reflection->getProperty(reset($exp)))->getType();

                if (null === $typeAlias || $typeAlias instanceof ReflectionUnionType) {
                    return;
                }

                if ($this->state->isMappedType($typeAlias->getName(), MappingType::ENTITY)) {
                    $reflection = $_helper->loadReflection($typeAlias->getName(), $this->memoize);
                }

                if (Collection::class === $typeAlias->getName()) {
                    $orm = array_merge_recursive($prop->getAttributes(ManyToMany::class), $prop->getAttributes(OneToMany::class));

                    if ([] !== $orm) {
                        $colRef = $_helper->loadReflection($_helper->mapFromAttribute($orm[0]->getArguments()['targetEntity'], $this->memoize), $this->memoize);
                        $reflection = $_helper->loadReflection($_helper->mapFromAttribute($colRef->getName(), $this->memoize), $this->memoize);
                    }
                }
            }

            $propertyType = $reflection->getProperty($field)->getType();

            if (!$propertyType?->isBuiltin()) {
                $refType = $_helper->loadReflection($propertyType->getName(), $this->memoize);

                if ($refType->implementsInterface(BackedEnum::class)) {
                    $instance = $reflection->newInstance();
                    $values = array_map(static function (string $value) use ($instance, $field) {
                        self::getEnumClassFromProperty($instance, $field)::from($value);

                        return $value;
                    }, $values);
                }
            } else {
                $values = array_map(static function (string $value) use ($propertyType) {
                    return match ($propertyType?->getName()) {
                        'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                        'int' => filter_var($value, FILTER_VALIDATE_INT),
                        'float' => filter_var($value, FILTER_VALIDATE_FLOAT),
                        default => $value,
                    };
                }, $values);
            }

            $queryBuilder->andWhere($queryBuilder->expr()->{$type}(sprintf('%s.%s', $alias, $field), $values));
        }
    }

    /**
     * @return class-string<BackedEnum>
     */
    private static function getEnumClassFromProperty(
        object $object,
        string $propertyName,
    ): string {
        try {
            $type = new ReflectionProperty($object, $propertyName)->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                throw new InvalidArgumentException('Not an enum');
            }

            $typeName = $type->getName();

            if (!enum_exists($typeName)) {
                throw new InvalidArgumentException('Enum not found: ' . $typeName);
            }

            return $typeName;
        } catch (ReflectionException $e) {
            throw new InvalidArgumentException('Not a valid enum: ' . $e->getMessage());
        }
    }
}
