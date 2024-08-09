<?php declare(strict_types = 1);

/*
 * This file is part of the Vairogs package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <davis@vairogs.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vairogs\Component\Mapper\Attribute;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class GrantedOperation
{
    public const array ALL = [
        Get::class,
        GetCollection::class,
        Post::class,
        Patch::class,
        Put::class,
        Delete::class,
    ];
    public const array GET = [
        Get::class,
        GetCollection::class,
    ];
    public const array EDIT = [
        Post::class,
        Patch::class,
        Put::class,
        Delete::class,
    ];

    public function __construct(
        public string $role,
        public array $operations = [],
    ) {
    }
}
