<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'orders')]
class Order
{
    public const STATUS_NEW = 'new';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_COMPLETED = 'completed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'bigint')]
    private int $telegramUserId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $ordererName;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $ordererNickname = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $ordererPhone = null;

    #[ORM\Column(type: 'string', length: 32)]
    private string $status = self::STATUS_NEW;

    #[ORM\OneToMany(mappedBy: 'order', targetEntity: OrderItem::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $items;

    #[ORM\Column(type: 'integer')]
    private int $totalCents = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(int $telegramUserId, string $ordererName, ?string $ordererNickname = null, ?string $ordererPhone = null)
    {
        $this->telegramUserId = $telegramUserId;
        $this->ordererName = $ordererName;
        $this->ordererNickname = $ordererNickname;
        $this->ordererPhone = $ordererPhone;
        $this->items = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->status = self::STATUS_NEW;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTelegramUserId(): int
    {
        return $this->telegramUserId;
    }

    public function getOrdererName(): string
    {
        return $this->ordererName;
    }

    public function setOrdererName(string $ordererName): void
    {
        $this->ordererName = $ordererName;
    }

    public function getOrdererNickname(): ?string
    {
        return $this->ordererNickname;
    }

    public function setOrdererNickname(?string $ordererNickname): void
    {
        $this->ordererNickname = $ordererNickname;
    }

    public function getOrdererPhone(): ?string
    {
        return $this->ordererPhone;
    }

    public function setOrdererPhone(?string $ordererPhone): void
    {
        $this->ordererPhone = $ordererPhone;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    /** @return Collection<int, OrderItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(OrderItem $item): void
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setOrder($this);
            $this->recalculateTotal();
        }
    }

    public function removeItem(OrderItem $item): void
    {
        if ($this->items->removeElement($item)) {
            $item->setOrder(null);
            $this->recalculateTotal();
        }
    }

    public function getTotalCents(): int
    {
        return $this->totalCents;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function recalculateTotal(): void
    {
        $total = 0;
        foreach ($this->items as $item) {
            $total += $item->getSubtotalCents();
        }
        $this->totalCents = $total;
    }
}

