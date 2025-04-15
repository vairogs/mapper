<?php declare(strict_types = 1);

/*
 * This file is part of the Vairogs package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <davis@vairogs.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vairogs\Component\Mapper\Constants;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;

final readonly class MapperOperation
{
    public const array OPERATION_ALL = [
        Get::class,
        GetCollection::class,
        Post::class,
        Patch::class,
        Put::class,
        Delete::class,
    ];

    public const array OPERATION_EDIT = [
        Post::class,
        Patch::class,
        Put::class,
        Delete::class,
    ];

    public const array OPERATION_GET = [
        Get::class,
        GetCollection::class,
    ];
}
