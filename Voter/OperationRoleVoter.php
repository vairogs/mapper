<?php declare(strict_types = 1);

namespace Vairogs\Component\Mapper\Voter;

use ApiPlatform\Metadata\ApiResource;
use Override;
use ReflectionException;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Vairogs\Component\Functions\Iteration;
use Vairogs\Component\Mapper\Attribute\GrantedOperation;
use Vairogs\Component\Mapper\Mapper;

use function array_merge;
use function in_array;

#[Autoconfigure(lazy: true)]
class OperationRoleVoter extends Voter
{
    public function __construct(
        protected readonly Mapper $mapper,
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
        $reflection = $this->mapper->loadReflection($subject);

        $check = [] !== $reflection->getAttributes(ApiResource::class) && [] !== $reflection->getAttributes(GrantedOperation::class);

        if ($check) {
            $grantedAttributes = [];
            foreach ($reflection->getAttributes(GrantedOperation::class) as $grantedAttribute) {
                $grantedAttributes = array_merge($grantedAttributes, $grantedAttribute->newInstance()->operations);
            }

            return in_array($attribute, $grantedAttributes, true);
        }

        return false;
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
        $reflection = $this->mapper->loadReflection($subject);
        $allowedRoles = [];
        foreach ($reflection->getAttributes(GrantedOperation::class) as $attribute) {
            $allowedRoles[] = $attribute->newInstance()->role;
        }

        if (in_array(AuthenticatedVoter::PUBLIC_ACCESS, $allowedRoles, true)) {
            return true;
        }

        return (new class() {
            use Iteration\_HaveCommonElements;
        })->haveCommonElements($token->getUser()?->getRoles() ?? [], $allowedRoles);
    }
}
