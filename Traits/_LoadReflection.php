<?php declare(strict_types = 1);

namespace Vairogs\Component\Mapper\Traits;

use Doctrine\Persistence\Proxy;
use ReflectionClass;
use ReflectionException;
use Vairogs\Component\Functions\Iteration\_AddElementIfNotExists;
use Vairogs\Component\Mapper\Exception\MappingException;
use Vairogs\Component\Mapper\Mapper;

use function array_key_exists;
use function is_object;
use function sprintf;

trait _LoadReflection
{
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

        if (array_key_exists($class, $context[Mapper::VAIROGS_MAPPER_REF] ??= [])) {
            return $context[Mapper::VAIROGS_MAPPER_REF][$class];
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

        $addElement = (new class {
            use _AddElementIfNotExists;
        });

        $addElement->addElementIfNotExists($context[Mapper::VAIROGS_MAPPER_REF], $reflection, $reflectionClass);
        $addElement->addElementIfNotExists($context[Mapper::VAIROGS_MAPPER_REF], $reflection, $class);

        return $reflection;
    }
}
