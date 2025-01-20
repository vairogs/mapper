<?php declare(strict_types = 1);

/*
 * This file is part of the Vairogs package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <davis@vairogs.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vairogs\Component\Mapper\State;

use ApiPlatform\Doctrine\Orm\Extension\FilterExtension;
use ApiPlatform\Doctrine\Orm\Extension\OrderExtension;
use ApiPlatform\Doctrine\Orm\Extension\QueryResultCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Exception\ResourceClassNotFoundException;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\State\ProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\QueryBuilder;
use ReflectionException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Vairogs\Bundle\ApiPlatform\Functions;
use Vairogs\Bundle\Service\RequestCache;
use Vairogs\Bundle\Traits\_GetReadProperty;
use Vairogs\Bundle\Traits\_LoadReflection;
use Vairogs\Component\Mapper\Exception\ItemNotFoundHttpException;

use function sprintf;
use function strtolower;

class Provider extends State implements ProviderInterface
{
    public function __construct(
        AuthorizationCheckerInterface $security,
        RequestCache $requestCache,
        EntityManagerInterface $entityManager,
        Functions $functions,
        #[AutowireIterator(
            'api_platform.doctrine.orm.query_extension.collection',
        )]
        protected iterable $collectionExtensions = [],
    ) {
        parent::__construct(
            $security,
            $entityManager,
            $requestCache,
            $functions,
        );
    }

    /**
     * @throws ORMException
     * @throws ReflectionException
     * @throws ResourceClassNotFoundException
     */
    public function provide(
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): array|object|null {
        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use _GetReadProperty;
                use _LoadReflection;
            };
        }

        $class = $_helper->loadReflection($operation->getClass(), $this->requestCache);

        if (!$this->security->isGranted($operation::class, $class->getName())) {
            throw new AccessDeniedHttpException('Access denied');
        }

        return match (true) {
            $operation instanceof GetCollection => $this->getCollection($operation, $context),
            $operation instanceof Delete,
            $operation instanceof Get,
            $operation instanceof Patch,
            $operation instanceof Put,
            $operation instanceof Post => $this->getItem($operation, $uriVariables[$_helper->getReadProperty($operation->getClass(), $this->requestCache)], $context),
            default => throw new BadRequestHttpException(sprintf('Invalid operation: "%s"', $operation::class)),
        };
    }

    protected function applyFilterExtensionsToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        Operation $operation,
        array $context = [],
    ): array|object {
        foreach ($this->collectionExtensions as $extension) {
            if ($extension instanceof FilterExtension || $extension instanceof QueryResultCollectionExtensionInterface) {
                $extension->applyToCollection($queryBuilder, $queryNameGenerator, $operation->getClass(), $operation, $context);
            }

            if ($extension instanceof OrderExtension) {
                if ([] !== $queryBuilder->getDQLPart('orderBy')) {
                    continue;
                }

                foreach ($operation->getOrder() ?? [] as $field => $direction) {
                    $queryBuilder->addOrderBy(sprintf('%s.%s', $queryBuilder->getRootAliases()[0], $field), $direction);
                }
            }

            if ($extension instanceof QueryResultCollectionExtensionInterface && $extension->supportsResult($operation->getClass(), $operation, $context)) {
                return $extension->getResult($queryBuilder, $operation->getClass(), $operation, $context);
            }
        }

        return $queryBuilder->getQuery()->useQueryCache(true)->getResult();
    }

    /**
     * @throws ReflectionException
     */
    protected function getCollection(
        Operation $operation,
        array $context = [],
    ): array|object {
        $queryBuilder = $this->entityManager->createQueryBuilder()->select('m')->from($this->getEntityClass($operation), 'm');

        return $this->applyFilterExtensionsToCollection($queryBuilder, new QueryNameGenerator(), $operation, $context);
    }

    /**
     * @throws ORMException
     * @throws ReflectionException
     * @throws ResourceClassNotFoundException
     */
    protected function getItem(
        Operation $operation,
        mixed $id,
        array $context = [],
    ): object {
        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use _LoadReflection;
            };
        }

        $entity = $this->find($entityClass = $this->getEntityClass($operation), $id);

        $this->throwErrorIfNotExist($entity, strtolower($_helper->loadReflection($entityClass, $this->requestCache)->getShortName()), $id);

        return $this->toResource($entity, $context);
    }

    protected function throwErrorIfNotExist(
        mixed $result,
        string $rootAlias,
        mixed $id,
    ): void {
        if (null === $result) {
            throw new ItemNotFoundHttpException($rootAlias . ':' . $id);
        }
    }
}
