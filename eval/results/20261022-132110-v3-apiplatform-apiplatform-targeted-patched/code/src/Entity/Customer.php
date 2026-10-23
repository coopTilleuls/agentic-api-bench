<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'customer')]
class Customer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: Types::STRING)]
    private string $name = '';

    #[ORM\Column(name: 'email', type: Types::STRING, unique: true)]
    private string $email = '';

    public function getId(): ?int
    {
        return $this->id;
    }
}
