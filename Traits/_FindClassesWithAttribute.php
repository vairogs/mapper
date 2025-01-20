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
use Vairogs\Bundle\Service\RequestCache;
use Vairogs\Bundle\Traits\_GetClassFromFile;
use Vairogs\Bundle\Traits\_LoadReflection;
use Vairogs\Component\Mapper\Attribute\Mapped;
use Vairogs\Component\Mapper\Constants\MapperContext;

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
        return $requestCache->memoize(MapperContext::FOUND_FILES, 'key', static function () use ($requestCache) {
            $matchingClasses = [];
            $finder = new Finder();
            $dirname = getcwd();

            if ('cli' !== PHP_SAPI) {
                $dirname = dirname($dirname);
            }

            $finder->files()->in([$dirname . '/src/ApiResource', $dirname . '/src/Entity'])->name('*.php');

            if ($finder->hasResults()) {
                $_helper = new class {
                    use _GetClassFromFile;
                    use _LoadReflection;
                };

                foreach ($finder as $file) {
                    $className = $requestCache->memoize(MapperContext::CALLER_CLASS, $file->getRealPath(), static fn () => $_helper->getClassFromFile($file->getRealPath(), $requestCache));

                    if ($className && class_exists($className) && class_exists(Mapped::class)) {
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
