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
use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Parameters;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\Serializer\Filter\GroupFilter;
use ApiPlatform\State\OptionsInterface;
use Attribute;
use ReflectionException;
use Stringable;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\KernelInterface;
use Vairogs\Bundle\Traits\_GetClassFromFile;
use Vairogs\Bundle\Traits\_GetReadProperty;
use Vairogs\Bundle\Traits\_LoadReflection;
use Vairogs\Component\Mapper\Constants\MapperContext;
use Vairogs\Component\Mapper\State\Processor;
use Vairogs\Component\Mapper\State\Provider;
use Vairogs\Component\Mapper\Traits\_MapFromAttribute;
use Vairogs\Functions\Memoize\MemoizeCache;
use Vairogs\Functions\Text;

use function array_key_exists;
use function array_merge;
use function array_unique;
use function array_values;
use function class_exists;
use function debug_backtrace;
use function func_get_args;
use function is_array;
use function sprintf;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class SimpleApiResource extends ApiResource
{
    public function __construct(
        ?string $uriTemplate = null,
        ?string $shortName = null,
        ?string $description = null,
        string|array|null $types = null,
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
        bool|Operation|null $openapi = null,
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
        string|Stringable|null $security = null,
        ?string $securityMessage = null,
        string|Stringable|null $securityPostDenormalize = null,
        ?string $securityPostDenormalizeMessage = null,
        string|Stringable|null $securityPostValidation = null,
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
        ?string $policy = null,
        array|string|null $middleware = null,
        array|Parameters|null $parameters = null,
        ?bool $strictQueryParameterValidation = null,
        ?bool $hideHydraOperation = null,
        array $extraProperties = [],
        array $simplify = [],
    ) {
        static $_helper = null;
        static $memoize = null;

        if (null === $_helper) {
            $_helper = new class {
                use _GetClassFromFile;
                use _GetReadProperty;
                use _LoadReflection;
                use _MapFromAttribute;
                use Text\Traits\_SnakeCaseFromCamelCase;
            };
        }

        if (null === $memoize) {
            $app = $GLOBALS['app'];

            $memoize = match (true) {
                $app instanceof KernelInterface => $app->getContainer()->get(MemoizeCache::class),
                $app instanceof Application => $app->getKernel()->getContainer()->get(MemoizeCache::class),
                default => null,
            };

            $memoize ??= new MemoizeCache();
        }

        $callerClass = $memoize->memoize(MapperContext::CALLER_CLASS, $file = debug_backtrace(limit: 1)[0]['file'], static fn () => $_helper->getClassFromFile($file, $memoize));
        $attributes = null;

        try {
            $self = $_helper->loadReflection(objectOrClass: $callerClass, memoize: $memoize);

            $attributes = $self->getAttributes(name: self::class)[0]->getArguments();
            $current = $_helper->loadReflection(objectOrClass: __CLASS__, memoize: $memoize)->getMethod(name: __FUNCTION__);
            $i = $a = 0;
            $args = func_get_args();

            $named = [];

            foreach ($current->getParameters() as $parameter) {
                $named[$parameter->getName()] = $a;
                $a++;
            }

            $readProperty = $_helper->getReadProperty($self->getName(), $memoize);

            $uriVariables = null;

            if ('id' !== $readProperty) {
                $uriVariables = [
                    $readProperty => new Link(fromProperty: $readProperty, fromClass: $self->getName(), identifiers: [$readProperty]),
                ];
            }

            $files = $memoize->memoize(MapperContext::RESOURCE_FILES, 'key', static function () use ($_helper, $memoize) {
                $finder = new Finder();
                $finder->files()->in(__DIR__ . '/../Filter/Resource/')->name('*.php');
                $files = [];

                foreach ($finder as $file) {
                    $className = $memoize->memoize(MapperContext::CALLER_CLASS, $file->getRealPath(), static fn () => $_helper->getClassFromFile($file->getRealPath(), $memoize));

                    if ($className && class_exists($className)) {
                        $reflection = $_helper->loadReflection($className, memoize: $memoize);

                        if ($reflection->implementsInterface(FilterInterface::class)) {
                            $files[] = $reflection->getName();
                        }
                    }
                }

                return $files;
            });

            $filters = array_merge($filters ?? [], $files);
            $filters[] = GroupFilter::class;
            $filters = array_unique($filters);

            $newShortName = $_helper->loadReflection($_helper->mapFromAttribute($callerClass, $memoize, true), $memoize)->getShortName();

            $prefix = $_helper->snakeCaseFromCamelCase($newShortName);
            $get = new Get(
                uriVariables: $uriVariables,
                requirements: [$readProperty => '.+', ],
                validationContext: ['groups' => [$prefix . ':read', ], ],
            );
            $collection = new GetCollection(
                validationContext: ['groups' => [$prefix . ':read', ], ],
            );
            $delete = new Delete(
                uriVariables: $uriVariables,
                requirements: [$readProperty => '.+', ],
            );
            $post = new Post(
                validationContext: ['groups' => [$prefix . ':write', ], ],
            );
            $patch = new Patch(
                uriVariables: $uriVariables,
                requirements: [$readProperty => '.+', ],
                validationContext: ['groups' => [$prefix . ':write', ], ],
            );
            $put = new Put(
                uriVariables: $uriVariables,
                requirements: [$readProperty => '.+', ],
                validationContext: ['groups' => [$prefix . ':write', ], ],
            );

            $operations = [
                Get::class => $get,
                Delete::class => $delete,
                Post::class => $post,
                Patch::class => $patch,
                Put::class => $put,
                GetCollection::class => $collection,
            ];

            foreach ($simplify['unset']['operations'] ?? [] as $unset) {
                unset($operations[$unset]);
            }

            $defaults = [
                'denormalizationContext' => ['groups' => [$prefix . ':write', ], ],
                'normalizationContext' => ['groups' => [$prefix . ':read', ], ],
                'operations' => array_values($operations),
                'order' => ['id' => OrderFilterInterface::DIRECTION_DESC, ],
                'shortName' => $newShortName,
                'provider' => Provider::class,
                'processor' => Processor::class,
                'filters' => $filters,
                'output' => $callerClass,
            ];

            foreach ($defaults as $dKey => $dValue) {
                if ('operations' === $dKey && is_array($args[$named[$dKey]] ?? null)) {
                    $existing = $args[$named[$dKey]];

                    if ([] === $existing) {
                        $args[$named[$dKey]] = array_merge($existing, $defaults['operations']);

                        continue;
                    }

                    foreach ($existing as $op) {
                        $opAttribute = $_helper->loadReflection($op, $memoize);

                        if (null === $opAttribute->newInstance()->getName()) {
                            unset($operations[$op::class]);
                        }
                    }

                    $args[$named[$dKey]] = array_merge($existing, array_values($operations));

                    continue;
                }

                if (null === ($args[$named[$dKey]] ?? null)) {
                    $args[$named[$dKey]] = $dValue;
                }
            }

            foreach ($current->getParameters() as $parameter) {
                if (array_key_exists($i, $args) && $parameter->getDefaultValue() !== $args[$i]) {
                    $attributes[$parameter->getName()] = $args[$i];
                }

                $i++;
            }
        } catch (ReflectionException) {
        }

        if (null === $attributes) {
            throw new InvalidConfigurationException(sprintf('Unable to extend %s in %s', self::class, $callerClass));
        }

        unset($attributes['simplify']);

        parent::__construct(...$attributes);
    }
}
