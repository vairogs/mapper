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

        if ('999' !== ($found = $this->reflections[$class] ?? '999')) {
            return $this->saveItem($context[Context::VAIROGS_M_REF], $found, $class);
        }
        unset($found);

        if ('999' !== ($found = $context[Context::VAIROGS_M_REF][$class] ?? '999')) {
            return $this->saveItem($this->reflections, $found, $class);
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
