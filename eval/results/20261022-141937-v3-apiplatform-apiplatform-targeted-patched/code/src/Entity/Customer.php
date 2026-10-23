<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'customer')]
class Customer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: 'text')]
    private string $name = '';

    #[ORM\Column(name: 'email', type: 'text', unique: true)]
    private string $email = '';

    public function getId(): ?int
    {
        return $this->id;
    }
}
