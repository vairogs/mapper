<?php declare(strict_types = 1);

/*
 * This file is part of the Vairogs package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <davis@vairogs.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vairogs\Component\Mapper\Filter\Traits;

use ReflectionException;
use ReflectionUnionType;
use Vairogs\Component\Functions\Iteration\_AddElementIfNotExists;

trait _GetProperties
{
    /**
     * @throws ReflectionException
     */
    public function getProperties(
        string $resourceClass,
    ): array {
        $i = $properties = [];

        $save = (new class {
            use _AddElementIfNotExists;
        });

        foreach ($this->mapper->loadReflection($resourceClass, $i)->getProperties() as $property) {
            $type = $property->getType();
            if ($type instanceof ReflectionUnionType) {
                continue;
            }

            $propertyType = $type?->getName();
            if (null === $propertyType) {
                continue;
            }

            $save->addElementIfNotExists($properties[$propertyType], $property, $property->getName());
        }

        return $properties;
    }
}
