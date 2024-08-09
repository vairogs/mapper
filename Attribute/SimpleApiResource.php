<?php declare(strict_types = 1);

/*
 * This file is part of the Vairogs package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <davis@vairogs.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vairogs\Component\Mapper\Attribute;

use ApiPlatform\Doctrine\Common\Filter\OrderFilterInterface;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Parameters;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\State\OptionsInterface;
use Attribute;
use ReflectionClass;
use ReflectionException;
use Stringable;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Vairogs\Component\Mapper\Mapper;
use Vairogs\Component\Mapper\Traits\_GetClassFromFile;
use Vairogs\Component\Mapper\Traits\_GetReadProperty;
use Vairogs\Component\Mapper\Traits\_MapFromAttribute;

use function array_key_exists;
use function array_merge;
use function array_values;
use function debug_backtrace;
use function func_get_args;
use function is_array;
use function sprintf;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class SimpleApiResource extends ApiResource
{
    use _GetClassFromFile;
    use _GetReadProperty;
    use _MapFromAttribute;

    public function __construct(
        ?string $uriTemplate = null,
        ?string $shortName = null,
        ?string $description = null,
        array|string|null $types = null,
        $operations = null,
        array|string|null $formats = null,
        array|string|null $inputFormats = null,
        array|string|null $outputFormats = null,
        $uriVariables = null,
        ?string $routePrefix = null,
        ?array $defaults = null,
        ?array $requirements = null,
        ?array $options = null,
        ?bool $stateless = null,
        ?string $sunset = null,
        ?string $acceptPatch = null,
        ?int $status = null,
        ?string $host = null,
        ?array $schemes = null,
        ?string $condition = null,
        ?string $controller = null,
        ?string $class = null,
        ?int $urlGenerationStrategy = null,
        ?string $deprecationReason = null,
        ?array $headers = null,
        ?array $cacheHeaders = null,
        ?array $normalizationContext = null,
        ?array $denormalizationContext = null,
        ?bool $collectDenormalizationErrors = null,
        ?array $hydraContext = null,
        OpenApiOperation|bool|null $openapi = null,
        ?array $validationContext = null,
        ?array $filters = null,
        ?bool $elasticsearch = null,
        $mercure = null,
        $messenger = null,
        $input = null,
        $output = null,
        ?array $order = null,
        ?bool $fetchPartial = null,
        ?bool $forceEager = null,
        ?bool $paginationClientEnabled = null,
        ?bool $paginationClientItemsPerPage = null,
        ?bool $paginationClientPartial = null,
        ?array $paginationViaCursor = null,
        ?bool $paginationEnabled = null,
        ?bool $paginationFetchJoinCollection = null,
        ?bool $paginationUseOutputWalkers = null,
        ?int $paginationItemsPerPage = null,
        ?int $paginationMaximumItemsPerPage = null,
        ?bool $paginationPartial = null,
        ?string $paginationType = null,
        Stringable|string|null $security = null,
        ?string $securityMessage = null,
        Stringable|string|null $securityPostDenormalize = null,
        ?string $securityPostDenormalizeMessage = null,
        Stringable|string|null $securityPostValidation = null,
        ?string $securityPostValidationMessage = null,
        ?bool $compositeIdentifier = null,
        ?array $exceptionToStatus = null,
        ?bool $queryParameterValidationEnabled = null,
        ?array $links = null,
        ?array $graphQlOperations = null,
        $provider = null,
        $processor = null,
        ?OptionsInterface $stateOptions = null,
        mixed $rules = null,
        array|Parameters|null $parameters = null,
        array $extraProperties = [],
        array|bool|null $simplify = null,
    ) {
        $callerClass = $this->getClassFromFile(file: debug_backtrace()[0]['file'] ?? null);
        $attributes = null;

        try {
            $self = new ReflectionClass(objectOrClass: $callerClass);

            $attributes = $self->getAttributes(name: self::class)[0]->getArguments();
            $current = (new ReflectionClass(objectOrClass: __CLASS__))->getMethod(name: __FUNCTION__);
            $i = $a = 0;
            $args = func_get_args();

            $named = [];
            foreach ($current->getParameters() as $parameter) {
                $named[$parameter->getName()] = $a;
                $a++;
            }

            $ignore = [];
            $readProperty = $this->getReadProperty($self->getName(), $ignore);

            $uriVariables = null;
            if ('id' !== $readProperty) {
                $uriVariables = [
                    $readProperty => new Link(fromProperty: $readProperty, fromClass: $self->getName(), identifiers: [$readProperty]),
                ];
            }

            $get = new Get(uriVariables: $uriVariables, requirements: [$readProperty => '.+', ]);
            $collection = new GetCollection();
            $delete = new Delete(uriVariables: $uriVariables, requirements: [$readProperty => '.+', ]);
            $post = new Post();
            $patch = new Patch(uriVariables: $uriVariables, requirements: [$readProperty => '.+', ]);
            $put = new Put(uriVariables: $uriVariables, requirements: [$readProperty => '.+', ]);

            $operations = [
                Get::class => $get,
                Delete::class => $delete,
                Post::class => $post,
                Patch::class => $patch,
                Put::class => $put,
                GetCollection::class => $collection,
            ];

            if (is_array($simplify) && array_key_exists('unset', $simplify) && is_array($simplify['unset'])) {
                foreach ($simplify['unset'] as $unset) {
                    unset($operations[$unset]);
                }
            }

            $defaults = [
                'denormalizationContext' => ['groups' => [$self->getConstant('WRITE'), ], ],
                'normalizationContext' => ['groups' => [$self->getConstant('READ'), ], ],
                'operations' => array_values($operations),
                'order' => ['createdAt' => OrderFilterInterface::DIRECTION_DESC, ],
                'shortName' => (new ReflectionClass($this->mapFromAttribute($callerClass, $ignore)))->getShortName(),
                'provider' => Mapper::class,
                'processor' => Mapper::class,
            ];

            foreach ($defaults as $dKey => $dValue) {
                if ('operations' === $dKey && is_array($args[$named[$dKey]])) {
                    $existing = $args[$named[$dKey]];
                    if ([] === $existing) {
                        $args[$named[$dKey]] = array_merge($existing, $defaults['operations']);
                        continue;
                    }

                    foreach ($existing as $op) {
                        $opAttribute = new ReflectionClass($op);
                        if (null === $opAttribute->newInstance()->getName()) {
                            unset($operations[$op::class]);
                        }
                    }

                    $args[$named[$dKey]] = array_merge($existing, array_values($operations));
                    continue;
                }

                if (null === $args[$named[$dKey]]) {
                    $args[$named[$dKey]] = $dValue;
                }
            }

            foreach ($current->getParameters() as $parameter) {
                if (isset($args[$i]) && $parameter->getDefaultValue() !== $args[$i]) {
                    $attributes[$parameter->getName()] = $args[$i];
                }
                $i++;
            }
        } catch (ReflectionException) {
        }

        if (null === $attributes) {
            throw new InvalidConfigurationException(message: sprintf('Unable to extend %s in %s', self::class, $callerClass));
        }

        unset($attributes['simplify']);

        parent::__construct(...$attributes);
    }
}
