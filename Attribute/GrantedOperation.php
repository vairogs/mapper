<?php declare(strict_types = 1);

namespace Vairogs\Component\Mapper\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class GrantedOperation
{
    public function __construct(public string $role, array $operations = [])
    {
    }
}
