<?php declare(strict_types = 1);

namespace Vairogs\Component\Mapper\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Mapped
{
    public function __construct(
        public string $mapsTo,
        public ?string $reverse = null,
    ) {
    }
}
