<?php declare(strict_types = 1);

/*
 * This file is part of the Vairogs package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <davis@vairogs.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vairogs\Component\Mapper\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use ReflectionException;
use ReflectionUnionType;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Vairogs\Bundle\Traits\_LoadReflection;
use Vairogs\Component\Mapper\Constants\MapperContext;
use Vairogs\Component\Mapper\State\State;
use Vairogs\Component\Mapper\Traits\_GetIgnore;
use Vairogs\Component\Mapper\Traits\_MapFromAttribute;
use Vairogs\Functions\Iteration;
use Vairogs\Functions\Memoize\MemoizeCache;

use function array_key_exists;
use function array_merge;

abstract class AbstractResourceFilter implements FilterInterface
{
    use Traits\_PropertyNameNormalizer;
    protected readonly PropertyAccessor $accessor;

    public function __construct(
        protected readonly ManagerRegistry $managerRegistry,
        protected readonly MemoizeCache $memoize,
        protected readonly ?LoggerInterface $logger = null,
        protected ?array $properties = null,
        protected readonly ?NameConverterInterface $nameConverter = null,
        protected readonly ?State $state = null,
    ) {
        $this->accessor = PropertyAccess::createPropertyAccessor();
    }

    abstract public function getPropertiesForType(
        string $resourceClass,
    ): array;

    /**
     * @throws ReflectionException
     */
    public function getProperties(
        string $resourceClass,
    ): array {
        return $this->memoize->memoize(MapperContext::RESOURCE_PROPERTIES, $resourceClass, function () use ($resourceClass) {
            static $_helper = null;

            if (null === $_helper) {
                $_helper = new class {
                    use _GetIgnore;
                    use _LoadReflection;
                    use _MapFromAttribute;
                    use Iteration\Traits\_AddElementIfNotExists;
                };
            }

            $properties = [];

            $entityClass = $_helper->mapFromAttribute($resourceClass, $this->memoize);
            $entityReflection = $_helper->loadReflection($entityClass, $this->memoize);

            foreach ($_helper->loadReflection($resourceClass, $this->memoize)->getProperties() as $property) {
                if ([] !== $_helper->getIgnore($property)) {
                    continue;
                }

                if ($entityReflection->hasProperty($property->getName()) && [] !== $_helper->getIgnore($entityReflection->getProperty($property->getName()))) {
                    continue;
                }

                $type = $property->getType();

                if ($type instanceof ReflectionUnionType) {
                    continue;
                }

                $propertyType = $type?->getName();

                if (null === $propertyType) {
                    continue;
                }

                $_helper->addElementIfNotExists($properties[$propertyType], $property, $property->getName());
            }

            return $properties;
        });
    }

    protected function checkApply(
        string $resourceClass,
        array &$context = [],
        bool $early = false,
    ): bool {
        if ([] === ($context['filters'] ?? []) && !$early) {
            return false;
        }

        $this->properties = array_merge($this->properties ?? [], $this->getPropertiesForType($resourceClass));

        if ([] === $this->properties) {
            return false;
        }

        if ($early) {
            return true;
        }

        foreach ($context['filters'] ?? [] as $property => $filter) {
            if (!array_key_exists($property, $this->properties)) {
                unset($context['filters'][$property]);
            }
        }

        return [] !== $context['filters'];
    }
}
