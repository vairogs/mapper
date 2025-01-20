<?php declare(strict_types = 1);

/*
 * This file is part of the Vairogs package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <davis@vairogs.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vairogs\Component\Mapper\Filter\Resource;

use ApiPlatform\Doctrine\Common\Filter\DateFilterInterface;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\ORM\QueryBuilder;
use Exception;
use ReflectionException;
use Vairogs\Component\DoctrineTools\UTCDateTimeImmutable;
use Vairogs\Component\Mapper\Filter\AbstractResourceFilter;
use Vairogs\Component\Mapper\Traits\_MapFromAttribute;

use function array_merge;
use function date_default_timezone_get;
use function sprintf;

class ResourceDateFilter extends AbstractResourceFilter implements DateFilterInterface
{
    /**
     * @throws Exception
     */
    public function apply(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if (!$this->checkApply($resourceClass, $context)) {
            return;
        }

        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use _MapFromAttribute;
            };
        }

        $timeZone = new DateTimeZone(date_default_timezone_get());

        foreach ($context['filters'] ?? [] as $property => $filter) {
            foreach ($filter as $condition => $value) {
                $context['filters'][$property][$condition] = new DateTime($value)->setTimezone($timeZone)->format(DateTimeInterface::ATOM);
            }
        }

        new DateFilter(
            $this->managerRegistry,
            $this->logger,
            $this->properties,
            $this->nameConverter,
        )->apply($queryBuilder, $queryNameGenerator, $_helper->mapFromAttribute($resourceClass, $this->requestCache), $operation, $context);
    }

    public function getDescription(
        string $resourceClass,
    ): array {
        if (!$this->checkApply($resourceClass, early: true)) {
            return [];
        }

        $description = [];

        foreach ($this->properties as $property => $options) {
            $description += $this->getFilterDescription($property, self::PARAMETER_BEFORE);
            $description += $this->getFilterDescription($property, self::PARAMETER_STRICTLY_BEFORE);
            $description += $this->getFilterDescription($property, self::PARAMETER_AFTER);
            $description += $this->getFilterDescription($property, self::PARAMETER_STRICTLY_AFTER);
        }

        return $description;
    }

    /**
     * @throws ReflectionException
     */
    public function getPropertiesForType(
        string $resourceClass,
    ): array {
        $properties = $this->getProperties($resourceClass);

        return array_merge(
            $this->properties ?? [],
            $properties[UTCDateTimeImmutable::class] ?? [],
            $properties[DateTime::class] ?? [],
            $properties[DateTimeInterface::class] ?? [],
            $properties[DateTimeImmutable::class] ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function getFilterDescription(
        string $property,
        string $period,
    ): array {
        $propertyName = $this->normalizePropertyName($property);

        return [
            sprintf('%s[%s]', $propertyName, $period) => [
                'property' => $propertyName,
                'type' => DateTimeInterface::class,
                'required' => false,
            ],
        ];
    }
}
