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
use Vairogs\Component\Mapper\Constants\Context;
use Vairogs\Component\Mapper\Service\RequestCache;

trait _MapFromAttribute
{
    /**
     * @throws ReflectionException
     */
    public function mapFromAttribute(
        object|string $objectOrClass,
        RequestCache $requestCache,
        bool $skipGlobal = false,
    ): ?string {
        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use _FindClassesWithAttribute;
                use _MapMapped;
            };
        }

        if (!$skipGlobal) {
            $foundClasses = $requestCache->get(Context::CLASSES_WITH_ATTR, 'key', static fn () => $_helper->findClassesWithAttribute($requestCache));

            foreach ($foundClasses as $item) {
                $_helper->mapMapped($item, $requestCache);
            }
        }

        return $_helper->mapMapped($objectOrClass, $requestCache);
    }
}
