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

use Symfony\Component\Finder\Finder;
use Vairogs\Bundle\Service\RequestCache;
use Vairogs\Component\Functions\Local\_GetClassFromFile;
use Vairogs\Component\Mapper\Attribute\Mapped;
use Vairogs\Component\Mapper\Constants\Context;

use function class_exists;
use function dirname;
use function getcwd;
use function is_object;

use const PHP_SAPI;

trait _MapFromAttribute
{
    public function mapFromAttribute(
        object|string $objectOrClass,
        RequestCache $requestCache,
        bool $skipGlobal = false,
    ): ?string {
        if (!$skipGlobal) {
            $foundClasses = $requestCache->get(Context::VAIROGS_M_MCLASSES, 'key', fn () => $this->findClassesWithAttribute($requestCache));

            foreach ($foundClasses as $item) {
                $this->mapMapped($item, $requestCache);
            }
        }

        return $this->mapMapped($objectOrClass, $requestCache);
    }

    protected function findClassesWithAttribute(
        RequestCache $requestCache,
    ): array {
        return $requestCache->get(Context::VAIROGS_M_FILES, 'key', function () use ($requestCache) {
            $matchingClasses = [];
            $finder = new Finder();
            $dirname = dirname(getcwd());
            if ('cli' === PHP_SAPI) {
                $dirname = getcwd();
            }

            $finder->files()->in([$dirname . '/src/ApiResource', $dirname . '/src/Entity'])->name('*.php');

            foreach ($finder as $file) {
                $className = (new class {
                    use _GetClassFromFile;
                })->getClassFromFile($file->getRealPath());
                if ($className && class_exists($className)) {
                    $attributes = (new class {
                        use _LoadReflection;
                    })->loadReflection($className, $requestCache)->getAttributes(Mapped::class);
                    if (!empty($attributes)) {
                        $matchingClasses[] = $className;
                    }
                }
            }

            return $matchingClasses;
        });
    }

    protected function mapMapped(
        object|string $class,
        RequestCache $requestCache,
    ): ?string {
        if (is_object($class)) {
            $class = $class::class;
        }

        return $requestCache->get(Context::VAIROGS_M_MAP, $class, function () use ($class, $requestCache) {
            $reflection = (new class {
                use _LoadReflection;
            })->loadReflection($class, $requestCache);

            if ([] !== ($attributes = $reflection->getAttributes(Mapped::class))) {
                $attribute = $attributes[0]->newInstance();

                if (null !== $attribute->reverse) {
                    $requestCache->get(Context::VAIROGS_M_MAP, $attribute->mapsTo, fn () => $attribute->reverse);
                }

                return $attribute->mapsTo;
            }

            return null;
        });
    }
}
