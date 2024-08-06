<?php declare(strict_types = 1);

namespace Vairogs\Component\Mapper\Voter;

use Override;
use ReflectionException;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Vairogs\Component\Functions\Iteration;
use Vairogs\Component\Mapper\Contracts\MapperInterface;

use function in_array;

#[Autoconfigure(lazy: true)]
class RoleVoter extends Voter
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
        $reflection = $this->mapper->loadReflection($subject);

        return $this->mapper->isResource($reflection->getName()) && [] !== $reflection->getAttributes(IsGranted::class);
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
        foreach ($reflection->getAttributes(IsGranted::class) as $item) {
            $allowedRoles[] = $item->newInstance()->attribute;
        }

        if (in_array(AuthenticatedVoter::PUBLIC_ACCESS, $allowedRoles, true)) {
            return true;
        }

        return (new class() {
            use Iteration\_HaveCommonElements;
        })->haveCommonElements($token->getUser()?->getRoles() ?? [], $allowedRoles);
    }
}
