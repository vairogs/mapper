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

use ReflectionClass;
use ReflectionException;
use Symfony\Component\Finder\Finder;
use Vairogs\Component\Functions\Local\_GetClassFromFile;
use Vairogs\Component\Mapper\Attribute\Mapped;
use Vairogs\Component\Mapper\Constants\Context;
use Vairogs\Component\Mapper\Exception\MappingException;

use function array_key_exists;
use function class_exists;
use function count;
use function dirname;
use function getcwd;
use function is_object;
use function sprintf;

use const PHP_SAPI;

trait _MapFromAttribute
{
    use _SavedItems;

    public function mapFromAttribute(
        object|string $objectOrClass,
        array &$context = [],
        bool $skipGlobal = false,
    ): ?string {
        $class = (new class {
            use _LoadReflection;
        })->loadReflection($objectOrClass, $context)->getName();

        if (null !== ($found = $this->checkMap($class))) {
            return $found;
        }

        if ($skipGlobal) {
            if (null !== $this->mappedClasses) {
                $foundClasses = $context[Context::VAIROGS_M_MCLASSES] = $this->mappedClasses;
            } elseif (array_key_exists(Context::VAIROGS_M_MCLASSES, $context)) {
                $foundClasses = $this->mappedClasses = $context[Context::VAIROGS_M_MCLASSES];
            } else {
                $foundClasses = $this->mappedClasses = $context[Context::VAIROGS_M_MCLASSES] = $this->findClassesWithAttribute($context);
            }

            foreach ($foundClasses as $item) {
                $this->mapMapped($item, $context);
            }

            if (array_key_exists($class, $context[Context::VAIROGS_M_MAP] ??= [])) {
                return $context[Context::VAIROGS_M_MAP][$class];
            }
        }

        return $this->mapMapped($objectOrClass, $context);
    }

    protected function findClassesWithAttribute(
        array &$context = [],
    ): array {
        if ([] !== $this->files) {
            $context[Context::VAIROGS_M_FILES] = $this->files;

            return $this->files;
        }

        if ([] !== ($context[Context::VAIROGS_M_FILES] ?? [])) {
            $this->files = $context[Context::VAIROGS_M_FILES];

            return $context[Context::VAIROGS_M_FILES];
        }

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
                $attributes = (new ReflectionClass($className))->getAttributes(Mapped::class);
                if (!empty($attributes)) {
                    $matchingClasses[] = $className;
                }
            }
        }

        return $context[Context::VAIROGS_M_FILES] = $this->files = $matchingClasses;
    }

    protected function mapMapped(
        object|string $class,
        array &$context = [],
    ): ?string {
        if (is_object($class)) {
            $class = $class::class;
        }

        if (null !== ($found = $this->checkMap($class))) {
            return $found;
        }

        try {
            $reflection = (new class {
                use _LoadReflection;
            })->loadReflection($class, $context);

            if ([] !== ($attributes = $reflection->getAttributes(Mapped::class))) {
                if (1 === count($attributes)) {
                    $attribute = $attributes[0]->newInstance();

                    if (null !== $attribute->reverse) {
                        $this->saveItem($context[Context::VAIROGS_M_MAP], $attribute->reverse, $attribute->mapsTo);
                        $this->saveItem($this->map, $attribute->reverse, $attribute->mapsTo);
                    }

                    $this->saveItem($context[Context::VAIROGS_M_MAP], $attribute->mapsTo, $class);

                    return $this->saveItem($this->map, $attribute->mapsTo, $class);
                }

                throw new MappingException(sprintf('More than 1 map for %s', $reflection->getName()));
            }
        } catch (ReflectionException) {
            return null;
        }

        return null;
    }

    private function checkMap(
        string $class,
    ): ?string {
        if ('999' !== ($found = $this->map[$class] ?? '999')) {
            return $this->saveItem($context[Context::VAIROGS_M_MAP], $found, $class);
        }
        unset($found);

        if ('999' !== ($found = $context[Context::VAIROGS_M_MAP][$class] ?? '999')) {
            return $this->saveItem($this->map, $found, $class);
        }

        return null;
    }
}
