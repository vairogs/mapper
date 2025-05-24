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
use Vairogs\Component\Mapper\Constants\MapperContext;
use Vairogs\Functions\Memoize\MemoizeCache;

trait _MapFromAttribute
{
    /**
     * @throws ReflectionException
     */
    public function mapFromAttribute(
        object|string $objectOrClass,
        MemoizeCache $memoize,
        bool $skipGlobal = false,
    ): ?string {
        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use _FindClassesWithAttribute;
                use _MapMapped;
            };
        }

        $mapped = $_helper->mapMapped($objectOrClass, $memoize);

        if (!$skipGlobal && null === $mapped) {
            $foundClasses = $memoize->memoize(MapperContext::CLASSES_WITH_ATTR, 'key', static fn () => $_helper->findClassesWithAttribute($memoize));

            foreach ($foundClasses as $item) {
                $_helper->mapMapped($item, $memoize);
            }
        }

        return $mapped;
    }
}
