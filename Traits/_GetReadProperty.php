<?php declare(strict_types = 1);

namespace Vairogs\Component\Mapper\Traits;

use ApiPlatform\Metadata\ApiProperty;
use ReflectionException;
use Vairogs\Component\Functions\Iteration\_AddElementIfNotExists;
use Vairogs\Component\Mapper\Exception\MappingException;
use Vairogs\Component\Mapper\Mapper;

use function array_key_exists;
use function is_object;

trait _GetReadProperty
{
    /**
     * @throws ReflectionException
     */
    public function getReadProperty(
        object|string $class,
        array &$context = [],
    ): string {
        if (is_object($class)) {
            $class = $class::class;
        }

        if (array_key_exists($class, $context[Mapper::VAIROGS_MAPPER_RP] ??= [])) {
            return $context[Mapper::VAIROGS_MAPPER_RP][$class];
        }

        $property = null;
        foreach ((new class {
            use _LoadReflection;
        })->loadReflection($class, $context)->getProperties() as $reflectionProperty) {
            if ([] !== ($attributes = $reflectionProperty->getAttributes(ApiProperty::class))) {
                $prop = $attributes[0]->newInstance();
                if ($prop->isIdentifier()) {
                    $property = $reflectionProperty->getName();
                    break;
                }
            }
        }

        if (null === $property) {
            throw new MappingException("Class $class does not have a read property!");
        }

        (new class {
            use _AddElementIfNotExists;
        })->addElementIfNotExists($context[Mapper::VAIROGS_MAPPER_RP], $property, $class);

        return $property;
    }
}
