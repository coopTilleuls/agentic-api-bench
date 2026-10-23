<?php

namespace App\State;

use ApiPlatform\Metadata\Exception\InvalidArgumentException;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

#[AsDecorator('api_platform.state_provider.main', priority: 190)]
final readonly class ParameterViolationToBadRequestProvider implements ProviderInterface
{
    public function __construct(#[AutowireDecorated] private ProviderInterface $inner)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        try {
            return $this->inner->provide($operation, $uriVariables, $context);
        } catch (ValidationException $e) {
            if (!$operation instanceof HttpOperation || 'GET' !== $operation->getMethod()) {
                throw $e;
            }

            throw new InvalidArgumentException($e->getConstraintViolationList()->get(0)->getMessage(), previous: $e);
        }
    }
}
