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

use ReflectionClass;
use ReflectionException;
use Vairogs\Bundle\Constants\Context;
use Vairogs\Bundle\Service\RequestCache;
use Vairogs\Component\Functions\Php\_GetReflection;

use function is_object;

trait _LoadReflection
{
    /**
     * @throws ReflectionException
     */
    public function loadReflection(
        object|string $objectOrClass,
        RequestCache $requestCache,
    ): ReflectionClass {
        $class = $objectOrClass;

        if (is_object($objectOrClass)) {
            $class = $objectOrClass::class;
        }

        $reflection = $requestCache->get(Context::REFLECTION, $class, static function () use ($objectOrClass) {
            static $_helper = null;
            if (null === $_helper) {
                $_helper = (new class {
                    use _GetReflection;
                });
            }

            return $_helper->getReflection($objectOrClass);
        });

        $requestCache->get(Context::REFLECTION, $reflection->getName(), static fn () => $reflection);

        return $reflection;
    }
}
