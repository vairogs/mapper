<?php declare(strict_types = 1);

namespace Vairogs\Component\Mapper\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class SkipCircularReference
{
    public function __construct(public int $maxLevels = 0)
    {
    }
}
