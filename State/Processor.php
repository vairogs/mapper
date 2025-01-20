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

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Exception\ResourceClassNotFoundException;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use Countable;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\PersistentCollection;
use Error;
use Exception;
use ReflectionException;
use Symfony\Component\DependencyInjection\Attribute\Lazy;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\TypeInfo\TypeIdentifier;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Vairogs\Bundle\ApiPlatform\Constants\MappingType;
use Vairogs\Bundle\ApiPlatform\Functions;
use Vairogs\Bundle\Service\RequestCache;
use Vairogs\Bundle\Traits\_GetReadProperty;
use Vairogs\Bundle\Traits\_LoadReflection;
use Vairogs\Component\DoctrineTools\UTCDateTimeImmutable;
use Vairogs\Component\Mapper\Constants\MapperContext;
use Vairogs\Component\Mapper\Exception\MappingException;
use Vairogs\Component\Mapper\Mercure\Mercure;
use Vairogs\Component\Mapper\Traits\_GetIgnore;
use Vairogs\Component\Mapper\Traits\_MapFromAttribute;

use function array_key_exists;
use function count;
use function get_class;
use function in_array;
use function is_array;
use function is_subclass_of;
use function property_exists;
use function sprintf;

class Processor extends State implements ProcessorInterface
{
    public function __construct(
        #[Lazy]
        protected readonly Mercure $mercure,
        #[Lazy]
        protected readonly ValidatorInterface $validator,
        AuthorizationCheckerInterface $security,
        EntityManagerInterface $entityManager,
        RequestCache $requestCache,
        Functions $functions,
    ) {
        parent::__construct(
            $security,
            $entityManager,
            $requestCache,
            $functions,
        );
    }

    /**
     * @throws ReflectionException
     */
    public function isRelationProperty(
        object $object,
        string $propertyName,
    ): bool {
        return $this->requestCache->memoize(MapperContext::IS_READ_PROP, $propertyName, fn () => (bool) $this->processRelationProperty($object, $propertyName));
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

        if (!$operation instanceof Delete) {
            $this->validate($entity, $operation);
        }

        $this->flush($entity, null !== $entity);

        if (!$operation instanceof Delete) {
            $this->mercure->publishToMercure($entity, $operation);

            return $this->toResource($entity, $context);
        }

        return null;
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
                use _GetIgnore;
                use _GetReadProperty;
                use _LoadReflection;
                use _MapFromAttribute;
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
                        use _GetReadProperty;
                        use _MapFromAttribute;
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
                use _GetReadProperty;
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
                use _GetReadProperty;
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
                use _GetReadProperty;
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
                use _LoadReflection;
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
