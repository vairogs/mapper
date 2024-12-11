<?php declare(strict_types = 1);

/*
 * This file is part of the Vairogs package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <davis@vairogs.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vairogs\Component\Mapper;

use ApiPlatform\Doctrine\Orm\Extension\FilterExtension;
use ApiPlatform\Doctrine\Orm\Extension\OrderExtension;
use ApiPlatform\Doctrine\Orm\Extension\QueryResultCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Exception\ResourceClassNotFoundException;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Symfony\Security\Exception\AccessDeniedException;
use ApiPlatform\Validator\Exception\ValidationException;
use Countable;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\Proxy;
use Error;
use Exception;
use InvalidArgumentException;
use LogicException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\Attribute\Lazy;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\TypeIdentifier;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Vairogs\Bundle\Service\RequestCache;
use Vairogs\Component\DoctrineTools\UTCDateTimeImmutable;
use Vairogs\Component\Functions\Iteration;
use Vairogs\Component\Mapper\Attribute\Mapped;
use Vairogs\Component\Mapper\Attribute\Modifier;
use Vairogs\Component\Mapper\Attribute\OnDeny;
use Vairogs\Component\Mapper\Attribute\SimpleApiResource;
use Vairogs\Component\Mapper\Attribute\SkipCircularReference;
use Vairogs\Component\Mapper\Constants\Context;
use Vairogs\Component\Mapper\Constants\MapperOperation;
use Vairogs\Component\Mapper\Constants\MappingType;
use Vairogs\Component\Mapper\Contracts\MapperInterface;
use Vairogs\Component\Mapper\Exception\ItemNotFoundHttpException;
use Vairogs\Component\Mapper\Exception\MappingException;
use Vairogs\Component\Mapper\Mercure\Mercure;

use function array_key_exists;
use function class_exists;
use function count;
use function get_class;
use function get_object_vars;
use function in_array;
use function is_array;
use function is_object;
use function is_string;
use function is_subclass_of;
use function method_exists;
use function property_exists;
use function sprintf;
use function strtolower;

#[Autoconfigure(lazy: true)]
class Mapper implements ProviderInterface, ProcessorInterface, MapperInterface
{
    protected ?PropertyAccessor $accessor = null;
    protected array $allowedFields = [];

    public function __construct(
        #[Lazy]
        protected readonly EntityManagerInterface $entityManager,
        #[Lazy]
        protected readonly ResourceMetadataCollectionFactoryInterface $resourceMetadataFactory,
        #[Lazy]
        protected readonly AuthorizationCheckerInterface $security,
        #[Lazy]
        protected readonly TranslatorInterface $translator,
        #[Lazy]
        protected readonly ParameterBagInterface $bag,
        #[Lazy]
        protected readonly Mercure $mercure,
        #[Lazy]
        protected readonly ValidatorInterface $validator,
        protected readonly RequestCache $requestCache,
        #[AutowireIterator(
            'api_platform.doctrine.orm.query_extension.collection',
        )]
        protected readonly iterable $collectionExtensions = [],
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
                use Traits\_GetReadProperty;
                use Traits\_MapFromAttribute;
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
        return $this->requestCache->memoize(Context::RELATION_NAME, $sourceClass . '/' . $propertyName, function () use ($sourceClass, $targetClass, $propertyName) {
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
                use Traits\_LoadReflection;
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
                use Traits\_GetReadProperty;
                use Traits\_MapFromAttribute;
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
                use Traits\_LoadReflection;
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
                use Traits\_MapFromAttribute;
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
                use Traits\_LoadReflection;
            };
        }

        try {
            $reflection = $_helper->loadReflection($objectOrClass, $this->requestCache);
        } catch (ReflectionException) {
            return false;
        }

        do {
            if ($this->isMapped($reflection->getName()) && match ($type) {
                MappingType::RESOURCE => $this->isResource($reflection->getName()),
                MappingType::ENTITY => $this->isEntity($reflection->getName()),
            }) {
                return true;
            }
        } while ($reflection = $reflection->getParentClass());

        return false;
    }

    /**
     * @throws ReflectionException
     */
    public function isRelationProperty(
        object $object,
        string $propertyName,
    ): bool {
        return $this->requestCache->memoize(Context::IS_READ_PROP, $propertyName, fn () => (bool) $this->processRelationProperty($object, $propertyName));
    }

    /**
     * @throws ReflectionException
     */
    public function isResource(
        object|string $object,
    ): bool {
        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use Traits\_LoadReflection;
            };
        }

        $reflection = $_helper->loadReflection($object, $this->requestCache);

        return [] !== $reflection->getAttributes(ApiResource::class) || [] !== $reflection->getAttributes(SimpleApiResource::class);
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
     * @throws ORMException
     * @throws ReflectionException
     * @throws ResourceClassNotFoundException
     */
    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): ?object {
        if (!$this->security->isGranted($operation::class, $data::class)) {
            throw new AccessDeniedHttpException('Access denied');
        }

        $entity = match (true) {
            $operation instanceof Delete => $this->delete($data, $operation),
            $operation instanceof Patch => $this->patch($data, $operation, $context),
            $operation instanceof Post => $this->post($data, $context),
            $operation instanceof Put => $this->put($data, $operation, $context),
            default => throw new BadRequestHttpException(sprintf('Invalid operation: "%s"', $operation::class)),
        };

        $this->validate($entity, $operation);

        $this->flush($entity, null !== $entity);
        $this->mercure->publishToMercure($entity, $operation);

        return $this->toResource($entity, $context);
    }

    /**
     * @throws ORMException
     * @throws ReflectionException
     * @throws ResourceClassNotFoundException
     */
    public function provide(
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): array|object|null {
        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use Traits\_GetReadProperty;
                use Traits\_LoadReflection;
            };
        }

        $class = $_helper->loadReflection($operation->getClass(), $this->requestCache);

        if (!$this->security->isGranted($operation::class, $class->getName())) {
            throw new AccessDeniedHttpException('Access denied');
        }

        return match (true) {
            $operation instanceof GetCollection => $this->getCollection($operation, $context),
            $operation instanceof Get,
            $operation instanceof Patch,
            $operation instanceof Put,
            $operation instanceof Post => $this->getItem($operation, $uriVariables[$_helper->getReadProperty($operation->getClass(), $this->requestCache)], $context),
            default => throw new BadRequestHttpException(sprintf('Invalid operation: "%s"', $operation::class)),
        };
    }

    /**
     * @throws ReflectionException
     * @throws ORMException
     */
    public function toEntity(
        object $object,
        array $context = [],
        ?object $existingEntity = null,
    ): ?object {
        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use Traits\_GetIgnore;
                use Traits\_GetReadProperty;
                use Traits\_LoadReflection;
                use Traits\_MapFromAttribute;
            };
        }

        if (!array_key_exists('groups', $context)) {
            $context['groups'] = [];
        }

        $reflection = $_helper->loadReflection($object, $this->requestCache);
        $targetEntityClass = $_helper->mapFromAttribute($reflection->getName(), $this->requestCache);

        $properties = $reflection->getProperties();
        $output = $existingEntity ?? new $targetEntityClass();

        foreach ($properties as $property) {
            $propertyName = $property->getName();

            if (!property_exists($targetEntityClass, $propertyName) || [] !== $_helper->getIgnore($property)) {
                continue;
            }

            $propertyType = $property->getType()?->getName();

            if ('self' === $propertyType) {
                $propertyType = $object::class;
            }

            try {
                $propertyValue = $property->getValue($object);
            } catch (Error) {
                $propertyValue = null;
            }

            if (is_subclass_of($propertyType, DateTimeInterface::class) && $propertyValue instanceof DateTimeInterface) {
                $propertyValue = match (true) {
                    $propertyValue instanceof UTCDateTimeImmutable => $propertyValue,
                    default => DateTimeImmutable::createFromInterface($propertyValue),
                };
            }

            if (null !== $existingEntity) {
                $values = $this->getValue($output, $propertyName);

                if ($this->compareValues($values, $propertyValue)) {
                    continue;
                }
            }

            if (TypeIdentifier::ARRAY->value === $propertyType && $this->isRelationProperty($object, $propertyName)) {
                $this->resetValue($output, $propertyName);

                if (null === $propertyValue || 0 === count($propertyValue)) {
                    continue;
                }

                $propertyClass = $propertyValue[0]::class;
                $targetClass = $_helper->mapFromAttribute($propertyClass, $this->requestCache);
                $collection = new ArrayCollection();

                foreach ($propertyValue as $value) {
                    $rp = $_helper->getReadProperty($propertyClass, $this->requestCache);

                    if (isset($value->{$rp})) {
                        if (null === ($entity = $this->find($targetClass, $value->{$rp}))) {
                            throw new MappingException("$targetClass entity with id $value->$rp not found!");
                        }

                        $collection->add($entity);

                        continue;
                    }

                    $collection->add($this->toEntity($value, $context));
                }

                $this->accessor->setValue($output, $propertyName, $collection);

                continue;
            }

            if ($this->isMappedType($propertyType, MappingType::RESOURCE)) {
                $targetClass = $_helper->mapFromAttribute($propertyType, $this->requestCache);
                $rp = $_helper->getReadProperty($propertyType, $this->requestCache);

                if (null !== $propertyValue?->{$rp}) {
                    if (null === ($entity = $this->find($targetClass, $propertyValue->{$rp}))) {
                        throw new MappingException("$targetClass entity with id $propertyValue->$rp not found!");
                    }

                    $this->accessor->setValue($output, $propertyName, $entity);

                    continue;
                }

                if (null !== $propertyValue) {
                    $this->accessor->setValue($output, $propertyName, $this->toEntity($propertyValue, $context));

                    continue;
                }
            }

            if (null !== $existingEntity || null !== $propertyValue) {
                if (null !== $propertyValue) {
                    $this->modifyValue($reflection, $output, $propertyName, $propertyValue);
                }

                $this->accessor->setValue($output, $propertyName, $propertyValue);
            }
        }

        return $output;
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
                use Iteration\_AddElementIfNotExists;
                use Iteration\_HaveCommonElements;
                use Traits\_GetIgnore;
                use Traits\_GetReadProperty;
                use Traits\_LoadReflection;
                use Traits\_MapFromAttribute;
            };
        }

        if (!array_key_exists('groups', $context)) {
            $context['groups'] = [];
        }

        $context[Context::MAPPER_LEVEL->value] = ($context[Context::MAPPER_LEVEL->value] ?? 0) + 1;

        if (null === $object || null === $this->findById($object::class, $object->getId())) {
            return null;
        }

        $reflection = $_helper->loadReflection($object, $this->requestCache);

        if (999 !== ($found = $this->requestCache->value(Context::ALREADY_NORMALIZED, $reflection->getName(), 999, (string) $object->getId()))) {
            return $found;
        }

        unset($found);

        if (!$this->isMappedType($object, MappingType::ENTITY)) {
            if (!$this->isEntity($object)) {
                throw new InvalidArgumentException(sprintf('%s is not an entity', $object::class));
            }

            return null; // TODO: config to throw or return | ^ ? <
        }

        $_helper->addElementIfNotExists($context[Context::MAPPER_PARENTS->value], $targetResourceClass = $_helper->mapFromAttribute($object, $this->requestCache), $targetResourceClass);

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
                if (1 < $context[Context::MAPPER_LEVEL->value] && !in_array($operation, MapperOperation::OPERATION_GET, true)) {
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

        $this->requestCache->memoize(Context::ALREADY_NORMALIZED, $reflection->getName(), static fn () => $output, false, (string) $object->getId());

        return $output;
    }

    protected function applyFilterExtensionsToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        Operation $operation,
        array $context = [],
    ): array|object {
        foreach ($this->collectionExtensions as $extension) {
            if ($extension instanceof FilterExtension || $extension instanceof QueryResultCollectionExtensionInterface) {
                $extension->applyToCollection($queryBuilder, $queryNameGenerator, $operation->getClass(), $operation, $context);
            }

            if ($extension instanceof OrderExtension) {
                if ([] !== $queryBuilder->getDQLPart('orderBy')) {
                    continue;
                }

                foreach ($operation->getOrder() ?? [] as $field => $direction) {
                    $queryBuilder->addOrderBy(sprintf('%s.%s', $queryBuilder->getRootAliases()[0], $field), $direction);
                }
            }

            if ($extension instanceof QueryResultCollectionExtensionInterface && $extension->supportsResult($operation->getClass(), $operation, $context)) {
                return $extension->getResult($queryBuilder, $operation->getClass(), $operation, $context);
            }
        }

        return $queryBuilder->getQuery()->useQueryCache(true)->getResult();
    }

    /**
     * @throws ReflectionException
     */
    protected function compareValues(
        mixed $value1,
        mixed $value2,
    ): bool {
        if ($value1 === $value2) {
            return true;
        }

        if ($value1 instanceof DateTimeInterface && $value2 instanceof DateTimeInterface) {
            return $value1->getTimestamp() === $value2->getTimestamp();
        }

        if ($value1 instanceof Countable) {
            $value2 ??= [];

            if (0 === $value1->count() && 0 === count($value2)) {
                return true;
            }

            if ($value1 instanceof PersistentCollection && is_array($value2) && $value1->count() === count($value2)) {
                static $_helper = null;

                if (null === $_helper) {
                    $_helper = new class {
                        use Traits\_GetReadProperty;
                        use Traits\_MapFromAttribute;
                    };
                }

                $firstSet = $value1->getValues();
                $secondSet = $value2;
                $rp = $_helper->getReadProperty($secondSet[0], $this->requestCache);

                for ($i = 0, $iMax = count($firstSet); $i < $iMax; $i++) {
                    $classesAreEqual = get_class($firstSet[$i]) === $_helper->mapFromAttribute($secondSet[$i], $this->requestCache);
                    $idsAreEqual = $secondSet[$i]->{$rp} === $this->getValue($firstSet[$i], $rp);

                    if (!$classesAreEqual || !$idsAreEqual) {
                        return false;
                    }
                }

                return true;
            }
        }

        return false;
    }

    /**
     * @throws ORMException
     * @throws ReflectionException
     */
    protected function delete(
        mixed $resource,
        Operation $operation,
    ): null {
        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use Traits\_GetReadProperty;
            };
        }

        $existingEntity = $this->find($this->getEntityClass($operation), $resource->{$_helper->getReadProperty($operation->getClass(), $this->requestCache)});

        $this->entityManager->remove($existingEntity);

        return null;
    }

    protected function flush(
        ?object $entity,
        bool $refresh = true,
    ): void {
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            throw new PreconditionFailedHttpException($exception->getMessage(), $exception);
        } catch (Exception $exception) {
            throw new AccessDeniedHttpException($exception->getMessage(), $exception);
        }

        if ($refresh) {
            try {
                $this->entityManager->refresh($entity);
            } catch (ORMException) {
            }
        }
    }

    protected function getActualObject(
        object $entity,
    ): object {
        return $this->requestCache->memoize(Context::ACTUAL_OBJECT, $entity::class, function () use ($entity) {
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
    protected function getCollection(
        Operation $operation,
        array $context = [],
    ): array|object {
        $queryBuilder = $this->entityManager->createQueryBuilder()->select('m')->from($this->getEntityClass($operation), 'm');

        return $this->applyFilterExtensionsToCollection($queryBuilder, new QueryNameGenerator(), $operation, $context);
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
                use Traits\_MapFromAttribute;
            };
        }

        $class = $_helper->mapFromAttribute($operation->getClass(), $this->requestCache);

        if (null === $class) {
            throw new MappingException(sprintf('Resource class %s does not have a %s attribute', $operation->getClass(), Mapped::class));
        }

        return $class;
    }

    /**
     * @throws ORMException
     * @throws ReflectionException
     * @throws ResourceClassNotFoundException
     */
    protected function getItem(
        Operation $operation,
        mixed $id,
        array $context = [],
    ): object {
        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use Traits\_LoadReflection;
            };
        }

        $entity = $this->find($entityClass = $this->getEntityClass($operation), $id);

        $this->throwErrorIfNotExist($entity, strtolower($_helper->loadReflection($entityClass, $this->requestCache)->getShortName()), $id);

        return $this->toResource($entity, $context);
    }

    /**
     * @throws ReflectionException
     */
    protected function getRelationPropertyClass(
        object $object,
        string $propertyName,
    ): ?string {
        return $this->requestCache->memoize(Context::GET_READ_PROP, $propertyName, fn () => $this->processRelationProperty($object, $propertyName, true));
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

        return in_array($targetClass, $context[Context::MAPPER_PARENTS->value], true) && (($maxLevels > 0 && $context[Context::MAPPER_LEVEL->value] >= $maxLevels) || -1 === $maxLevels);
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
     * @throws ORMException
     * @throws ReflectionException
     */
    protected function patch(
        mixed $resource,
        Operation $operation,
        array $context = [],
    ): ?object {
        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use Traits\_GetReadProperty;
            };
        }

        $existingEntity = $this->find($this->getEntityClass($operation), $resource->{$_helper->getReadProperty($operation->getClass(), $this->requestCache)});

        return $this->toEntity($resource, $context, $existingEntity);
    }

    /**
     * @throws ReflectionException
     * @throws ORMException
     */
    protected function post(
        mixed $resource,
        array $context = [],
    ): ?object {
        $entity = $this->toEntity($resource, $context);
        $this->entityManager->persist($entity);

        return $entity;
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

        if (null !== $type) {
            $nonNullable = $type->asNonNullable();

            if ($nonNullable instanceof CollectionType) {
                $collectionValueType = $nonNullable->getCollectionValueType();

                if ($collectionValueType instanceof ObjectType) {
                    if ($returnType) {
                        $result = $collectionValueType->getClassName();
                    } else {
                        $result = $this->isMappedType($collectionValueType->getClassName(), MappingType::RESOURCE);
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @throws ORMException
     * @throws ReflectionException
     */
    protected function put(
        mixed $resource,
        Operation $operation,
        array $context = [],
    ): ?object {
        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use Traits\_GetReadProperty;
            };
        }

        $existingEntity = $this->find($this->getEntityClass($operation), $resource->{$_helper->getReadProperty($operation->getClass(), $this->requestCache)});

        $entity = $this->toEntity($resource, $context, clone $existingEntity);
        $this->entityManager->persist($entity);

        return $entity;
    }

    protected function resetValue(
        object $object,
        string $property,
    ): void {
        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use Traits\_LoadReflection;
            };
        }

        try {
            $type = $_helper->loadReflection($object, $this->requestCache)->getProperty($property)->getType()?->getName();
        } catch (ReflectionException) {
            return;
        }

        $value = match (true) {
            TypeIdentifier::ARRAY->value === $type => [],
            in_array($type, [Collection::class, ArrayCollection::class], true) => new ArrayCollection(),
            default => null,
        };

        $this->accessor->setValue($object, $property, $value);
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
                use Traits\_GetReadProperty;
                use Traits\_LoadReflection;
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
            && !array_key_exists($propertyValue::class, $context[Context::MAPPER_PARENTS->value])
            && $this->isResource($propertyValue)
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

    protected function throwErrorIfNotExist(
        mixed $result,
        string $rootAlias,
        mixed $id,
    ): void {
        if (null === $result) {
            throw new ItemNotFoundHttpException($rootAlias . ':' . $id);
        }
    }

    protected function validate(
        object $entity,
        Operation $operation,
    ): void {
        if (!$operation instanceof Delete) {
            $errors = $this->validator->validate($entity);

            if ($errors->count() > 0) {
                throw new ValidationException($errors);
            }
        }
    }
}
