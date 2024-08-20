<?php declare(strict_types = 1);

/*
 * This file is part of the Vairogs package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <davis@vairogs.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vairogs\Component\Mapper\Serializer;

use ApiPlatform\Metadata\Exception\ResourceClassNotFoundException;
use ArrayObject;
use Doctrine\ORM\Exception\ORMException;
use ReflectionException;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Vairogs\Component\Mapper\Constants\Context;
use Vairogs\Component\Mapper\Constants\Enum\MappingType;
use Vairogs\Component\Mapper\Contracts\MapperInterface;

use function array_key_exists;
use function is_object;

#[Autoconfigure(lazy: true)]
#[AutoconfigureTag('serializer.normalizer')]
class EntityNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    public function __construct(
        private readonly MapperInterface $mapper,
    ) {
    }

    public function getSupportedTypes(
        ?string $format,
    ): array {
        return [
            'object' => true,
        ];
    }

    /**
     * @throws ReflectionException
     * @throws ExceptionInterface
     * @throws ResourceClassNotFoundException
     * @throws ORMException
     */
    public function normalize(
        mixed $data,
        ?string $format = null,
        array $context = [],
    ): float|array|ArrayObject|bool|int|string|null {
        if (
            array_key_exists($data::class, $this->mapper->alreadyMapped)
            && array_key_exists($data->getId(), $this->mapper->alreadyMapped[$data::class])
        ) {
            return $this->mapper->alreadyMapped[$data::class][$data->getId()];
        }

        $resource = $this->mapper->toResource($data, $context);
        $context[Context::VAIROGS_M_ENTITY_NORMALIZER] = true;
        $this->mapper->alreadyMapped[$data::class][$data->getId()] = $resource;

        return $this->normalizer->normalize($resource, $format, $context);
    }

    /**
     * @throws ReflectionException
     */
    public function supportsNormalization(
        mixed $data,
        ?string $format = null,
        array $context = [],
    ): bool {
        if (array_key_exists(Context::VAIROGS_M_ENTITY_NORMALIZER, $context) || !is_object($data)) {
            return false;
        }

        return $this->mapper->isMappedType($data, MappingType::ENTITY, $context);
    }
}
