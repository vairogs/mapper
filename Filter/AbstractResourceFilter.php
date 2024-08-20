<?php declare(strict_types = 1);

/*
 * This file is part of the Vairogs package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <davis@vairogs.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vairogs\Component\Mapper\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Vairogs\Component\Mapper\Contracts\MapperInterface;

use function array_key_exists;
use function array_merge;

abstract class AbstractResourceFilter implements FilterInterface
{
    use Traits\_GetProperties;
    use Traits\_PropertyNameNormalizer;

    public function __construct(
        protected readonly ManagerRegistry $managerRegistry,
        protected readonly ?LoggerInterface $logger = null,
        protected ?array $properties = null,
        protected readonly ?NameConverterInterface $nameConverter = null,
        protected readonly ?MapperInterface $mapper = null,
    ) {
    }

    abstract public function getPropertiesForType(
        string $resourceClass,
    ): array;

    protected function checkApply(
        string $resourceClass,
        array &$context = [],
        bool $early = false,
    ): bool {
        $this->properties = array_merge($this->properties ?? [], $this->getPropertiesForType($resourceClass));

        if ([] === $this->properties) {
            return false;
        }

        if ($early) {
            return true;
        }

        foreach ($context['filters'] ?? [] as $property => $filter) {
            if (!array_key_exists($property, $this->properties)) {
                unset($context['filters'][$property]);
            }
        }

        return [] !== $context['filters'];
    }
}
