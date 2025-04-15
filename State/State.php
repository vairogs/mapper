<?php declare(strict_types = 1);

/*
 * This file is part of the Vairogs package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <davis@vairogs.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vairogs\Component\Mapper\State;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Exception\ResourceClassNotFoundException;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Symfony\Security\Exception\AccessDeniedException;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\Proxy;
use InvalidArgumentException;
use LogicException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\DependencyInjection\Attribute\Lazy;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\Uid\Uuid;
use Vairogs\Bundle\ApiPlatform\Constants\MappingType;
use Vairogs\Bundle\ApiPlatform\Functions;
use Vairogs\Bundle\Service\RequestCache;
use Vairogs\Bundle\Traits\_GetReadProperty;
use Vairogs\Bundle\Traits\_LoadReflection;
use Vairogs\Component\Mapper\Attribute\Mapped;
use Vairogs\Component\Mapper\Attribute\Modifier;
use Vairogs\Component\Mapper\Attribute\OnDeny;
use Vairogs\Component\Mapper\Attribute\SimpleApiResource;
use Vairogs\Component\Mapper\Attribute\SkipCircularReference;
use Vairogs\Component\Mapper\Constants\MapperContext;
use Vairogs\Component\Mapper\Constants\MapperOperation;
use Vairogs\Component\Mapper\Exception\MappingException;
use Vairogs\Component\Mapper\Traits\_GetIgnore;
use Vairogs\Component\Mapper\Traits\_MapFromAttribute;
use Vairogs\Functions\Iteration;

use function array_key_exists;
use function class_exists;
use function count;
use function get_object_vars;
use function in_array;
use function is_object;
use function is_string;
use function method_exists;
use function property_exists;
use function reset;
use function sprintf;

class State
{
    protected ?PropertyAccessor $accessor = null;
    protected array $allowedFields = [];

    public function __construct(
        #[Lazy]
        protected readonly AuthorizationCheckerInterface $security,
        #[Lazy]
        protected readonly EntityManagerInterface $entityManager,
        protected readonly RequestCache $requestCache,
        protected readonly Functions $functions,
    ) {
        $this->accessor = PropertyAccess::createPropertyAccessor();
    }

    /**
     * @throws ORMException
     * @throws ReflectionException
     */
    public function find(
        string $class,
        mixed $id,
        bool $load = false,
    ): ?object {
        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use _GetReadProperty;
                use _MapFromAttribute;
            };
        }

        $count = ($repository = $this->entityManager->getRepository($class))->count([$property = $_helper->getReadProperty($_helper->mapFromAttribute($class, $this->requestCache), $this->requestCache) => $id]);

        if (0 === $count) {
            return null;
        }

        if ('id' !== $property) {
            $id = $repository->createQueryBuilder('m')
                ->select('m.id')
                ->where(sprintf('m.%s = :property', $property))
                ->setParameter('property', $id)
                ->getQuery()
                ->useQueryCache(true)
                ->getSingleColumnResult()[0];
        }

        return $this->findById($class, $id, $load);
    }

    /**
     * @throws ORMException
     */
    public function findById(
        string $class,
        mixed $id,
        bool $load = false,
    ): ?object {
        $count = $this->entityManager->getRepository($class)->count(['id' => $id]);

        if (0 === $count) {
            return null;
        }

        $id = $this->parseIfUuid($id);

        if ($load) {
            return $this->entityManager->find($class, $id);
        }

        return $this->entityManager->getReference($class, $id);
    }

    public function findRelationName(
        string $propertyName,
        string $sourceClass,
        string $targetClass,
    ): ?string {
        return $this->requestCache->memoize(MapperContext::RELATION_NAME, $sourceClass . '/' . $propertyName, function () use ($sourceClass, $targetClass, $propertyName) {
            $associations = $this->entityManager->getClassMetadata($sourceClass)->getAssociationMappings();

            $matches = [];
            $i = 0;

            foreach ($associations as $fieldName => $association) {
                if ($association['targetEntity'] === $targetClass) {
                    $matches[$association['inversedBy'] ?? $i++] = $fieldName;
                }
            }

            if (1 === count($matches)) {
                return reset($matches);
            }

            return $matches[$propertyName] ?? null;
        });
    }

    /**
     * @throws ReflectionException
     */
    public function getApiResource(
        object|string $object,
    ): ReflectionAttribute {
        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use _LoadReflection;
            };
        }

        $reflection = $_helper->loadReflection($object, $this->requestCache);

        if ([] !== ($ea = $reflection->getAttributes(SimpleApiResource::class))) {
            return $ea[0];
        }

        return $reflection->getAttributes(ApiResource::class)[0];
    }

    public function getValue(
        object $object,
        string $property,
    ): mixed {
        if ('id' === $property) {
            return $object->getId();
        }

        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use _GetReadProperty;
                use _MapFromAttribute;
            };
        }

        if (!$this->entityManager->getUnitOfWork()->isInIdentityMap($object) && !in_array($property, ['id', $_helper->getReadProperty($_helper->mapFromAttribute($_helper->loadReflection($object, $this->requestCache)->getName(), $this->requestCache), $this->requestCache)], true)) {
            $object = $this->getActualObject($object);
        }

        if ($this->entityManager->getUnitOfWork()->isInIdentityMap($object)) {
            return $this->accessor->getValue($object, $property);
        }

        $result = $this->entityManager->getRepository($object::class)->createQueryBuilder('m')
            ->select(sprintf('m.%s', $property))
            ->where('m.id = :id')
            ->setParameter('id', $object->getId())
            ->getQuery()
            ->useQueryCache(true)
            ->getSingleColumnResult()[0];

        return $this->parseIfUuid($result);
    }

    /**
     * @throws ReflectionException
     */
    public function isEntity(
        object|string $object,
    ): bool {
        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use _LoadReflection;
            };
        }

        return [] !== $_helper->loadReflection($object, $this->requestCache)->getAttributes(ORM\Entity::class);
    }

    /**
     * @throws ReflectionException
     */
    public function isMapped(
        object|string $object,
    ): bool {
        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use _MapFromAttribute;
            };
        }

        return null !== $_helper->mapFromAttribute($object, $this->requestCache);
    }

    /**
     * @throws ReflectionException
     */
    public function isMappedType(
        string|object $objectOrClass,
        MappingType $type,
    ): bool {
        if (is_object($objectOrClass)) {
            $objectOrClass = $objectOrClass::class;
        }

        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use _LoadReflection;
            };
        }

        try {
            $reflection = $_helper->loadReflection($objectOrClass, $this->requestCache);
        } catch (ReflectionException) {
            return false;
        }

        do {
            if ($this->isMapped($reflection->getName()) && match ($type) {
                MappingType::RESOURCE => $this->functions->isResource($reflection->getName()),
                MappingType::ENTITY => $this->isEntity($reflection->getName()),
            }) {
                return true;
            }
        } while ($reflection = $reflection->getParentClass());

        return false;
    }

    public function modifyValue(
        ReflectionClass $reflection,
        object $output,
        string $propertyName,
        mixed &$propertyValue,
    ): void {
        if ($reflection->hasProperty($propertyName)) {
            $attributes = $reflection->getProperty($propertyName)->getAttributes(Modifier::class);

            if ([] !== $attributes) {
                $attribute = $attributes[0];
                $arguments = $attribute->getArguments();

                if (2 === count($arguments) && in_array($arguments[0], ['$this', 'self'], true)) {
                    $propertyValue = $attribute->newInstance()->closure($propertyValue, $output);
                } else {
                    $propertyValue = $attribute->newInstance()->closure($propertyValue);
                }
            }
        }
    }

    /**
     * @throws ResourceClassNotFoundException
     * @throws ReflectionException
     * @throws ORMException
     */
    public function toResource(
        ?object $object,
        array $context = [],
    ): ?object {
        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use _GetIgnore;
                use _GetReadProperty;
                use _LoadReflection;
                use _MapFromAttribute;
                use Iteration\Traits\_AddElementIfNotExists;
                use Iteration\Traits\_HaveCommonElements;
            };
        }

        if (!array_key_exists('groups', $context)) {
            $context['groups'] = [];
        }

        $context[MapperContext::MAPPER_LEVEL->value] = ($context[MapperContext::MAPPER_LEVEL->value] ?? 0) + 1;

        if (null === $object || null === $this->findById($object::class, $object->getId())) {
            return null;
        }

        $reflection = $_helper->loadReflection($object, $this->requestCache);

        if (999 !== ($found = $this->requestCache->value(MapperContext::ALREADY_NORMALIZED, $reflection->getName(), 999, (string) $object->getId()))) {
            return $found;
        }

        unset($found);

        if (!$this->isMappedType($object, MappingType::ENTITY)) {
            if (!$this->isEntity($object)) {
                throw new InvalidArgumentException(sprintf('%s is not an entity', $object::class));
            }

            return null; // TODO: config to throw or return | ^ ? <
        }

        $_helper->addElementIfNotExists($context[MapperContext::MAPPER_PARENTS->value], $targetResourceClass = $_helper->mapFromAttribute($object, $this->requestCache), $targetResourceClass);

        $operation = $context['operation'] ?? $context['root_operation'] ?? ($context['request'] ?? null)?->attributes->get('_api_operation');

        if (is_object($operation)) {
            $operation = $operation::class;
        }

        $output = new $targetResourceClass();

        $targetResourceReflection = $_helper->loadReflection($output, $this->requestCache);

        if ([] === ($this->allowedFields[$targetResourceClass] ?? [])) {
            $this->allowedFields[$targetResourceClass] = [];

            if (!$this->security->isGranted($operation, $targetResourceClass)) {
                if ($targetResourceClass !== $context['resource_class']) {
                    $decision = null;

                    if ([] !== $attributes = $targetResourceReflection->getAttributes(OnDeny::class)) {
                        $decision = $attributes[0]->newInstance()->allowedFields;
                    }

                    if (null === $decision || [] === $decision) {
                        return null;
                    }

                    $this->allowedFields[$targetResourceClass] = $decision;
                } else {
                    $exception = new AccessDeniedException();
                    $exception->setAttributes($operation);
                    $exception->setSubject($targetResourceClass);

                    throw $exception;
                }
            }
        }

        foreach ($reflection->getProperties() as $property) {
            $propertyName = $property->getName();

            $propertyType = $property->getType()?->getName();

            if (null === $propertyType) {
                throw new MappingException(sprintf('Type for property %s on class %s cannot be detected', $propertyName, $reflection->getName()));
            }

            $propertyValue = $this->getValue($object, $propertyName);

            if (null === $propertyValue || [] !== $_helper->getIgnore($property) || (Collection::class === $propertyType && 0 === $propertyValue->count())) {
                continue;
            }

            if (Collection::class === $propertyType || (class_exists($propertyType) && $this->isEntity($propertyType))) {
                if (1 < $context[MapperContext::MAPPER_LEVEL->value] && !in_array($operation, MapperOperation::OPERATION_GET, true)) {
                    continue;
                }

                if (Collection::class === $propertyType) {
                    $this->setResourceProperty($output, $propertyName, [], context: $context);
                    $targetClass = $_helper->mapFromAttribute($propertyValue->getTypeClass()->getName(), $this->requestCache);
                } else {
                    $targetClass = $_helper->mapFromAttribute($propertyType, $this->requestCache);
                }

                if (null === $targetClass) {
                    continue;
                }

                if ($this->isCircularReference($targetClass, $context, $targetResourceReflection, $propertyName)) {
                    continue;
                }

                $open = false;
                $att = $this->getApiResource($targetClass)->newInstance();
                $ref = $_helper->loadReflection($att, $this->requestCache)->getProperty('normalizationContext')->getValue($att);

                if ($_helper->haveCommonElements($context['groups'], $ref['groups'])) {
                    $open = true;
                }

                $resource = new $targetClass();

                if (Collection::class === $propertyType) {
                    if (0 === $propertyValue->count()) {
                        continue;
                    }

                    if (!$open) {
                        $rp = $_helper->getReadProperty($resource, $this->requestCache);

                        $relationName = $this->findRelationName($propertyName, $propertyValue->getTypeClass()->getName(), $_helper->loadReflection($object, $this->requestCache)->getName());
                        $qb = $this->entityManager->getRepository($propertyValue->getTypeClass()->getName())->createQueryBuilder('e')->select(sprintf('e.%s', $rp));

                        //                        if (null === $relationName) {
                        $qb
                            ->innerJoin($object::class, 'r', Join::WITH, 'r.id = :relation')
                            ->innerJoin(sprintf('r.%s', $propertyName), 'j', Join::WITH, 'j.id = e.id');
                        //                        } else {
                        $qb
//                                ->where(sprintf('\'e.%s\' = :relation', $relationName))
                            ->setParameter('relation', $object->getId());
                        //                        }

                        $rps = $qb
                            ->getQuery()
                            ->useQueryCache(true)
                            ->getSingleColumnResult();

                        //                        dd($rps, $qb->getQuery()->getSQL(), $relationName);

                        foreach ($rps as $key) {
                            $instance = clone $resource;

                            if (is_string($key) && Uuid::isValid($key)) {
                                $key = Uuid::fromString($key);
                            }
                            $instance->{$rp} = $key;
                            $this->setResourceProperty($output, $propertyName, $instance, true, $context);
                        }

                        continue;
                    }

                    foreach ($propertyValue->getValues() as $value) {
                        $this->setResourceProperty($output, $propertyName, $this->toResource($value, $context), true, $context);
                    }

                    continue;
                }

                if (!$open) {
                    $instance = clone $resource;
                    $rp = $_helper->getReadProperty($resource, $this->requestCache);
                    $instance->{$rp} = $this->getValue($propertyValue, $rp);
                    $this->setResourceProperty($output, $propertyName, $instance, context: $context);
                } else {
                    $this->setResourceProperty($output, $propertyName, $this->toResource($propertyValue, $context), context: $context);
                }

                continue;
            }

            $this->modifyValue($targetResourceReflection, $output, $propertyName, $propertyValue);
            $this->setResourceProperty($output, $propertyName, $propertyValue, context: $context);
        }

        $this->requestCache->memoize(MapperContext::ALREADY_NORMALIZED, $reflection->getName(), static fn () => $output, false, (string) $object->getId());

        return $output;
    }

    protected function getActualObject(
        object $entity,
    ): object {
        return $this->requestCache->memoize(MapperContext::ACTUAL_OBJECT, $entity::class, function () use ($entity) {
            if ($entity instanceof Proxy) {
                $this->entityManager->getUnitOfWork()->initializeObject($entity);

                if (!method_exists($entity, 'getId')) {
                    throw new LogicException('The entity does not have a getId method.');
                }

                foreach (get_object_vars($entity) as $property => $value) {
                    $entity->{$property};
                }

                $this->entityManager->detach($entity);

                return $this->entityManager->getRepository($entity::class)->find($entity->getId());
            }

            return $entity;
        });
    }

    /**
     * @throws ReflectionException
     */
    protected function getEntityClass(
        Operation $operation,
    ): string {
        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use _MapFromAttribute;
            };
        }

        $class = $_helper->mapFromAttribute($operation->getClass(), $this->requestCache);

        if (null === $class) {
            throw new MappingException(sprintf('Resource class %s does not have a %s attribute', $operation->getClass(), Mapped::class));
        }

        return $class;
    }

    /**
     * @throws ReflectionException
     */
    protected function getRelationPropertyClass(
        object $object,
        string $propertyName,
    ): ?string {
        return $this->requestCache->memoize(MapperContext::GET_READ_PROP, $propertyName, fn () => $this->processRelationProperty($object, $propertyName, true));
    }

    /**
     * @throws ReflectionException
     */
    protected function isCircularReference(
        string $targetClass,
        array $context,
        ReflectionClass $resourceReflection,
        string $propertyName,
    ): bool {
        $attributes = $resourceReflection->getProperty($propertyName)->getAttributes(SkipCircularReference::class);
        $maxLevels = -1;

        if ([] !== $attributes) {
            $maxLevels = $attributes[0]->newInstance()->maxLevels;
        }

        return in_array($targetClass, $context[MapperContext::MAPPER_PARENTS->value], true) && (($maxLevels > 0 && $context[MapperContext::MAPPER_LEVEL->value] >= $maxLevels) || -1 === $maxLevels);
    }

    protected function parseIfUuid(
        mixed $id,
    ): mixed {
        if (is_string($id) && Uuid::isValid($id)) {
            $id = Uuid::fromString($id);
        }

        return $id;
    }

    /**
     * @throws ReflectionException
     */
    protected function processRelationProperty(
        object $object,
        string $propertyName,
        bool $returnType = false,
    ): bool|string|null {
        static $phpDoc = null;

        if (null === $phpDoc) {
            $phpDoc = new PhpDocExtractor();
        }

        $type = $phpDoc->getType($object::class, $propertyName);
        $result = null;

        if ($type instanceof CollectionType) {
            $collectionValueType = $type->getCollectionValueType();

            if ($collectionValueType instanceof ObjectType) {
                if ($returnType) {
                    $result = $collectionValueType->getClassName();
                } else {
                    $result = $this->isMappedType($collectionValueType->getClassName(), MappingType::RESOURCE);
                }
            }
        }

        return $result;
    }

    /**
     * @throws ReflectionException
     */
    protected function setResourceProperty(
        object $resource,
        string $propertyName,
        mixed $propertyValue,
        bool $appendArray = false,
        array $context = [],
    ): void {
        if (null === $propertyValue) {
            return;
        }

        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use _GetReadProperty;
                use _LoadReflection;
            };
        }

        $granted = true;

        if (null !== ($reflection = $this->getRelationPropertyClass($resource, $propertyName))) {
            $ref = $_helper->loadReflection($resource, $this->requestCache);

            $operation = $context['operation'] ?? $context['root_operation'] ?? ($context['request'] ?? null)?->attributes->get('_api_operation');

            if (is_object($operation)) {
                $operation = $operation::class;
            }

            if (!$this->security->isGranted($operation, $reflection)) {
                $allowed = ($ref->getProperty($propertyName)->getAttributes(OnDeny::class)[0] ?? null)?->newInstance()->allowedFields ?? [];
                $this->allowedFields[$reflection] = $allowed;
                $granted = false;
            }
        }

        if ([] !== $this->allowedFields[$resource::class] && !in_array($_helper->getReadProperty($resource, $this->requestCache), $this->allowedFields[$resource::class] ?? [], true)) {
            $this->allowedFields[$resource::class][] = $_helper->getReadProperty($resource, $this->requestCache);
        }

        if (
            !$granted
            && is_object($propertyValue)
            && !array_key_exists($propertyValue::class, $context[MapperContext::MAPPER_PARENTS->value])
            && $this->functions->isResource($propertyValue)
            && !in_array($propertyName, $this->allowedFields[$resource::class], true)
        ) {
            return;
        }

        if (property_exists($resource, $propertyName)) {
            if ($appendArray) {
                $resource->{$propertyName}[] = $propertyValue;
            } else {
                $resource->{$propertyName} = $propertyValue;
            }
        }
    }
}
