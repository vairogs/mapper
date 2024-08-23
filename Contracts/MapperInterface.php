<?php declare(strict_types = 1);

/*
 * This file is part of the Vairogs package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <davis@vairogs.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vairogs\Component\Mapper\Contracts;

use ApiPlatform\Metadata\Exception\ResourceClassNotFoundException;
use Doctrine\ORM\Exception\ORMException;
use ReflectionClass;
use ReflectionException;
use Vairogs\Bundle\Service\RequestCache;
use Vairogs\Component\Mapper\Constants\Enum\MappingType;

interface MapperInterface
{
    /**
     * @throws ORMException
     */
    public function find(
        string $class,
        mixed $id,
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
        RequestCache $requestCache,
    ): string;

    /**
     * @throws ReflectionException
     */
    public function isEntity(
        object|string $object,
    ): bool;

    public function isMapped(
        object|string $object,
    ): bool;

    /**
     * @throws ReflectionException
     */
    public function isMappedType(
        string|object $objectOrClass,
        MappingType $type,
    ): bool;

    /**
     * @throws ReflectionException
     */
    public function isResource(
        object|string $object,
    ): bool;

    /**
     * @throws ReflectionException
     */
    public function loadReflection(
        object|string $objectOrClass,
        RequestCache $requestCache,
    ): ReflectionClass;

    public function mapFromAttribute(
        object|string $objectOrClass,
        RequestCache $requestCache,
        bool $skipGlobal = false,
    ): ?string;

    /**
     * @throws ReflectionException
     * @throws ORMException
     */
    public function toEntity(
        object $object,
        array $context = [],
        ?object $existingEntity = null,
    ): ?object;

    /**
     * @throws ResourceClassNotFoundException
     * @throws ReflectionException
     * @throws ORMException
     */
    public function toResource(
        ?object $object,
        array $context = [],
    ): ?object;
}
