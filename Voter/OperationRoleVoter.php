<?php declare(strict_types = 1);

namespace Vairogs\Component\Mapper\Voter;

use ApiPlatform\Metadata\ApiResource;
use Override;
use ReflectionException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Vairogs\Component\Mapper\Attribute\GrantedOperation;
use Vairogs\Component\Mapper\Mapper;

class OperationRoleVoter extends Voter
{
    public function __construct(protected readonly Mapper $mapper)
    {
    }

    /**
     * @throws ReflectionException
     */
    #[Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        $reflection = $this->mapper->loadReflection($subject);

        return [] !== $reflection->getAttributes(ApiResource::class) && [] !== $reflection->getAttributes(GrantedOperation::class);
    }

    /**
     * @throws ReflectionException
     */
    #[Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        dd($attribute, $subject);
    }
}
