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

use Attribute;
use Vairogs\Component\Functions\Local\_Exists;

use function count;
use function is_callable;
use function is_string;
use function str_replace;
use function var_export;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Modifier
{
    public array $parameters = [];

    public function __construct(
        ...$parameters,
    ) {
        $this->parameters = $parameters;
    }

    public function closure(mixed $value, ?object $object = null): mixed
    {
        if (1 === count($this->parameters)) {
            $param = $this->parameters[0];
            if (is_callable($param)) {
                return $param($value);
            }

            if (is_string($param)) {
                $code = str_replace('$value', var_export($value, true), $param);

                return eval("return $code;");
            }
        } elseif (2 === count($this->parameters)) {
            [$target, $expression] = $this->parameters;
            if (null !== $object) {
                $target = $object::class;
            }
            $code = str_replace('$value', var_export($value, true), $expression);

            if (null !== $object || (new class {
                use _Exists;
            })->exists($target)) {
                if (str_contains($expression, '->')) {
                    $code = str_replace('->', '', $code);
                    $object ??= new $target();

                    return eval("return \$object->$code;");
                }

                $code = str_replace('::', '', $code);

                return eval("return $target::$code;");
            }
        }

        return $value;
    }
}
