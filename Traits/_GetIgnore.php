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

use ReflectionProperty;
use Symfony\Component\Serializer;

use function array_merge;
use function class_exists;

trait _GetIgnore
{
    public function getIgnore(
        ReflectionProperty $property,
    ): array {
        $ignore = [];

        if (class_exists(Serializer\Attribute\Ignore::class)) {
            $ignore[] = $property->getAttributes(Serializer\Attribute\Ignore::class);
        }

        if (class_exists(Serializer\Annotation\Ignore::class)) {
            $ignore[] = $property->getAttributes(Serializer\Annotation\Ignore::class);
        }

        return array_merge(...$ignore);
    }
}
