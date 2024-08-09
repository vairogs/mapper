<?php declare(strict_types = 1);

/*
 * This file is part of the Vairogs package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <davis@vairogs.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vairogs\Component\Mapper\Traits;

use Doctrine\Persistence\Proxy;
use ReflectionClass;
use ReflectionException;
use Vairogs\Component\Mapper\Constants\Context;

use function array_key_exists;
use function is_object;

trait _LoadReflection
{
    use _SavedItems;

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

        if (array_key_exists($class, $this->reflections)) {
            return $this->saveItem($context[Context::VAIROGS_M_REF], $this->reflections[$class], $class);
        }

        if (array_key_exists($class, $context[Context::VAIROGS_M_REF] ?? [])) {
            return $this->saveItem($this->reflections, $context[Context::VAIROGS_M_REF][$class], $class);
        }

        $reflection = new ReflectionClass($objectOrClass);

        if ($objectOrClass instanceof Proxy) {
            $reflection = $reflection->getParentClass();
        }

        $reflectionClass = $reflection->getName();

        $this->saveItem($context[Context::VAIROGS_M_REF], $reflection, $class);
        $this->saveItem($context[Context::VAIROGS_M_REF], $reflection, $reflectionClass);
        $this->saveItem($this->reflections, $reflection, $reflectionClass);

        return $this->saveItem($this->reflections, $reflection, $class);
    }
}
