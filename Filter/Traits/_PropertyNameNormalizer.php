<?php declare(strict_types = 1);

/*
 * This file is part of the Vairogs package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <davis@vairogs.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vairogs\Component\Mapper\Filter\Traits;

use Symfony\Component\Serializer\NameConverter\NameConverterInterface;

use function array_map;
use function explode;
use function implode;

trait _PropertyNameNormalizer
{
    protected function normalizePropertyName(
        string $property,
    ): string {
        if (!$this->nameConverter instanceof NameConverterInterface) {
            return $property;
        }

        return implode('.', array_map([$this->nameConverter, 'normalize'], explode('.', $property)));
    }
}
