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

use Vairogs\Component\Functions\Iteration\_AddElementIfNotExists;

trait _SavedItems
{
    public array $allowedOperation = [];
    public array $allowedRole = [];
    public array $files = [];
    public array $map = [];
    public ?array $mappedClasses = null;
    public array $reflections = [];
    public array $relations = [];
    public array $rps = [];
    public array $supportOperation = [];
    public array $supportRole = [];

    public function saveItem(
        ?array &$array,
        mixed $element,
        mixed $key = null,
    ): mixed {
        (new class {
            use _AddElementIfNotExists;
        })->addElementIfNotExists($array, $element, $key);

        return $array[$key];
    }
}
