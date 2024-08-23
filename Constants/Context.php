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

final readonly class Context
{
    public const array VAIROGS_M_OP_ALL = [
        Get::class,
        GetCollection::class,
        Post::class,
        Patch::class,
        Put::class,
        Delete::class,
    ];

    public const array VAIROGS_M_OP_GET = [
        Get::class,
        GetCollection::class,
    ];

    public const array VAIROGS_M_OP_EDIT = [
        Post::class,
        Patch::class,
        Put::class,
        Delete::class,
    ];
    public const string VAIROGS_M_ENTITY_NORMALIZER = 'VAIROGS_M_ENTITY_NORMALIZER';

    public const string VAIROGS_M_FILES = 'VAIROGS_M_FILES';
    public const string VAIROGS_M_GET_RP = 'VAIROGS_M_GET_RP';
    public const string VAIROGS_M_IS_RP = 'VAIROGS_M_IS_RP';
    public const string VAIROGS_M_LEVEL = 'VAIROGS_M_LEVEL';
    public const string VAIROGS_M_MAP = 'VAIROGS_M_MAP';
    public const string VAIROGS_M_MCLASSES = 'VAIROGS_M_CLASSES';
    public const string VAIROGS_M_PARENTS = 'VAIROGS_M_PARENTS';
    public const string VAIROGS_M_REF = 'VAIROGS_M_REF';
    public const string VAIROGS_M_RP = 'VAIROGS_M_RP';
}
