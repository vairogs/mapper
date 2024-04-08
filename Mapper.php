<?php declare(strict_types = 1);

namespace Vairogs\Component\Mapper;

use ApiPlatform\Doctrine\Orm\Extension\FilterExtension;
use ApiPlatform\Doctrine\Orm\Extension\OrderExtension;
use ApiPlatform\Doctrine\Orm\Extension\QueryResultCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Exception\ResourceClassNotFoundException;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Symfony\Security\Exception\AccessDeniedException;
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
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\Proxy;
use Error;
use Exception;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Type;
use Symfony\Component\Serializer;
use Symfony\Component\Serializer\Annotation\Ignore;
use Symfony\Contracts\Translation\TranslatorInterface;
use Vairogs\Component\DoctrineTools\UTCDateTimeImmutable;
use Vairogs\Component\Functions\Iteration;
use Vairogs\Component\Mapper\Attribute\Mapped;
use Vairogs\Component\Mapper\Attribute\OnDeny;
use Vairogs\Component\Mapper\Attribute\SkipCircularReference;
use Vairogs\Component\Mapper\Constants\Enum\MappingType;
use Vairogs\Component\Mapper\Exception\MappingException;

use function array_key_exists;
use function array_merge;
use function class_exists;
use function count;
use function get_class;
use function in_array;
use function is_array;
use function is_object;
use function is_subclass_of;
use function property_exists;
use function sprintf;
use function strtolower;

#[Autoconfigure(lazy: true)]
class Mapper implements ProviderInterface, ProcessorInterface
{
    public const string VAIROGS_MAPPER_PARENTS = 'VAIROGS_MAPPER_PARENTS';
    public const string VAIROGS_MAPPER_LEVEL = 'VAIROGS_MAPPER_LEVEL';

    private array $reflections = [];
    private array $map = [];
    private array $allowedFields = [];

    private readonly PropertyAccessor $accessor;
    private readonly PhpDocExtractor $phpDocExtractor;

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly ParameterBagInterface $bag,
        protected readonly ResourceMetadataCollectionFactoryInterface $resourceMetadataFactory,
        protected readonly Security $security,
        protected readonly TranslatorInterface $translator,
        #[TaggedIterator('api_platform.doctrine.orm.query_extension.collection')]
        protected readonly iterable $collectionExtensions = [],
    ) {
        $this->accessor = PropertyAccess::createPropertyAccessor();
        $this->phpDocExtractor = new PhpDocExtractor();
    }

    /**
     * @throws ReflectionException
     * @throws ResourceClassNotFoundException
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array|object|null
    {
        return match (true) {
            $operation instanceof GetCollection => $this->getCollection($operation, $context),
            default => $this->getItem($operation, $uriVariables['id'], $context),
        };
    }

    /**
     * @throws ReflectionException
     * @throws ResourceClassNotFoundException
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?object
    {
        $entity = match (true) {
            $operation instanceof Delete => $this->delete($data, $operation),
            $operation instanceof Patch => $this->patch($data, $operation, $context),
            $operation instanceof Post => $this->post($data, $context),
            $operation instanceof Put => $this->put($data, $operation, $context),
            default => throw new BadRequestHttpException(sprintf('Invalid operation: %s', $operation::class)),
        };

        $this->flush($entity, null !== $entity);

        return $this->toResource($entity, $context);
    }

    /**
     * @throws ResourceClassNotFoundException
     * @throws ReflectionException
     */
    public function toResource(?object $object, array $context = []): ?object
    {
        if (null === $object) {
            return null;
        }

        if (!$this->isMappedType($object, MappingType::ENTITY)) {
            if (!$this->isEntity($object)) {
                throw new InvalidArgumentException(sprintf('%s is not an entity', $object::class));
            }

            return null; // TODO: config to throw or return | ^ ? <
        }

        $context[self::VAIROGS_MAPPER_LEVEL] ??= +1;
        $this->addElementIfNotExists($context[self::VAIROGS_MAPPER_PARENTS], $targetResourceClass = $this->mapFromAttribute($object));

        $operation = $context['operation'] ?? $context['root_operation'] ?? null;
        if (is_object($operation)) {
            $operation = $operation::class;
        }

        $output = new $targetResourceClass();

        $targetResourceReflection = $this->loadReflection($output);

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

        $reflection = $this->loadReflection($object);
        foreach ($reflection->getProperties() as $property) {
            $propertyName = $property->getName();

            $propertyType = $property->getType()?->getName();
            if (null === $propertyType) {
                throw new MappingException(sprintf('Type for property %s on class %s cannot be detected', $propertyName, $reflection->getName()));
            }

            // TODO: if collection, check count before getting value (improves speed if lazy)
            $propertyValue = $this->accessor->getValue($object, $propertyName);

            if (null === $propertyValue || [] !== $property->getAttributes(Ignore::class)) {
                continue;
            }

            if ((class_exists($propertyType) && $this->isEntity($propertyType)) || Collection::class === $propertyType) {
                if (Collection::class === $propertyType) {
                    $this->setResourceProperty($output, $propertyName, []);
                    $targetClass = $this->mapFromAttribute($propertyValue->getTypeClass()->getName());
                } else {
                    $targetClass = $this->mapFromAttribute($propertyType);
                }

                if (null === $targetClass) {
                    continue;
                }

                if (array_key_exists('groups', $context)) {
                    $targetGroups = $this->getNormalizationGroups($targetClass, $context);
                    $propertyGroups = $this->getResourcePropertyNormalizationGroups($targetResourceReflection, $propertyName);
                    if (
                        !(new Iteration())->haveCommonElements($propertyGroups, $context['groups'])
                        && !(new Iteration())->haveCommonElements($targetGroups, $context['groups'])
                    ) {
                        continue;
                    }
                }
                if ($this->isCircularReference($targetClass, $context, $targetResourceReflection, $propertyName)) {
                    continue;
                }

                $contextCleared = $context;
                if (isset($targetGroups)) {
                    $contextCleared = $this->unsetNormalizationGroups($context, $targetGroups);
                }

                if (Collection::class === $propertyType) {
                    foreach ($propertyValue->getValues() as $value) {
                        $this->setResourceProperty($output, $propertyName, $this->toResource($value, $contextCleared), true);
                    }
                    continue;
                }

                $this->setResourceProperty($output, $propertyName, $this->toResource($propertyValue, $contextCleared));
                continue;
            }

            $this->setResourceProperty($output, $propertyName, $propertyValue);
        }

        return $output;
    }

    /**
     * @throws ReflectionException
     */
    public function isEntity(object|string $object): bool
    {
        return [] !== $this->loadReflection($object)->getAttributes(ORM\Entity::class);
    }

    /**
     * @throws ReflectionException
     */
    public function isResource(object|string $object): bool
    {
        return [] !== $this->loadReflection($object)->getAttributes(ApiResource::class);
    }

    /**
     * @throws ReflectionException
     */
    public function isMapped(object|string $object): bool
    {
        return [] !== $this->loadReflection($object)->getAttributes(Mapped::class);
    }

    /**
     * @throws ReflectionException
     */
    public function toEntity(object $object, array $context, ?object $existingEntity = null): object
    {
        $reflection = $this->loadReflection($object);
        $targetEntityClass = $this->mapFromAttribute($reflection->getName());

        $properties = $reflection->getProperties();
        $output = $existingEntity ?? new $targetEntityClass();

        foreach ($properties as $property) {
            $propertyName = $property->getName();
            if (!property_exists($targetEntityClass, $propertyName) || [] !== $property->getAttributes(Ignore::class)) {
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

            if (is_subclass_of($propertyType, DateTimeInterface::class)
                && $propertyValue instanceof DateTimeInterface) {
                $propertyValue = match (true) {
                    $propertyValue instanceof UTCDateTimeImmutable => $propertyValue,
                    default => DateTimeImmutable::createFromInterface($propertyValue),
                };
            }

            $values = $this->accessor->getValue($output, $propertyName);
            if (null !== $existingEntity && $this->compareValues($values, $propertyValue)) {
                continue;
            }

            if (Type::BUILTIN_TYPE_ARRAY === $propertyType && $this->isRelationProperty($object, $propertyName)) {
                $this->resetValue($output, $propertyName);

                if (null === $propertyValue || 0 === count($propertyValue)) {
                    continue;
                }

                $targetClass = $this->mapFromAttribute(get_class($propertyValue[0]));
                $collection = new ArrayCollection();
                foreach ($propertyValue as $value) {
                    if (isset($value->id)) {
                        if (null === ($entity = $this->find($targetClass, $value->id))) {
                            throw new MappingException("$targetClass entity with id $value->id not found!");
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
                $targetClass = $this->mapFromAttribute($propertyType);
                if (isset($propertyValue->id)) {
                    if (null === ($entity = $this->find($targetClass, $propertyValue->id))) {
                        throw new MappingException("$targetClass entity with id $propertyValue->id not found!");
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
                $this->accessor->setValue($output, $propertyName, $propertyValue);
            }
        }

        return $output;
    }

    /**
     * @throws ReflectionException
     */
    public function isMappedType(string|object $objectOrClass, MappingType $type): bool
    {
        try {
            $reflection = $this->loadReflection($objectOrClass);
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

    public function mapFromAttribute(object|string $objectOrClass): ?string
    {
        $class = $objectOrClass;

        if (is_object($objectOrClass)) {
            $class = $objectOrClass::class;
        }

        if (array_key_exists($class, $this->map)) {
            return $this->map[$class];
        }

        try {
            $reflection = $this->loadReflection($objectOrClass);

            if ([] !== $attributes = $reflection->getAttributes(Mapped::class)) {
                if (1 === count($attributes)) {
                    return $this->map[$class] = $attributes[0]->newInstance()->mapsTo;
                }

                throw new MappingException(sprintf('More than 1 map for %s', $reflection->getName()));
            }
        } catch (ReflectionException) {
            return null;
        }

        return null;
    }

    /**
     * @throws ReflectionException
     */
    public function loadReflection(object|string $objectOrClass): ReflectionClass
    {
        $class = $objectOrClass;

        if (is_object($objectOrClass)) {
            $class = $objectOrClass::class;
        }

        if (array_key_exists($class, $this->reflections)) {
            return $this->reflections[$class];
        }

        $reflection = new ReflectionClass($objectOrClass);

        if ($objectOrClass instanceof Proxy) {
            $objectOrClass->__load();
            if (!$objectOrClass->__isInitialized()) {
                throw new MappingException(sprintf('Un-initialized proxy object for %s', $objectOrClass::class));
            }

            $reflection = $reflection->getParentClass();
        }

        return $this->reflections[$class] = $reflection;
    }

    /** @noinspection PhpRedundantCatchClauseInspection */
    protected function flush(?object $entity, bool $refresh = true): void
    {
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

    protected function delete(mixed $resource, Operation $operation): null
    {
        $existingEntity = $this->find($this->getEntityClass($operation), $resource->id);

        $this->entityManager->remove($existingEntity);

        return null;
    }

    /**
     * @throws ReflectionException
     * @throws ResourceClassNotFoundException
     */
    protected function getItem(Operation $operation, mixed $id, array $context): object
    {
        $entity = $this->find($entityClass = $this->getEntityClass($operation), $id);

        $this->throwErrorIfNotExist($entity, strtolower($this->loadReflection($entityClass)->getShortName()), $id);

        return $this->toResource($entity, $context);
    }

    protected function getCollection(Operation $operation, array $context = []): array|object
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()->select('m')->from($this->getEntityClass($operation), 'm');

        return $this->applyFilterExtensionsToCollection($queryBuilder, new QueryNameGenerator(), $operation, $context);
    }

    protected function getEntityClass(Operation $operation): string
    {
        $class = $this->mapFromAttribute($operation->getClass());

        if (null === $class) {
            throw new MappingException(sprintf('Resource class %s does not have a %s attribute', $operation->getClass(), Mapped::class));
        }

        return $class;
    }

    protected function applyFilterExtensionsToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, Operation $operation, array $context = []): array|object
    {
        foreach ($this->collectionExtensions as $extension) {
            if ($extension instanceof FilterExtension || $extension instanceof QueryResultCollectionExtensionInterface) {
                $extension->applyToCollection($queryBuilder, $queryNameGenerator, $operation->getClass(), $operation, $context);
            }

            if ($extension instanceof OrderExtension) {
                $orderByDqlPart = $queryBuilder->getDQLPart('orderBy');
                if ([] !== $orderByDqlPart) {
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

        return $queryBuilder->getQuery()->getResult();
    }

    protected function throwErrorIfNotExist(mixed $result, string $rootAlias, mixed $id): void
    {
        if (null === $result) {
            throw new NotFoundHttpException($rootAlias . ':' . $id);
        }
    }

    /**
     * @throws ReflectionException
     */
    protected function patch(mixed $resource, Operation $operation, array $context = []): ?object
    {
        $existingEntity = $this->find($this->getEntityClass($operation), $resource->id);

        $entity = $this->createEntity($resource, $context, $existingEntity);
        $this->entityManager->persist($entity);

        return $entity;
    }

    /**
     * @throws ReflectionException
     */
    protected function put(mixed $resource, Operation $operation, array $context = []): ?object
    {
        $existingEntity = $this->find($this->getEntityClass($operation), $resource->id);

        $entity = $this->createEntity($resource, $context, clone $existingEntity);
        $this->entityManager->persist($entity);

        return $entity;
    }

    /**
     * @throws ReflectionException
     */
    protected function post(mixed $resource, array $context = []): ?object
    {
        $entity = $this->createEntity($resource, $context);
        $this->entityManager->persist($entity);

        return $entity;
    }

    /**
     * @throws ReflectionException
     */
    protected function createEntity(object $resource, array $context, ?object $existingEntity = null): object
    {
        return $this->toEntity($resource, $context, $existingEntity);
    }

    protected function find(string $class, mixed $id): ?object
    {
        return $this->entityManager->getRepository($class)->find($id);
    }

    private function addElementIfNotExists(?array &$array, mixed $element): void
    {
        if (!in_array($element, $array ??= [], true)) {
            $array[] = $element;
        }
    }

    /**
     * @throws ReflectionException
     */
    private function setResourceProperty(object $resource, string $propertyName, mixed $propertyValue, bool $appendArray = false): void
    {
        if (null === $propertyValue) {
            return;
        }

        if (null !== ($reflection = $this->getRelationPropertyClass($resource, $propertyName))) {
            $ref = $this->loadReflection($resource);
            $allowed = ($ref->getProperty($propertyName)->getAttributes(OnDeny::class)[0] ?? null)?->newInstance()->allowedFields ?? [];
            $this->allowedFields[$reflection] = $allowed;
        }

        if ([] !== $this->allowedFields[$resource::class]) {
            $this->allowedFields[$resource::class] = array_merge($this->allowedFields[$resource::class], ['id']);
            if (!in_array($propertyName, $this->allowedFields[$resource::class], true)) {
                return;
            }
        }

        if ($appendArray) {
            $resource->{$propertyName}[] = $propertyValue;
        } else {
            $resource->{$propertyName} = $propertyValue;
        }
    }

    /**
     * @throws ReflectionException
     */
    private function isCircularReference(string $targetClass, array $context, ReflectionClass $resourceReflection, string $propertyName): bool
    {
        $attributes = $resourceReflection->getProperty($propertyName)->getAttributes(SkipCircularReference::class);
        $maxLevels = null;

        if ([] !== $attributes) {
            $maxLevels = $attributes[0]->newInstance()->maxLevels;
        }

        return in_array($targetClass, $context[self::VAIROGS_MAPPER_PARENTS], true) && (($maxLevels > 0 && $context[self::VAIROGS_MAPPER_LEVEL] >= $maxLevels) || null === $maxLevels);
    }

    /**
     * @throws ReflectionException
     */
    private function getResourcePropertyNormalizationGroups(ReflectionClass $reflection, string $propertyName): array
    {
        $property = $reflection->getProperty($propertyName);
        $groupAttributes = [];

        if (class_exists(Serializer\Annotation\Groups::class)) {
            $groupAttributes = array_merge($groupAttributes, $property->getAttributes(Serializer\Annotation\Groups::class));
        }

        if (class_exists(Serializer\Attribute\Groups::class)) {
            $groupAttributes = array_merge($groupAttributes, $property->getAttributes(Serializer\Attribute\Groups::class));
        }

        if (1 === count($groupAttributes)) {
            return $groupAttributes[0]->getArguments()[0];
        }

        return [];
    }

    /**
     * @throws ResourceClassNotFoundException
     */
    private function getNormalizationGroups(string $resourceClass, array $context = []): array
    {
        $output = $context['groups'] ?? [];

        if (empty($output)) {
            $resourceMetadata = $this->resourceMetadataFactory->create($resourceClass);
            if (null !== $normalizationContext = $resourceMetadata->getOperation()->getNormalizationContext()) {
                $output = array_merge($output, $normalizationContext['groups']);
            }
        }

        return $output;
    }

    private function compareValues(mixed $value1, mixed $value2): bool
    {
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
                $firstSet = $value1->getValues();
                $secondSet = $value2;
                for ($i = 0, $iMax = count($firstSet); $i < $iMax; $i++) {
                    $classesAreEqual = get_class($firstSet[$i]) === $this->mapFromAttribute($secondSet[$i]);
                    $idsAreEqual = $firstSet[$i]->getId() === $secondSet[$i]->id;
                    if (!$classesAreEqual || !$idsAreEqual) {
                        return false;
                    }
                }

                return true;
            }
        }

        return false;
    }

    private function resetValue(object $object, string $property): void
    {
        try {
            $type = $this->loadReflection($object)->getProperty($property)->getType()->getName();
        } catch (ReflectionException) {
            return;
        }

        $value = match (true) {
            Type::BUILTIN_TYPE_ARRAY === $type => [],
            in_array($type, [Collection::class, ArrayCollection::class], true) => new ArrayCollection(),
            default => null,
        };

        $this->accessor->setValue($object, $property, $value);
    }

    /**
     * @throws ReflectionException
     */
    private function processRelationProperty(object $object, string $propertyName, bool $returnType = false): bool|string|null
    {
        $types = $this->phpDocExtractor->getTypes($object::class, $propertyName);

        if (null === $types) {
            return null;
        }

        $type = $types[0];
        if ($type->isCollection()) {
            $collectionValueType = $type->getCollectionValueTypes()[0] ?? null;

            if ($returnType) {
                return $collectionValueType?->getClassName() ?? null;
            }

            return $this->isMappedType($collectionValueType?->getClassName(), MappingType::RESOURCE);
        }

        return null;
    }

    /**
     * @throws ReflectionException
     */
    private function isRelationProperty(object $object, string $propertyName): bool
    {
        return (bool) $this->processRelationProperty($object, $propertyName);
    }

    /**
     * @throws ReflectionException
     */
    private function getRelationPropertyClass(object $object, string $propertyName): ?string
    {
        return $this->processRelationProperty($object, $propertyName, true);
    }

    private function unsetNormalizationGroups(array $context, array $targetNormalizationGroups): array
    {
        $targetGroups = [];
        foreach ($context['groups'] as $group) {
            if (in_array($group, $targetNormalizationGroups, true)) {
                $targetGroups[] = $group;
            }
        }

        $context['groups'] = $targetGroups;

        return $context;
    }
}
