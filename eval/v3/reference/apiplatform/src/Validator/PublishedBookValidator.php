<?php

namespace App\Validator;

use App\Entity\Book;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

final class PublishedBookValidator extends ConstraintValidator
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PublishedBook) {
            return;
        }

        if (!\is_int($value) || $this->entityManager->getRepository(Book::class)->findOneBy(['id' => $value, 'published' => true])) {
            return;
        }

        $this->context->buildViolation($constraint->message)->addViolation();
    }
}
