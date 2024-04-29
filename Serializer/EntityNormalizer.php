<?php declare(strict_types = 1);

namespace Vairogs\Component\Mapper\Serializer;

use ApiPlatform\Metadata\Exception\ResourceClassNotFoundException;
use ArrayObject;
use ReflectionException;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Vairogs\Component\Mapper\Constants\Enum\MappingType;
use Vairogs\Component\Mapper\Mapper;

use function is_object;

#[Autoconfigure(tags: ['serializer.normalizer'], lazy: true)]
class EntityNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const string VAIROGS_MAPPER_ENTITY_NORMALIZER = 'VAIROGS_MAPPER_ENTITY_NORMALIZER';

    public function __construct(
        private readonly Mapper $mapper,
    ) {
    }

    /**
     * @throws ReflectionException
     */
    public function supportsNormalization(
        mixed $data,
        ?string $format = null,
        array $context = [],
    ): bool {
        if (isset($context[self::VAIROGS_MAPPER_ENTITY_NORMALIZER]) || !is_object($data)) {
            return false;
        }

        return $this->mapper->isMappedType($data, MappingType::ENTITY, $context);
    }

    /**
     * @throws ReflectionException
     * @throws ExceptionInterface
     * @throws ResourceClassNotFoundException
     */
    public function normalize(
        mixed $object,
        ?string $format = null,
        array $context = [],
    ): float|array|ArrayObject|bool|int|string|null {
        $resource = $this->mapper->toResource($object, $context);
        $context[self::VAIROGS_MAPPER_ENTITY_NORMALIZER] = true;

        return $this->normalizer->normalize($resource, $format, $context);
    }

    public function getSupportedTypes(
        ?string $format,
    ): array {
        return [
            'object' => true,
        ];
    }
}
