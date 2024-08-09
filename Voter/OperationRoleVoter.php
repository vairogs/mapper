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
use Vairogs\Component\Functions\Iteration;
use Vairogs\Component\Mapper\Attribute\GrantedOperation;
use Vairogs\Component\Mapper\Contracts\MapperInterface;

use function array_key_exists;
use function array_merge;
use function in_array;
use function property_exists;

#[Autoconfigure(lazy: true)]
class OperationRoleVoter extends Voter
{
    public function __construct(
        private readonly MapperInterface $mapper,
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
        if (property_exists($this->mapper, 'supportOperation') && array_key_exists($subject, $this->mapper->supportOperation)) {
            return $this->mapper->supportOperation[$subject];
        }

        $result = false;
        $reflection = $this->mapper->loadReflection($subject);

        $check = $this->mapper->isResource($reflection->getName()) && [] !== $reflection->getAttributes(GrantedOperation::class);

        if ($check) {
            $grantedAttributes = [];
            foreach ($reflection->getAttributes(GrantedOperation::class) as $grantedAttribute) {
                $grantedAttributes[] = $grantedAttribute->newInstance()->operations;
            }

            $result = in_array($attribute, array_merge(...$grantedAttributes), true);
        }

        return $this->mapper->saveItem($this->mapper->supportOperation, $result, $subject);
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
        if (property_exists($this->mapper, 'allowedOperation') && array_key_exists($subject, $this->mapper->allowedOperation)) {
            return $this->mapper->allowedOperation[$subject];
        }

        $reflection = $this->mapper->loadReflection($subject);
        $allowedRoles = [];
        foreach ($reflection->getAttributes(GrantedOperation::class) as $item) {
            $allowedRoles[] = $item->newInstance()->role;
        }

        if (in_array(AuthenticatedVoter::PUBLIC_ACCESS, $allowedRoles, true)) {
            $result = true;
        } else {
            $result = (new class {
                use Iteration\_HaveCommonElements;
            })->haveCommonElements($token->getUser()?->getRoles() ?? [], $allowedRoles);
        }

        return $this->mapper->saveItem($this->mapper->allowedOperation, $result, $subject);
    }
}
