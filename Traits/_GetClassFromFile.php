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

use PhpToken;

use function array_slice;
use function file_get_contents;

use const T_CLASS;
use const T_NAMESPACE;
use const T_STRING;
use const T_WHITESPACE;

trait _GetClassFromFile
{
    public function getClassFromFile(
        ?string $file,
    ): ?string {
        if (null === $file) {
            return null;
        }

        $namespace = '';
        $tokens = PhpToken::tokenize(code: file_get_contents(filename: $file));

        foreach ($tokens as $i => $token) {
            if (T_NAMESPACE === $token->id) {
                foreach (array_slice($tokens, $i + 1) as $subToken) {
                    if (T_NAME_QUALIFIED === $subToken->id) {
                        $namespace = $subToken->text;
                        break;
                    }
                }
            }

            if (T_CLASS === $token->id) {
                foreach (array_slice($tokens, $i + 1) as $subToken) {
                    if (T_WHITESPACE === $subToken->id) {
                        continue;
                    }

                    if (T_STRING === $subToken->id) {
                        return $namespace . '\\' . $subToken->text;
                    }
                    break;
                }
            }
        }

        return null;
    }
}
