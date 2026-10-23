<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class CustomerIdAuthenticator extends AbstractAuthenticator
{
    public function supports(Request $request): bool
    {
        return true;
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $id = $request->headers->get('Customer-Id');
        if (null === $id || !ctype_digit($id) || \strlen($id) > 9) {
            throw new AuthenticationException('A valid Customer-Id header is required.');
        }

        return new SelfValidatingPassport(new UserBadge($id));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse(['errors' => [[
            'status' => (string) Response::HTTP_UNAUTHORIZED,
            'title' => 'Unauthorized',
            'detail' => 'A valid Customer-Id header is required.',
        ]]], Response::HTTP_UNAUTHORIZED, ['Content-Type' => 'application/vnd.api+json']);
    }
}
