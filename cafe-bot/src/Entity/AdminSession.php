<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'admin_sessions')]
class AdminSession
{
    public const FLOW_ADD = 'add_product';
    public const FLOW_EDIT = 'edit_product';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'bigint')]
    private int $telegramUserId;

    #[ORM\Column(type: 'string', length: 32)]
    private string $flow;

    #[ORM\Column(type: 'string', length: 64)]
    private string $step;

    #[ORM\Column(type: 'json')]
    private array $data = [];

    public function __construct(int $telegramUserId, string $flow, string $step = 'name')
    {
        $this->telegramUserId = $telegramUserId;
        $this->flow = $flow;
        $this->step = $step;
        $this->data = [];
    }

    public function getId(): ?int { return $this->id; }
    public function getTelegramUserId(): int { return $this->telegramUserId; }
    public function getFlow(): string { return $this->flow; }
    public function getStep(): string { return $this->step; }
    public function setStep(string $step): void { $this->step = $step; }
    public function getData(): array { return $this->data; }
    public function setData(array $data): void { $this->data = $data; }
}

