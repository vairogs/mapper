<?php declare(strict_types = 1);

/*
 * This file is part of the Vairogs package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <davis@vairogs.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vairogs\Component\Mapper\Mercure;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\UrlGeneratorInterface;
use ApiPlatform\State\SerializerContextBuilderInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Serializer;
use Vairogs\Bundle\Traits\_LoadReflection;
use Vairogs\Component\Mapper\Traits\_MapFromAttribute;
use Vairogs\Functions\Memoize\MemoizeCache;

use function sprintf;

readonly class Mercure
{
    public function __construct(
        protected UrlGeneratorInterface $urlGenerator,
        protected Serializer\SerializerInterface $serializer,
        protected SerializerContextBuilderInterface $serializerContextBuilder,
        protected MemoizeCache $memoize,
        protected ?HubInterface $hub = null,
    ) {
    }

    public function publishToMercure(
        object $entity,
        Operation $operation,
    ): void {
        if ($this->hub instanceof HubInterface) {
            static $_helper = null;

            if (null === $_helper) {
                $_helper = new class {
                    use _LoadReflection;
                    use _MapFromAttribute;
                };
            }

            $topic = sprintf(
                '%s/api/%s/%s',
                $this->urlGenerator->generate('api_entrypoint', [], UrlGeneratorInterface::ABS_URL),
                $_helper->mapFromAttribute($entity, $this->memoize),
                $entity->getId(),
            );

            $resource = $_helper->mapFromAttribute($entity, $this->memoize);

            $context = [
                'operation' => $operation,
                'resource_class' => $operation->getClass(),
                'item_operation_name' => $operation->getName(),
                'groups' => [$_helper->loadReflection($resource, $this->memoize)->getConstant('READ')],
            ];

            $data = $this->serializer->serialize($entity, 'jsonld', $context);

            $update = new Update($topic, $data);

            $this->hub->publish($update);
        }
    }
}
