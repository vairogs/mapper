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
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Vairogs\Bundle\ApiPlatform\Constants\MappingType;
use Vairogs\Component\Mapper\Constants\MapperContext;
use Vairogs\Component\Mapper\State\State;
use Vairogs\Functions\Memoize\MemoizeCache;

use function array_key_exists;
use function array_merge;
use function is_object;

#[AutoconfigureTag('serializer.normalizer')]
class EntityNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    public function __construct(
        private readonly State $state,
        private readonly MemoizeCache $memoize,
        private readonly RequestStack $stack,
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
        $context['groups'] = array_merge($context['groups'], $this->stack->getCurrentRequest()?->query->all('groups') ?? []);
        $data = $this->memoize->memoize(MapperContext::ALREADY_NORMALIZED, $data::class, fn () => $this->state->toResource($data, $context), false, (string) $data->getId());
        $context[MapperContext::ENTITY_NORMALIZER->value] = true;

        return $this->normalizer->normalize($data, $format, $context);
    }

    /**
     * @throws ReflectionException
     */
    public function supportsNormalization(
        mixed $data,
        ?string $format = null,
        array $context = [],
    ): bool {
        if (array_key_exists(MapperContext::ENTITY_NORMALIZER->value, $context) || !is_object($data)) {
            return false;
        }

        return $this->state->isMappedType($data, MappingType::ENTITY);
    }
}
