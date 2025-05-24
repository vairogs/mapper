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
use Vairogs\Bundle\Traits\_LoadReflection;
use Vairogs\Component\Mapper\Attribute\Mapped;
use Vairogs\Component\Mapper\Constants\MapperContext;
use Vairogs\Functions\Memoize\MemoizeCache;

use function is_object;

trait _MapMapped
{
    /**
     * @throws ReflectionException
     */
    public function mapMapped(
        object|string $class,
        MemoizeCache $memoize,
    ): ?string {
        if (is_object($class)) {
            $class = $class::class;
        }

        return $memoize->memoize(MapperContext::MAP, $class, static function () use ($class, $memoize) {
            static $_helper = null;

            if (null === $_helper) {
                $_helper = new class {
                    use _LoadReflection;
                };
            }

            $reflection = $_helper->loadReflection($class, $memoize);

            if ([] !== ($attributes = $reflection->getAttributes(Mapped::class))) {
                $attribute = $attributes[0]->newInstance();

                if (null !== $attribute->reverse) {
                    $memoize->memoize(MapperContext::MAP, $attribute->mapsTo, static fn () => $attribute->reverse);
                }

                return $attribute->mapsTo;
            }

            return null;
        });
    }
}
