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

use ReflectionException;
use Vairogs\Bundle\Service\RequestCache;
use Vairogs\Bundle\Traits\_LoadReflection;
use Vairogs\Component\Mapper\Attribute\Mapped;
use Vairogs\Component\Mapper\Constants\MapperContext;

use function is_object;

trait _MapMapped
{
    /**
     * @throws ReflectionException
     */
    public function mapMapped(
        object|string $class,
        RequestCache $requestCache,
    ): ?string {
        if (is_object($class)) {
            $class = $class::class;
        }

        return $requestCache->memoize(MapperContext::MAP, $class, static function () use ($class, $requestCache) {
            static $_helper = null;

            if (null === $_helper) {
                $_helper = new class {
                    use _LoadReflection;
                };
            }

            $reflection = $_helper->loadReflection($class, $requestCache);

            if ([] !== ($attributes = $reflection->getAttributes(Mapped::class))) {
                $attribute = $attributes[0]->newInstance();

                if (null !== $attribute->reverse) {
                    $requestCache->memoize(MapperContext::MAP, $attribute->mapsTo, static fn () => $attribute->reverse);
                }

                return $attribute->mapsTo;
            }

            return null;
        });
    }
}
