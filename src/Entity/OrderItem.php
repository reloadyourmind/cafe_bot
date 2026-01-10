<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'order_items')]
class OrderItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Order $order = null;

    #[ORM\ManyToOne(targetEntity: MenuItem::class)]
    #[ORM\JoinColumn(nullable: false)]
    private MenuItem $menuItem;

    #[ORM\Column(type: 'integer')]
    private int $quantity = 1;

    #[ORM\Column(type: 'integer')]
    private int $unitPriceCents;

    public function __construct(MenuItem $menuItem, int $quantity)
    {
        $this->menuItem = $menuItem;
        $this->quantity = max(1, $quantity);
        $this->unitPriceCents = $menuItem->getPriceCents();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): ?Order
    {
        return $this->order;
    }

    public function setOrder(?Order $order): void
    {
        $this->order = $order;
    }

    public function getMenuItem(): MenuItem
    {
        return $this->menuItem;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): void
    {
        $this->quantity = max(1, $quantity);
    }

    public function getUnitPriceCents(): int
    {
        return $this->unitPriceCents;
    }

    public function getSubtotalCents(): int
    {
        return $this->unitPriceCents * $this->quantity;
    }
}

