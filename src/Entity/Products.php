<?php

namespace App\Entity;

use App\Entity\Trait\CreatedAtTrait;
use App\Entity\Trait\SlugTrait;
use App\Repository\ProductsRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProductsRepository::class)]
class Products
{
    use CreatedAtTrait;
    use SlugTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'Le nom du produit ne peut pas être vide')]
    #[Assert\Length(
        min: 8,
        max: 200,
        minMessage: 'Le titre doit faire au moins {{ limit }} caractères',
        maxMessage: 'Le titre ne doit pas faire plus de {{ limit }} caractères'
    )]
    private $name;

    #[ORM\Column(type: 'text')]
    private $description;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private $brand;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private $productType;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private $gender;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private $frameShape;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private $frameMaterial;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private $frameStyle;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private $polarized = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private $prescriptionAvailable = false;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private $uvProtection;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private $externalSource;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private $externalId;

    #[ORM\ManyToOne(targetEntity: Categories::class, inversedBy: 'products')]
    #[ORM\JoinColumn(nullable: false)]
    private $categories;

    #[ORM\ManyToMany(targetEntity: Categories::class)]
    #[ORM\JoinTable(name: 'product_secondary_categories')]
    private $secondaryCategories;

    #[ORM\OneToMany(mappedBy: 'products', targetEntity: Images::class, orphanRemoval: true, cascade: ['persist'])]
    private $images;

    #[ORM\OneToMany(mappedBy: 'products', targetEntity: ProductVariant::class, orphanRemoval: true, cascade: ['persist'])]
    private $variants;

    #[ORM\OneToMany(mappedBy: 'products', targetEntity: OrdersDetails::class)]
    private $ordersDetails;

    public function __construct()
    {
        $this->images = new ArrayCollection();
        $this->variants = new ArrayCollection();
        $this->ordersDetails = new ArrayCollection();
        $this->secondaryCategories = new ArrayCollection();
        $this->created_at = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getBrand(): ?string
    {
        return $this->brand;
    }

    public function setBrand(?string $brand): self
    {
        $this->brand = $brand;

        return $this;
    }

    public function getProductType(): ?string
    {
        return $this->productType;
    }

    public function setProductType(?string $productType): self
    {
        $this->productType = $productType;

        return $this;
    }

    public function getGender(): ?string
    {
        return $this->gender;
    }

    public function setGender(?string $gender): self
    {
        $this->gender = $gender;

        return $this;
    }

    public function getFrameShape(): ?string
    {
        return $this->frameShape;
    }

    public function setFrameShape(?string $frameShape): self
    {
        $this->frameShape = $frameShape;

        return $this;
    }

    public function getFrameMaterial(): ?string
    {
        return $this->frameMaterial;
    }

    public function setFrameMaterial(?string $frameMaterial): self
    {
        $this->frameMaterial = $frameMaterial;

        return $this;
    }

    public function getFrameStyle(): ?string
    {
        return $this->frameStyle;
    }

    public function setFrameStyle(?string $frameStyle): self
    {
        $this->frameStyle = $frameStyle;

        return $this;
    }


    public function isPolarized(): bool
    {
        return (bool) $this->polarized;
    }

    public function setPolarized(bool $polarized): self
    {
        $this->polarized = $polarized;

        return $this;
    }

    public function isPrescriptionAvailable(): bool
    {
        return (bool) $this->prescriptionAvailable;
    }

    public function setPrescriptionAvailable(bool $prescriptionAvailable): self
    {
        $this->prescriptionAvailable = $prescriptionAvailable;

        return $this;
    }

    public function getUvProtection(): ?string
    {
        return $this->uvProtection;
    }

    public function setUvProtection(?string $uvProtection): self
    {
        $this->uvProtection = $uvProtection;

        return $this;
    }

    public function getExternalSource(): ?string
    {
        return $this->externalSource;
    }

    public function setExternalSource(?string $externalSource): self
    {
        $this->externalSource = $externalSource;

        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): self
    {
        $this->externalId = $externalId;

        return $this;
    }

    public function getCategories(): ?Categories
    {
        return $this->categories;
    }

    public function setCategories(?Categories $categories): self
    {
        $this->categories = $categories;

        return $this;
    }

    /**
     * @return Collection<int, Categories>
     */
    public function getSecondaryCategories(): Collection
    {
        return $this->secondaryCategories;
    }

    public function addSecondaryCategory(Categories $category): self
    {
        if (!$this->secondaryCategories->contains($category)) {
            $this->secondaryCategories->add($category);
        }

        return $this;
    }

    public function removeSecondaryCategory(Categories $category): self
    {
        $this->secondaryCategories->removeElement($category);

        return $this;
    }

    /**
     * @return Collection|Images[]
     */
    public function getImages(): Collection
    {
        return $this->images;
    }

    public function addImage(Images $image): self
    {
        if (!$this->images->contains($image)) {
            $this->images[] = $image;
            $image->setProducts($this);
        }

        return $this;
    }

    public function removeImage(Images $image): self
    {
        if ($this->images->removeElement($image)) {
            // set the owning side to null (unless already changed)
            if ($image->getProducts() === $this) {
                $image->setProducts(null);
            }
        }

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
            $ordersDetail->setProducts($this);
        }

        return $this;
    }

    public function removeOrdersDetail(OrdersDetails $ordersDetail): self
    {
        if ($this->ordersDetails->removeElement($ordersDetail)) {
            // set the owning side to null (unless already changed)
            if ($ordersDetail->getProducts() === $this) {
                $ordersDetail->setProducts(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|ProductVariant[]
     */
    public function getVariants(): Collection
    {
        return $this->variants;
    }

    public function addVariant(ProductVariant $variant): self
    {
        if (!$this->variants->contains($variant)) {
            $this->variants[] = $variant;
            $variant->setProducts($this);
        }

        return $this;
    }

    public function removeVariant(ProductVariant $variant): self
    {
        if ($this->variants->removeElement($variant)) {
            if ($variant->getProducts() === $this) {
                $variant->setProducts(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
