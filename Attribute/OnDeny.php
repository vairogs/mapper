<?php declare(strict_types = 1);

namespace Vairogs\Component\Mapper\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS)]
final readonly class OnDeny
{
    public function __construct(
        public ?array $allowedFields = null,
    ) {
    }
}
