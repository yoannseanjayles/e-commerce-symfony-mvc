<?php

namespace App\Entity;

use App\Entity\Trait\CreatedAtTrait;
use App\Repository\OrdersRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrdersRepository::class)]
class Orders
{
    use CreatedAtTrait;

    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELED = 'canceled';

    public const PAYMENT_PROVIDER_STRIPE = 'stripe';
    public const PAYMENT_PROVIDER_MANUAL = 'manual';

    public const PAYMENT_STATUS_PENDING = 'pending';
    public const PAYMENT_STATUS_PAID = 'paid';
    public const PAYMENT_STATUS_FAILED = 'failed';
    public const PAYMENT_STATUS_CANCELED = 'canceled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'string', length: 20, unique: true)]
    private $reference;

    #[ORM\Column(type: 'integer', nullable: true)]
    private $total;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $paymentProvider = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $paymentStatus = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $stripeCheckoutSessionId = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $status = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $stockAdjusted = false;

    #[ORM\ManyToOne(targetEntity: Coupons::class, inversedBy: 'orders')]
    private $coupons;

    #[ORM\ManyToOne(targetEntity: Users::class, inversedBy: 'orders')]
    #[ORM\JoinColumn(nullable: false)]
    private $users;

    #[ORM\OneToMany(mappedBy: 'orders', targetEntity: OrdersDetails::class, orphanRemoval: true, cascade: ['persist'])]
    private $ordersDetails;

    public function __construct()
    {
        $this->ordersDetails = new ArrayCollection();
        $this->created_at = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    public function getTotal(): ?int
    {
        return $this->total;
    }

    public function setTotal(?int $total): self
    {
        $this->total = $total;

        return $this;
    }

    public function getPaymentProvider(): ?string
    {
        return $this->paymentProvider;
    }

    public function setPaymentProvider(?string $paymentProvider): self
    {
        $this->paymentProvider = $paymentProvider;

        return $this;
    }

    public function getPaymentStatus(): ?string
    {
        return $this->paymentStatus;
    }

    public function setPaymentStatus(?string $paymentStatus): self
    {
        $this->paymentStatus = $paymentStatus;

        return $this;
    }

    public function getStripeCheckoutSessionId(): ?string
    {
        return $this->stripeCheckoutSessionId;
    }

    public function setStripeCheckoutSessionId(?string $stripeCheckoutSessionId): self
    {
        $this->stripeCheckoutSessionId = $stripeCheckoutSessionId;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function isStockAdjusted(): bool
    {
        return $this->stockAdjusted;
    }

    public function setStockAdjusted(bool $stockAdjusted): self
    {
        $this->stockAdjusted = $stockAdjusted;

        return $this;
    }

    public function getCoupons(): ?Coupons
    {
        return $this->coupons;
    }

    public function setCoupons(?Coupons $coupons): self
    {
        $this->coupons = $coupons;

        return $this;
    }

    public function getUsers(): ?Users
    {
        return $this->users;
    }

    public function setUsers(?Users $users): self
    {
        $this->users = $users;

        return $this;
    }

    /**
     * @return Collection|OrdersDetails[]
     */
    public function getOrdersDetails(): Collection
    {
        return $this->ordersDetails;
    }

    public function addOrdersDetail(OrdersDetails $ordersDetail): self
    {
        if (!$this->ordersDetails->contains($ordersDetail)) {
            $this->ordersDetails[] = $ordersDetail;
            $ordersDetail->setOrders($this);
        }

        return $this;
    }

    public function removeOrdersDetail(OrdersDetails $ordersDetail): self
    {
        if ($this->ordersDetails->removeElement($ordersDetail)) {
            if ($ordersDetail->getOrders() === $this) {
                $ordersDetail->setOrders(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->reference;
    }

    public function getUserEmail(): ?string
    {
        return $this->users instanceof Users ? $this->users->getEmail() : null;
    }

    public function getUserFullName(): ?string
    {
        if (!$this->users instanceof Users) {
            return null;
        }

        $first = (string) ($this->users->getFirstname() ?? '');
        $last = (string) ($this->users->getLastname() ?? '');
        $full = trim(trim($first . ' ' . $last));

        return $full !== '' ? $full : null;
    }

    public function getUserAddressLine(): ?string
    {
        if (!$this->users instanceof Users) {
            return null;
        }

        $address = trim((string) ($this->users->getAddress() ?? ''));
        $zipcode = trim((string) ($this->users->getZipcode() ?? ''));
        $city = trim((string) ($this->users->getCity() ?? ''));

        $line2 = trim(implode(' ', array_values(array_filter([$zipcode, $city], static fn (string $v): bool => $v !== ''))));

        $out = $address;
        if ($line2 !== '') {
            $out = $out !== '' ? ($out . ', ' . $line2) : $line2;
        }

        return $out !== '' ? $out : null;
    }
}
