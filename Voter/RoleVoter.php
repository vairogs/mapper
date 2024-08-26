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
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Vairogs\Bundle\Constants\Context;
use Vairogs\Bundle\Service\RequestCache;
use Vairogs\Component\Functions\Iteration;
use Vairogs\Component\Mapper\Contracts\MapperInterface;
use Vairogs\Component\Mapper\Traits\_LoadReflection;

use function in_array;

#[Autoconfigure(lazy: true)]
class RoleVoter extends Voter
{
    public function __construct(
        private readonly MapperInterface $mapper,
        private readonly RequestCache $requestCache,
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
        return $this->requestCache->get(Context::SUPPORT_ROLE, $subject, function () use ($subject) {
            $reflection = (new class {
                use _LoadReflection;
            })->loadReflection($subject, $this->requestCache);

            return $this->mapper->isResource($reflection->getName()) && [] !== $reflection->getAttributes(IsGranted::class);
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
        return $this->requestCache->get(Context::ALLOW_ROLE, $subject, function () use ($subject, $token) {
            $reflection = (new class {
                use _LoadReflection;
            })->loadReflection($subject, $this->requestCache);
            $allowedRoles = [];
            foreach ($reflection->getAttributes(IsGranted::class) as $item) {
                $allowedRoles[] = $item->newInstance()->attribute;
            }

            if (in_array(AuthenticatedVoter::PUBLIC_ACCESS, $allowedRoles, true)) {
                $result = true;
            } else {
                $result = (new class {
                    use Iteration\_HaveCommonElements;
                })->haveCommonElements($token->getUser()?->getRoles() ?? [], $allowedRoles);
            }

            return $result;
        });
    }
}
