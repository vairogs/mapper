<?php declare(strict_types = 1);

namespace Vairogs\Component\Mapper\Traits;

use ReflectionClass;
use ReflectionException;
use Symfony\Component\Finder\Finder;
use Vairogs\Component\Functions\Iteration\_AddElementIfNotExists;
use Vairogs\Component\Mapper\Attribute\Mapped;
use Vairogs\Component\Mapper\Exception\MappingException;
use Vairogs\Component\Mapper\Mapper;

use function array_key_exists;
use function count;
use function dirname;
use function getcwd;
use function is_object;
use function sprintf;

trait _MapFromAttribute
{
    public function mapFromAttribute(
        object|string $objectOrClass,
        array &$context = [],
    ): ?string {
        $class = $objectOrClass;

        if (is_object($objectOrClass)) {
            $class = $objectOrClass::class;
        }

        if (array_key_exists($class, $context[Mapper::VAIROGS_MAPPER_MAP] ??= [])) {
            return $context[Mapper::VAIROGS_MAPPER_MAP][$class];
        }

        if (array_key_exists(Mapper::VAIROGS_MAPPER_MM, $context)) {
            $foundClasses = $context[Mapper::VAIROGS_MAPPER_MM];
        } else {
            $foundClasses = $this->findClassesWithAttribute();
            $context[Mapper::VAIROGS_MAPPER_MM] = $foundClasses;
        }

        foreach ($foundClasses as $item) {
            $this->mapMapped($item, $context);
        }

        if (array_key_exists($class, $context[Mapper::VAIROGS_MAPPER_MAP] ??= [])) {
            return $context[Mapper::VAIROGS_MAPPER_MAP][$class];
        }

        return $this->mapMapped($objectOrClass, $context);
    }

    private function mapMapped(
        object|string $class,
        array &$context = [],
    ): ?string {
        if (is_object($class)) {
            $class = $class::class;
        }

        $addElement = (new class() {
            use _AddElementIfNotExists;
        });

        try {
            $reflection = (new class() {
                use _LoadReflection;
            })->loadReflection($class, $context);

            if ([] !== ($attributes = $reflection->getAttributes(Mapped::class))) {
                if (1 === count($attributes)) {
                    $attribute = $attributes[0]->newInstance();

                    $addElement->addElementIfNotExists($context[Mapper::VAIROGS_MAPPER_MAP], $attribute->mapsTo, $class);
                    if (null !== $attribute->reverse) {
                        $addElement->addElementIfNotExists($context[Mapper::VAIROGS_MAPPER_MAP], $attribute->reverse, $attribute->mapsTo);
                    }

                    return $context[Mapper::VAIROGS_MAPPER_MAP][$class];
                }

                throw new MappingException(sprintf('More than 1 map for %s', $reflection->getName()));
            }
        } catch (ReflectionException) {
            return null;
        }

        return null;
    }

    private function findClassesWithAttribute(): array
    {
        $matchingClasses = [];
        $finder = new Finder();
        $dirname = dirname(getcwd());
        $finder->files()->in([$dirname . '/src/Entity', $dirname . '/src/ApiResource'])->name('*.php');

        foreach ($finder as $file) {
            $className = (new class() {
                use _GetClassFromFile;
            })->getClassFromFile($file->getRealPath());
            if ($className && class_exists($className)) {
                $attributes = (new ReflectionClass($className))->getAttributes(Mapped::class);
                if (!empty($attributes)) {
                    $matchingClasses[] = $className;
                }
            }
        }

        return $matchingClasses;
    }
}
