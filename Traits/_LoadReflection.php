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
use Vairogs\Bundle\Service\RequestCache;
use Vairogs\Component\Mapper\Constants\Context;

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

        $reflection = $requestCache->get(Context::VAIROGS_M_REF, $class, static function () use ($objectOrClass) {
            $reflection = new ReflectionClass($objectOrClass);

            if ($objectOrClass instanceof Proxy) {
                $reflection = $reflection->getParentClass();
            }

            return $reflection;
        });

        $requestCache->get(Context::VAIROGS_M_REF, $reflection->getName(), static fn () => $reflection);

        return $reflection;
    }
}
