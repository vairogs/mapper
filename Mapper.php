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
use Symfony\Component\Serializer;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\TypeIdentifier;
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
    public const string VAIROGS_MAPPER_REF = 'VAIROGS_MAPPER_REF';
    public const string VAIROGS_MAPPER_MAP = 'VAIROGS_MAPPER_MAP';

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

        foreach ($this->bag->get('vairogs.mapper.mapping') as $entry) {
            $this->map[$entry['entity']] = $entry['resource'];
        }
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
        return match (true) {
            $operation instanceof GetCollection => $this->getCollection($operation, $context),
            $operation instanceof Get => $this->getItem($operation, $uriVariables['id'], $context),
            default => throw new BadRequestHttpException(sprintf('Invalid operation: "%s"', $operation::class)),
        };
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
        $entity = match (true) {
            $operation instanceof Delete => $this->delete($data, $operation),
            $operation instanceof Patch => $this->patch($data, $operation, $context),
            $operation instanceof Post => $this->post($data, $context),
            $operation instanceof Put => $this->put($data, $operation, $context),
            default => throw new BadRequestHttpException(sprintf('Invalid operation: "%s"', $operation::class)),
        };

        $this->flush($entity, null !== $entity);

        return $this->toResource($entity, $context);
    }

    /**
     * @throws ResourceClassNotFoundException
     * @throws ReflectionException
     */
    public function toResource(
        ?object $object,
        array &$context = [],
    ): ?object {
        if (null === $object) {
            return null;
        }

        if (!$this->isMappedType($object, MappingType::ENTITY, $context)) {
            if (!$this->isEntity($object, $context)) {
                throw new InvalidArgumentException(sprintf('%s is not an entity', $object::class));
            }

            return null; // TODO: config to throw or return | ^ ? <
        }

        $context[self::VAIROGS_MAPPER_LEVEL] ??= +1;
        (new class() {
            use Iteration\_AddElementIfNotExists;
        })->addElementIfNotExists($context[self::VAIROGS_MAPPER_PARENTS], $targetResourceClass = $this->mapFromAttribute($object, $context), $targetResourceClass);

        $operation = $context['request']->attributes->get('_api_operation');
        if (is_object($operation)) {
            $operation = $operation::class;
        }

        $output = new $targetResourceClass();

        $targetResourceReflection = $this->loadReflection($output, $context);

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

        $reflection = $this->loadReflection($object, $context);
        foreach ($reflection->getProperties() as $property) {
            $propertyName = $property->getName();

            $propertyType = $property->getType()?->getName();
            if (null === $propertyType) {
                throw new MappingException(sprintf('Type for property %s on class %s cannot be detected', $propertyName, $reflection->getName()));
            }

            $propertyValue = $this->accessor->getValue($object, $propertyName);

            $ignore = [];
            if (class_exists(Serializer\Attribute\Ignore::class)) {
                $ignore = array_merge($ignore, $property->getAttributes(Serializer\Attribute\Ignore::class));
            }
            if (class_exists(Serializer\Annotation\Ignore::class)) {
                $ignore = array_merge($ignore, $property->getAttributes(Serializer\Annotation\Ignore::class));
            }

            $c = null;
            if (null === $propertyValue || [] !== $ignore || (Collection::class === $propertyType && 0 === ($c = $propertyValue->count()))) {
                continue;
            }

            if ((class_exists($propertyType) && $this->isEntity($propertyType, $context)) || Collection::class === $propertyType) {
                if (Collection::class === $propertyType) {
                    $this->setResourceProperty($output, $propertyName, [], context: $context);
                    $targetClass = $this->mapFromAttribute($propertyValue->getTypeClass()->getName(), $context);
                } else {
                    $targetClass = $this->mapFromAttribute($propertyType, $context);
                }

                if (null === $targetClass) {
                    continue;
                }

                $commonElements = (new class() {
                    use Iteration\_HaveCommonElements;
                });

                if (array_key_exists('groups', $context)) {
                    $targetGroups = $this->getNormalizationGroups($targetClass, $context);
                    $propertyGroups = $this->getResourcePropertyNormalizationGroups($targetResourceReflection, $propertyName);
                    if (
                        !$commonElements->haveCommonElements($propertyGroups, $context['groups'])
                        && !$commonElements->haveCommonElements($targetGroups, $context['groups'])
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

                $open = false;
                $att = $this->loadReflection($targetClass, $context)->getAttributes(ApiResource::class)[0]->newInstance();
                $ref = (new ReflectionClass($att))->getProperty('normalizationContext')->getValue($att);
                if ($commonElements->haveCommonElements($contextCleared['groups'], $ref['groups'])) {
                    $open = true;
                }

                $resource = new $targetClass();
                if (Collection::class === $propertyType) {
                    if (!$open) {
                        for ($i = 0; $i < $c; $i++) {
                            $instance = clone $resource;
                            $instance->id = $propertyValue->get($i)->getId();
                            $this->setResourceProperty($output, $propertyName, $instance, true, $contextCleared);
                        }
                        continue;
                    }

                    foreach ($propertyValue->getValues() as $value) {
                        $this->setResourceProperty($output, $propertyName, $this->toResource($value, $contextCleared), true, $contextCleared);
                    }
                    continue;
                }

                if (!$open) {
                    $instance = clone $resource;
                    $instance->id = $propertyValue->getId();
                    $this->setResourceProperty($output, $propertyName, $instance, context: $contextCleared);
                } else {
                    $this->setResourceProperty($output, $propertyName, $this->toResource($propertyValue, $contextCleared), context: $contextCleared);
                }
                continue;
            }

            $this->setResourceProperty($output, $propertyName, $propertyValue, context: $context);
        }

        return $output;
    }

    /**
     * @throws ReflectionException
     */
    public function isEntity(
        object|string $object,
        array &$context = [],
    ): bool {
        return [] !== $this->loadReflection($object, $context)->getAttributes(ORM\Entity::class);
    }

    /**
     * @throws ReflectionException
     */
    public function isResource(
        object|string $object,
        array &$context = [],
    ): bool {
        return [] !== $this->loadReflection($object, $context)->getAttributes(ApiResource::class);
    }

    public function isMapped(
        object|string $object,
        array &$context = [],
    ): bool {
        return null !== $this->mapFromAttribute($object, $context);
    }

    /**
     * @throws ReflectionException
     * @throws ORMException
     */
    public function toEntity(
        object $object,
        array &$context = [],
        ?object $existingEntity = null,
    ): object {
        $reflection = $this->loadReflection($object);
        $targetEntityClass = $this->mapFromAttribute($reflection->getName(), $context);

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
            if (null !== $existingEntity && $this->compareValues($values, $propertyValue, $context)) {
                continue;
            }

            if (TypeIdentifier::ARRAY->value === $propertyType && $this->isRelationProperty($object, $propertyName, $context)) {
                $this->resetValue($output, $propertyName, $context);

                if (null === $propertyValue || 0 === count($propertyValue)) {
                    continue;
                }

                $targetClass = $this->mapFromAttribute(get_class($propertyValue[0]), $context);
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

            if ($this->isMappedType($propertyType, MappingType::RESOURCE, $context)) {
                $targetClass = $this->mapFromAttribute($propertyType, $context);
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
    public function isMappedType(
        string|object $objectOrClass,
        MappingType $type,
        array &$context = [],
    ): bool {
        try {
            $reflection = $this->loadReflection($objectOrClass, $context);
        } catch (ReflectionException) {
            return false;
        }

        do {
            if ($this->isMapped($reflection->getName(), $context) && match ($type) {
                MappingType::RESOURCE => $this->isResource($reflection->getName(), $context),
                MappingType::ENTITY => $this->isEntity($reflection->getName(), $context),
            }) {
                return true;
            }
        } while ($reflection = $reflection->getParentClass());

        return false;
    }

    public function mapFromAttribute(
        object|string $objectOrClass,
        array &$context = [],
    ): ?string {
        $class = $objectOrClass;

        if (is_object($objectOrClass)) {
            $class = $objectOrClass::class;
        }

        $addElement = (new class() {
            use Iteration\_AddElementIfNotExists;
        });

        if (array_key_exists($class, $context[self::VAIROGS_MAPPER_MAP] ??= [])) {
            $addElement->addElementIfNotExists($this->map, $context[self::VAIROGS_MAPPER_MAP][$class], $class);

            return $context[self::VAIROGS_MAPPER_MAP][$class];
        }

        if (array_key_exists($class, $this->map)) {
            $addElement->addElementIfNotExists($context[self::VAIROGS_MAPPER_MAP], $this->map[$class], $class);

            return $this->map[$class];
        }

        try {
            $reflection = $this->loadReflection($objectOrClass, $context);

            if ([] !== $attributes = $reflection->getAttributes(Mapped::class)) {
                if (1 === count($attributes)) {
                    $mapsTo = $attributes[0]->newInstance()->mapsTo;

                    $addElement->addElementIfNotExists($context[self::VAIROGS_MAPPER_MAP], $mapsTo, $class);
                    $addElement->addElementIfNotExists($this->map, $mapsTo, $class);

                    return $context[self::VAIROGS_MAPPER_MAP][$class];
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
    public function loadReflection(
        object|string $objectOrClass,
        array &$context = [],
    ): ReflectionClass {
        $class = $objectOrClass;

        if (is_object($objectOrClass)) {
            $class = $objectOrClass::class;
        }

        $addElement = (new class() {
            use Iteration\_AddElementIfNotExists;
        });

        if (array_key_exists($class, $context[self::VAIROGS_MAPPER_REF] ??= [])) {
            $addElement->addElementIfNotExists($this->reflections, $context[self::VAIROGS_MAPPER_REF][$class], $class);

            return $context[self::VAIROGS_MAPPER_REF][$class];
        }

        if (array_key_exists($class, $this->reflections)) {
            $addElement->addElementIfNotExists($context[self::VAIROGS_MAPPER_REF], $this->reflections[$class], $class);

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

        $reflectionClass = $reflection->getName();

        $addElement->addElementIfNotExists($context[self::VAIROGS_MAPPER_REF], $reflection, $reflectionClass);
        $addElement->addElementIfNotExists($context[self::VAIROGS_MAPPER_REF], $reflection, $class);

        $addElement->addElementIfNotExists($this->reflections, $reflection, $reflectionClass);
        $addElement->addElementIfNotExists($this->reflections, $reflection, $class);

        return $reflection;
    }

    /** @noinspection PhpRedundantCatchClauseInspection */
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

    /**
     * @throws ORMException
     */
    protected function delete(
        mixed $resource,
        Operation $operation,
    ): null {
        $existingEntity = $this->find($this->getEntityClass($operation), $resource->id);

        $this->entityManager->remove($existingEntity);

        return null;
    }

    /**
     * @throws ORMException
     * @throws ReflectionException
     * @throws ResourceClassNotFoundException
     */
    protected function getItem(
        Operation $operation,
        mixed $id,
        array &$context = [],
    ): object {
        $entity = $this->find($entityClass = $this->getEntityClass($operation), $id);

        $this->throwErrorIfNotExist($entity, strtolower($this->loadReflection($entityClass)->getShortName()), $id);

        return $this->toResource($entity, $context);
    }

    protected function getCollection(
        Operation $operation,
        array $context = [],
    ): array|object {
        $queryBuilder = $this->entityManager->createQueryBuilder()->select('m')->from($this->getEntityClass($operation), 'm');

        return $this->applyFilterExtensionsToCollection($queryBuilder, new QueryNameGenerator(), $operation, $context);
    }

    protected function getEntityClass(
        Operation $operation,
        array &$context = [],
    ): string {
        $class = $this->mapFromAttribute($operation->getClass(), $context);

        if (null === $class) {
            throw new MappingException(sprintf('Resource class %s does not have a %s attribute', $operation->getClass(), Mapped::class));
        }

        return $class;
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

        return $queryBuilder->getQuery()->getResult();
    }

    protected function throwErrorIfNotExist(
        mixed $result,
        string $rootAlias,
        mixed $id,
    ): void {
        if (null === $result) {
            throw new NotFoundHttpException($rootAlias . ':' . $id);
        }
    }

    /**
     * @throws ORMException
     * @throws ReflectionException
     */
    protected function patch(
        mixed $resource,
        Operation $operation,
        array &$context = [],
    ): ?object {
        $existingEntity = $this->find($this->getEntityClass($operation), $resource->id);

        $entity = $this->createEntity($resource, $context, $existingEntity);
        $this->entityManager->persist($entity);

        return $entity;
    }

    /**
     * @throws ORMException
     * @throws ReflectionException
     */
    protected function put(
        mixed $resource,
        Operation $operation,
        array &$context = [],
    ): ?object {
        $existingEntity = $this->find($this->getEntityClass($operation), $resource->id);

        $entity = $this->createEntity($resource, $context, clone $existingEntity);
        $this->entityManager->persist($entity);

        return $entity;
    }

    /**
     * @throws ReflectionException
     * @throws ORMException
     */
    protected function post(
        mixed $resource,
        array &$context = [],
    ): ?object {
        $entity = $this->createEntity($resource, $context);
        $this->entityManager->persist($entity);

        return $entity;
    }

    /**
     * @throws ReflectionException
     * @throws ORMException
     */
    protected function createEntity(
        object $resource,
        array &$context,
        ?object $existingEntity = null,
    ): object {
        return $this->toEntity($resource, $context, $existingEntity);
    }

    /**
     * @throws ORMException
     */
    protected function find(
        string $class,
        mixed $id,
    ): ?object {
        return $this->entityManager->getReference($class, $id);
    }

    /**
     * @throws ReflectionException
     */
    private function setResourceProperty(
        object $resource,
        string $propertyName,
        mixed $propertyValue,
        bool $appendArray = false,
        array &$context = [],
    ): void {
        if (null === $propertyValue) {
            return;
        }

        $granted = true;
        if (null !== ($reflection = $this->getRelationPropertyClass($resource, $propertyName, $context))) {
            $ref = $this->loadReflection($resource, $context);

            $operation = $context['request']->attributes->get('_api_operation');
            if (is_object($operation)) {
                $operation = $operation::class;
            }

            if (!$this->security->isGranted($operation, $reflection)) {
                $allowed = ($ref->getProperty($propertyName)->getAttributes(OnDeny::class)[0] ?? null)?->newInstance()->allowedFields ?? [];
                $this->allowedFields[$reflection] = $allowed;
                $granted = false;
            }
        }

        if ([] !== $this->allowedFields[$resource::class]) {
            $this->allowedFields[$resource::class] = array_merge($this->allowedFields[$resource::class], ['id']);
        }

        if (!$granted && is_object($propertyValue) && $this->isResource($propertyValue, $context)) {
            if (!array_key_exists($propertyValue::class, $context[self::VAIROGS_MAPPER_PARENTS])) {
                if (!in_array($propertyName, $this->allowedFields[$resource::class], true)) {
                    return;
                }
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
    private function isCircularReference(
        string $targetClass,
        array $context,
        ReflectionClass $resourceReflection,
        string $propertyName,
    ): bool {
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
    private function getResourcePropertyNormalizationGroups(
        ReflectionClass $reflection,
        string $propertyName,
    ): array {
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
    private function getNormalizationGroups(
        string $resourceClass,
        array $context = [],
    ): array {
        $output = $context['groups'] ?? [];

        if (empty($output)) {
            $resourceMetadata = $this->resourceMetadataFactory->create($resourceClass);
            if (null !== $normalizationContext = $resourceMetadata->getOperation()->getNormalizationContext()) {
                $output = array_merge($output, $normalizationContext['groups']);
            }
        }

        return $output;
    }

    private function compareValues(
        mixed $value1,
        mixed $value2,
        array &$context = [],
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
                $firstSet = $value1->getValues();
                $secondSet = $value2;
                for ($i = 0, $iMax = count($firstSet); $i < $iMax; $i++) {
                    $classesAreEqual = get_class($firstSet[$i]) === $this->mapFromAttribute($secondSet[$i], $context);
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

    private function resetValue(
        object $object,
        string $property,
        array &$context = [],
    ): void {
        try {
            $type = $this->loadReflection($object, $context)->getProperty($property)->getType()->getName();
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
    private function processRelationProperty(
        object $object,
        string $propertyName,
        bool $returnType = false,
        array &$context = [],
    ): bool|string|null {
        $type = $this->phpDocExtractor->getType($object::class, $propertyName);

        if (null === $type) {
            return null;
        }

        if ($type->asNonNullable() instanceof CollectionType) {
            /** @noinspection PhpPossiblePolymorphicInvocationInspection */
            $collectionValueType = $type->asNonNullable()->getCollectionValueType();

            if ($returnType) {
                return $collectionValueType->getClassName() ?? null;
            }

            return $this->isMappedType($collectionValueType->getClassName(), MappingType::RESOURCE, $context);
        }

        return null;
    }

    /**
     * @throws ReflectionException
     */
    private function isRelationProperty(
        object $object,
        string $propertyName,
        array &$context = [],
    ): bool {
        return (bool) $this->processRelationProperty($object, $propertyName, context: $context);
    }

    /**
     * @throws ReflectionException
     */
    private function getRelationPropertyClass(
        object $object,
        string $propertyName,
        array &$context = [],
    ): ?string {
        return $this->processRelationProperty($object, $propertyName, true, $context);
    }

    private function unsetNormalizationGroups(
        array &$context,
        array $targetNormalizationGroups,
    ): array {
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
