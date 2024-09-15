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

use ReflectionException;
use Symfony\Component\Finder\Finder;
use Vairogs\Component\Functions\Local;
use Vairogs\Component\Mapper\Attribute\Mapped;
use Vairogs\Component\Mapper\Constants\Context;
use Vairogs\Component\Mapper\Service\RequestCache;

use function class_exists;
use function dirname;
use function getcwd;

use const PHP_SAPI;

trait _FindClassesWithAttribute
{
    /**
     * @throws ReflectionException
     */
    public function findClassesWithAttribute(
        RequestCache $requestCache,
    ): array {
        return $requestCache->get(Context::FOUND_FILES, 'key', static function () use ($requestCache) {
            $matchingClasses = [];
            $finder = new Finder();
            $dirname = dirname(getcwd());

            if ('cli' === PHP_SAPI) {
                $dirname = getcwd();
            }

            $finder->files()->in([$dirname . '/src/ApiResource', $dirname . '/src/Entity'])->name('*.php');

            if ($finder->count()) {
                static $_helper = null;

                if (null === $_helper) {
                    $_helper = new class {
                        use _LoadReflection;
                        use Local\_GetClassFromFile;
                    };
                }

                foreach ($finder as $file) {
                    $className = $requestCache->get(Context::CALLER_CLASS, $file->getRealPath(), static fn () => $_helper->getClassFromFile($file->getRealPath()));

                    if ($className && class_exists($className)) {
                        $attributes = $_helper->loadReflection($className, $requestCache)->getAttributes(Mapped::class);

                        if (!empty($attributes)) {
                            $matchingClasses[] = $className;
                        }
                    }
                }
            }

            return $matchingClasses;
        });
    }
}
