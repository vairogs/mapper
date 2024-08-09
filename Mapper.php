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
use ApiPlatform\Metadata\UrlGeneratorInterface;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\State\SerializerContextBuilderInterface;
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
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Serializer;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\TypeIdentifier;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Vairogs\Component\DoctrineTools\UTCDateTimeImmutable;
use Vairogs\Component\Functions\Iteration;
use Vairogs\Component\Mapper\Attribute\Mapped;
use Vairogs\Component\Mapper\Attribute\Modifier;
use Vairogs\Component\Mapper\Attribute\OnDeny;
use Vairogs\Component\Mapper\Attribute\SimpleApiResource;
use Vairogs\Component\Mapper\Attribute\SkipCircularReference;
use Vairogs\Component\Mapper\Constants\Context;
use Vairogs\Component\Mapper\Constants\Enum\MappingType;
use Vairogs\Component\Mapper\Contracts\MapperInterface;
use Vairogs\Component\Mapper\Exception\MappingException;
use Vairogs\Component\Mapper\Traits\_GetReadProperty;
use Vairogs\Component\Mapper\Traits\_LoadReflection;
use Vairogs\Component\Mapper\Traits\_MapFromAttribute;

use function array_key_exists;
use function array_merge;
use function class_exists;
use function count;
use function get_class;
use function get_object_vars;
use function in_array;
use function is_array;
use function is_object;
use function is_string;
use function is_subclass_of;
use function property_exists;
use function sprintf;
use function strtolower;

#[Autoconfigure(lazy: true)]
class Mapper implements ProviderInterface, ProcessorInterface, MapperInterface
{
    use _GetReadProperty;
    use _LoadReflection;
    use _MapFromAttribute;

    public array $alreadyMapped = [];

    protected array $allowedFields = [];
    protected readonly PropertyAccessor $accessor;
    protected readonly PhpDocExtractor $phpDocExtractor;

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly ResourceMetadataCollectionFactoryInterface $resourceMetadataFactory,
        protected readonly AuthorizationCheckerInterface $security,
        protected readonly TranslatorInterface $translator,
        protected readonly ParameterBagInterface $bag,
        protected readonly UrlGeneratorInterface $urlGenerator,
        protected readonly Serializer\SerializerInterface $serializer,
        protected readonly SerializerContextBuilderInterface $serializerContextBuilder,
        #[AutowireIterator('api_platform.doctrine.orm.query_extension.collection')]
        protected readonly iterable $collectionExtensions = [],
        protected readonly ?HubInterface $hub = null,
    ) {
        $this->accessor = PropertyAccess::createPropertyAccessor();
        $this->phpDocExtractor = new PhpDocExtractor();
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
            $operation instanceof Get,
            $operation instanceof Patch,
            $operation instanceof Put,
            $operation instanceof Post => $this->getItem($operation, $uriVariables[$this->getReadProperty($operation->getClass(), $context)], $context),
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
            $operation instanceof Delete => $this->delete($data, $operation, $context),
            $operation instanceof Patch => $this->patch($data, $operation, $context),
            $operation instanceof Post => $this->post($data, $context),
            $operation instanceof Put => $this->put($data, $operation, $context),
            default => throw new BadRequestHttpException(sprintf('Invalid operation: "%s"', $operation::class)),
        };

        $this->flush($entity, null !== $entity);
        $this->publishToMercure($entity, $operation, $context);

        return $this->toResource($entity, $context);
    }

    /**
     * @throws ResourceClassNotFoundException
     * @throws ReflectionException
     * @throws ORMException
     */
    public function toResource(
        ?object $object,
        array &$context = [],
    ): ?object {
        if (!array_key_exists('groups', $context)) {
            $context['groups'] = [];
        }

        $context[Context::VAIROGS_M_LEVEL] = ($context[Context::VAIROGS_M_LEVEL] ?? 1) + 1;

        if (null === $object || null === $this->findById($object::class, $object->getId())) {
            return null;
        }

        $reflection = $this->loadReflection($object, $context);

        if (
            array_key_exists($reflection->getName(), $this->alreadyMapped)
            && array_key_exists($object->getId(), $this->alreadyMapped[$reflection->getName()])
        ) {
            return $this->alreadyMapped[$reflection->getName()][$object->getId()];
        }

        if (!$this->isMappedType($object, MappingType::ENTITY, $context)) {
            if (!$this->isEntity($object, $context)) {
                throw new InvalidArgumentException(sprintf('%s is not an entity', $object::class));
            }

            return null; // TODO: config to throw or return | ^ ? <
        }

        (new class {
            use Iteration\_AddElementIfNotExists;
        })->addElementIfNotExists($context[Context::VAIROGS_M_PARENTS], $targetResourceClass = $this->mapFromAttribute($object, $context), $targetResourceClass);

        $operation = $context['operation'] ?? $context['root_operation'] ?? ($context['request'] ?? null)?->attributes->get('_api_operation');
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

        foreach ($reflection->getProperties() as $property) {
            $propertyName = $property->getName();

            $propertyType = $property->getType()?->getName();
            if (null === $propertyType) {
                throw new MappingException(sprintf('Type for property %s on class %s cannot be detected', $propertyName, $reflection->getName()));
            }

            $propertyValue = $this->getValue($object, $propertyName);

            $ignore = [];
            if (class_exists(Serializer\Attribute\Ignore::class)) {
                $ignore[] = $property->getAttributes(Serializer\Attribute\Ignore::class);
            }
            if (class_exists(Serializer\Annotation\Ignore::class)) {
                $ignore[] = $property->getAttributes(Serializer\Annotation\Ignore::class);
            }

            $ignore = array_merge(...$ignore);

            $c = null;
            if (null === $propertyValue || [] !== $ignore || (Collection::class === $propertyType && 0 === ($c = $propertyValue->count()))) {
                continue;
            }

            if (Collection::class === $propertyType || (class_exists($propertyType) && $this->isEntity($propertyType, $context))) {
                if (Collection::class === $propertyType) {
                    $this->setResourceProperty($output, $propertyName, [], context: $context);
                    $targetClass = $this->mapFromAttribute($propertyValue->getTypeClass()->getName(), $context);
                } else {
                    $targetClass = $this->mapFromAttribute($propertyType, $context);
                }

                if (null === $targetClass) {
                    continue;
                }

                $commonElements = (new class {
                    use Iteration\_HaveCommonElements;
                });

                if ($this->isCircularReference($targetClass, $context, $targetResourceReflection, $propertyName)) {
                    continue;
                }

                $open = false;
                $att = $this->getApiResource($targetClass, $context)->newInstance();
                $ref = (new ReflectionClass($att))->getProperty('normalizationContext')->getValue($att);
                if ($commonElements->haveCommonElements($context['groups'], $ref['groups'])) {
                    $open = true;
                }

                $resource = new $targetClass();
                if (Collection::class === $propertyType) {
                    if (1 < $context[Context::VAIROGS_M_LEVEL] && !in_array($operation, Context::VAIROGS_M_OP_GET, true)) {
                        continue;
                    }

                    if (!$open) {
                        for ($i = 0; $i < $c; $i++) {
                            $instance = clone $resource;
                            $rp = $this->getReadProperty($resource, $context);
                            $instance->{$rp} = $this->getValue($propertyValue->get($i), $rp);
                            $this->setResourceProperty($output, $propertyName, $instance, true, $context);
                        }
                        continue;
                    }

                    foreach ($propertyValue->getValues() as $value) {
                        $this->setResourceProperty($output, $propertyName, $this->toResource($value, $context), true, $context);
                    }
                    continue;
                }

                if (2 < $context[Context::VAIROGS_M_LEVEL] && !in_array($operation, Context::VAIROGS_M_OP_GET, true)) {
                    continue;
                }

                if (!$open) {
                    $instance = clone $resource;
                    $rp = $this->getReadProperty($resource, $context);
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

        $addElement = (new class {
            use Iteration\_AddElementIfNotExists;
        });

        $addElement->addElementIfNotExists($this->alreadyMapped[$reflection->getName()], $output, $object->getId());

        return $output;
    }

    public function modifyValue(ReflectionClass $reflection, object $output, string $propertyName, mixed &$propertyValue): void
    {
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
     * @throws ReflectionException
     */
    public function getApiResource(
        object|string $object,
        array &$context = [],
    ): ReflectionAttribute {
        $reflection = $this->loadReflection($object, $context);

        if ([] !== ($ea = $reflection->getAttributes(SimpleApiResource::class))) {
            return $ea[0];
        }

        return $reflection->getAttributes(ApiResource::class)[0];
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
        $reflection = $this->loadReflection($object, $context);

        return [] !== $reflection->getAttributes(ApiResource::class) || [] !== $reflection->getAttributes(SimpleApiResource::class);
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
    ): ?object {
        if (!array_key_exists('groups', $context)) {
            $context['groups'] = [];
        }

        $reflection = $this->loadReflection($object, $context);
        $targetEntityClass = $this->mapFromAttribute($reflection->getName(), $context);

        $properties = $reflection->getProperties();
        $output = $existingEntity ?? new $targetEntityClass();

        foreach ($properties as $property) {
            $propertyName = $property->getName();

            $ignore = [];
            if (class_exists(Serializer\Attribute\Ignore::class)) {
                $ignore[] = $property->getAttributes(Serializer\Attribute\Ignore::class);
            }
            if (class_exists(Serializer\Annotation\Ignore::class)) {
                $ignore[] = $property->getAttributes(Serializer\Annotation\Ignore::class);
            }

            $ignore = array_merge(...$ignore);

            if (!property_exists($targetEntityClass, $propertyName) || [] !== $ignore) {
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
                if ($this->compareValues($values, $propertyValue, $context)) {
                    continue;
                }
            }

            if (TypeIdentifier::ARRAY->value === $propertyType && $this->isRelationProperty($object, $propertyName, $context)) {
                $this->resetValue($output, $propertyName, $context);

                if (null === $propertyValue || 0 === count($propertyValue)) {
                    continue;
                }

                $propertyClass = $propertyValue[0]::class;
                $targetClass = $this->mapFromAttribute($propertyClass, $context);
                $collection = new ArrayCollection();
                foreach ($propertyValue as $value) {
                    $rp = $this->getReadProperty($propertyClass, $context);
                    if (isset($value->{$rp})) {
                        if (null === ($entity = $this->find($targetClass, $value->{$rp}, $context))) {
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

            if ($this->isMappedType($propertyType, MappingType::RESOURCE, $context)) {
                $targetClass = $this->mapFromAttribute($propertyType, $context);
                $rp = $this->getReadProperty($propertyType, $context);
                if (null !== $propertyValue->{$rp}) {
                    if (null === ($entity = $this->find($targetClass, $propertyValue->{$rp}, $context))) {
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

    /**
     * @throws ORMException
     * @throws ReflectionException
     */
    public function find(
        string $class,
        mixed $id,
        array &$context = [],
        bool $load = false,
    ): ?object {
        $count = ($repository = $this->entityManager->getRepository($class))->count([$property = $this->getReadProperty($this->mapFromAttribute($class), $context) => $id]);
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

    public function getValue(
        object $object,
        string $property,
    ): mixed {
        if ('id' === $property) {
            return $object->getId();
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
     * @throws ReflectionException
     */
    protected function delete(
        mixed $resource,
        Operation $operation,
        array &$context = [],
    ): null {
        $existingEntity = $this->find($this->getEntityClass($operation), $resource->{$this->getReadProperty($operation->getClass(), $context)}, $context);

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
        $entity = $this->find($entityClass = $this->getEntityClass($operation), $id, $context);

        $this->throwErrorIfNotExist($entity, strtolower($this->loadReflection($entityClass, $context)->getShortName()), $id);

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

        return $queryBuilder->getQuery()->useQueryCache(true)->getResult();
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
        $existingEntity = $this->find($this->getEntityClass($operation), $resource->{$this->getReadProperty($operation->getClass(), $context)}, $context);

        return $this->toEntity($resource, $context, $existingEntity);
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
        $existingEntity = $this->find($this->getEntityClass($operation), $resource->{$this->getReadProperty($operation->getClass(), $context)}, $context);

        $entity = $this->toEntity($resource, $context, clone $existingEntity);
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
        $entity = $this->toEntity($resource, $context);
        $this->entityManager->persist($entity);

        return $entity;
    }

    protected function getActualObject(object $entity): object
    {
        if ($entity instanceof Proxy) {
            $this->entityManager->getUnitOfWork()->initializeObject($entity);

            foreach (get_object_vars($entity) as $property => $value) {
                $entity->{$property};
            }

            $this->entityManager->detach($entity);

            return $this->entityManager->getRepository($entity::class)->find($entity->getId());
        }

        return $entity;
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
    protected function setResourceProperty(
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

        if ([] !== $this->allowedFields[$resource::class] && !in_array($this->getReadProperty($resource, $context), $this->allowedFields[$resource::class] ?? [], true)) {
            $this->allowedFields[$resource::class][] = $this->getReadProperty($resource, $context);
        }

        if (!$granted && is_object($propertyValue) && $this->isResource($propertyValue, $context) && !array_key_exists($propertyValue::class, $context[Context::VAIROGS_M_PARENTS]) && !in_array($propertyName, $this->allowedFields[$resource::class], true)) {
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

        return in_array($targetClass, $context[Context::VAIROGS_M_PARENTS], true) && (($maxLevels > 0 && $context[Context::VAIROGS_M_LEVEL] >= $maxLevels) || -1 === $maxLevels);
    }

    /**
     * @throws ReflectionException
     */
    protected function getResourcePropertyNormalizationGroups(
        ReflectionClass $reflection,
        string $propertyName,
    ): array {
        $property = $reflection->getProperty($propertyName);
        $groupAttributes = [];

        if (class_exists(Serializer\Annotation\Groups::class)) {
            $groupAttributes[] = $property->getAttributes(Serializer\Annotation\Groups::class);
        }

        if (class_exists(Serializer\Attribute\Groups::class)) {
            $groupAttributes[] = $property->getAttributes(Serializer\Attribute\Groups::class);
        }

        $groupAttributes = array_merge(...$groupAttributes);

        if (1 === count($groupAttributes)) {
            return $groupAttributes[0]->getArguments()[0];
        }

        return [];
    }

    /**
     * @throws ReflectionException
     */
    protected function compareValues(
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
                $rp = $this->getReadProperty($secondSet[0], $context);
                for ($i = 0, $iMax = count($firstSet); $i < $iMax; $i++) {
                    $classesAreEqual = get_class($firstSet[$i]) === $this->mapFromAttribute($secondSet[$i], $context);
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

    protected function resetValue(
        object $object,
        string $property,
        array &$context = [],
    ): void {
        try {
            $type = $this->loadReflection($object, $context)->getProperty($property)->getType()?->getName();
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
    protected function processRelationProperty(
        object $object,
        string $propertyName,
        bool $returnType = false,
        array &$context = [],
    ): bool|string|null {
        $type = $this->phpDocExtractor->getType($object::class, $propertyName);

        if (null === $type) {
            return null;
        }

        $nonNullable = $type->asNonNullable();
        if ($nonNullable instanceof CollectionType) {
            $collectionValueType = $nonNullable->getCollectionValueType();

            if ($collectionValueType instanceof ObjectType) {
                if ($returnType) {
                    return $collectionValueType->getClassName();
                }

                return $this->isMappedType($collectionValueType->getClassName(), MappingType::RESOURCE, $context);
            }
        }

        return null;
    }

    /**
     * @throws ReflectionException
     */
    protected function isRelationProperty(
        object $object,
        string $propertyName,
        array &$context = [],
    ): bool {
        return (bool) $this->processRelationProperty($object, $propertyName, context: $context);
    }

    /**
     * @throws ReflectionException
     */
    protected function getRelationPropertyClass(
        object $object,
        string $propertyName,
        array &$context = [],
    ): ?string {
        return $this->processRelationProperty($object, $propertyName, true, $context);
    }

    /**
     * @throws ReflectionException
     */
    protected function publishToMercure(object $entity, Operation $operation, array &$context = []): void
    {
        if ($this->hub instanceof HubInterface) {
            $topic = sprintf('%s/api/%s/%s',
                $this->urlGenerator->generate('api_entrypoint', [], UrlGeneratorInterface::ABS_URL),
                $this->mapFromAttribute($entity, $context),
                $entity->getId(),
            );

            $resource = $this->mapFromAttribute($entity, $context);

            $context = [
                'operation' => $operation,
                'resource_class' => $operation->getClass(),
                'item_operation_name' => $operation->getName(),
                'groups' => [$this->loadReflection($resource, $context)->getConstant('READ')],
            ];

            $data = $this->serializer->serialize($entity, 'jsonld', $context);

            $update = new Update($topic, $data);

            $this->hub->publish($update);
        }
    }
}
