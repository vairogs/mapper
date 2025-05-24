<?php declare(strict_types = 1);

/*
 * This file is part of the Vairogs package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <davis@vairogs.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vairogs\Component\Mapper\Voter;

use Override;
use ReflectionException;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Vairogs\Bundle\ApiPlatform\Functions;
use Vairogs\Bundle\Traits\_LoadReflection;
use Vairogs\Component\Mapper\Attribute\GrantedOperation;
use Vairogs\Component\Mapper\Constants\MapperContext;
use Vairogs\Functions\Iteration;
use Vairogs\Functions\Memoize\MemoizeCache;

use function array_merge;
use function in_array;

#[Autoconfigure(lazy: true)]
class OperationRoleVoter extends Voter
{
    public function __construct(
        private readonly Functions $functions,
        private readonly MemoizeCache $memoize,
    ) {
    }

    /**
     * @throws ReflectionException
     */
    #[Override]
    protected function supports(
        string $attribute,
        mixed $subject,
    ): bool {
        return $this->memoize->memoize(MapperContext::SUPPORT_OPERATION, $subject, function () use ($subject, $attribute) {
            $result = false;

            static $_helper = null;

            if (null === $_helper) {
                $_helper = new class {
                    use _LoadReflection;
                };
            }

            $reflection = $_helper->loadReflection($subject, $this->memoize);
            $check = $this->functions->isResource($reflection->getName()) && [] !== $reflection->getAttributes(GrantedOperation::class);

            if ($check) {
                $grantedAttributes = [];

                foreach ($reflection->getAttributes(GrantedOperation::class) as $grantedAttribute) {
                    $grantedAttributes[] = $grantedAttribute->newInstance()->operations;
                }

                $result = in_array($attribute, array_merge(...$grantedAttributes), true);
            }

            return $result;
        });
    }

    /**
     * @throws ReflectionException
     */
    #[Override]
    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token,
    ): bool {
        return $this->memoize->memoize(MapperContext::ALLOW_OPERATION, $subject, function () use ($subject, $token) {
            static $_helper = null;

            if (null === $_helper) {
                $_helper = new class {
                    use _LoadReflection;
                    use Iteration\Traits\_HaveCommonElements;
                };
            }

            $reflection = $_helper->loadReflection($subject, $this->memoize);
            $allowedRoles = [];

            foreach ($reflection->getAttributes(GrantedOperation::class) as $item) {
                $allowedRoles[] = $item->newInstance()->role;
            }

            if (in_array(AuthenticatedVoter::PUBLIC_ACCESS, $allowedRoles, true)) {
                return true;
            }

            return $_helper->haveCommonElements($token->getUser()?->getRoles() ?? [], $allowedRoles);
        });
    }
}
