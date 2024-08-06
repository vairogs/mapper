<?php declare(strict_types = 1);

namespace Vairogs\Component\Mapper\Contracts;

use ApiPlatform\Metadata\Exception\ResourceClassNotFoundException;
use Doctrine\ORM\Exception\ORMException;
use ReflectionClass;
use ReflectionException;
use Vairogs\Component\Mapper\Constants\Enum\MappingType;

interface MapperInterface
{
    /**
     * @throws ORMException
     */
    public function find(
        string $class,
        mixed $id,
        array &$context = [],
    ): ?object;

    /**
     * @throws ORMException
     */
    public function findById(
        string $class,
        mixed $id,
    ): ?object;

    public function getReadProperty(
        object|string $class,
        array &$context = [],
    ): string;

    /**
     * @throws ReflectionException
     */
    public function isEntity(
        object|string $object,
        array &$context = [],
    ): bool;

    public function isMapped(
        object|string $object,
        array &$context = [],
    ): bool;

    /**
     * @throws ReflectionException
     */
    public function isMappedType(
        string|object $objectOrClass,
        MappingType $type,
        array &$context = [],
    ): bool;

    /**
     * @throws ReflectionException
     */
    public function isResource(
        object|string $object,
        array &$context = [],
    ): bool;

    /**
     * @throws ReflectionException
     */
    public function loadReflection(
        object|string $objectOrClass,
        array &$context = [],
    ): ReflectionClass;

    public function mapFromAttribute(
        object|string $objectOrClass,
        array &$context = [],
    ): ?string;

    /**
     * @throws ReflectionException
     * @throws ORMException
     */
    public function toEntity(
        object $object,
        array &$context = [],
        ?object $existingEntity = null,
    ): ?object;

    /**
     * @throws ResourceClassNotFoundException
     * @throws ReflectionException
     * @throws ORMException
     */
    public function toResource(
        ?object $object,
        array &$context = [],
    ): ?object;
}
