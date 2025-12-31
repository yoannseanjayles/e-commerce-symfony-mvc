<?php

namespace App\Entity;

use App\Entity\Trait\CreatedAtTrait;
use App\Entity\Trait\SlugTrait;
use App\Repository\ProductVariantRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @UniqueEntity(fields={"sku"}, message="Ce SKU est déjà utilisé par une autre variante.", ignoreNull=true)
 * @UniqueEntity(fields={"barcode"}, message="Ce code-barres est déjà utilisé par une autre variante.", ignoreNull=true)
 */
#[ORM\Entity(repositoryClass: ProductVariantRepository::class)]
#[ORM\Table(name: 'product_variant')]
class ProductVariant
{
    use CreatedAtTrait;
    use SlugTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\ManyToOne(targetEntity: Products::class, inversedBy: 'variants')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private $products;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'Le nom de la variante est obligatoire.')]
    private $name;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private $color;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private $colorCode;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private $size;

    #[ORM\Column(type: 'integer', nullable: true)]
    private $lensWidthMm;

    #[ORM\Column(type: 'integer', nullable: true)]
    private $bridgeWidthMm;

    #[ORM\Column(type: 'integer', nullable: true)]
    private $templeLengthMm;

    #[ORM\Column(type: 'integer', nullable: true)]
    private $lensHeightMm;

    #[ORM\Column(type: 'string', length: 64, nullable: true, unique: true)]
    private $sku;

    #[ORM\Column(type: 'string', length: 32, nullable: true, unique: true)]
    private $barcode;

    #[ORM\Column(type: 'integer', nullable: true)]
    private $price;

    #[ORM\Column(type: 'integer', nullable: true)]
    private $stock;

    #[ORM\ManyToOne(targetEntity: Images::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Images $primaryImage = null;

    public function __construct()
    {
        $this->created_at = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProducts(): ?Products
    {
        return $this->products;
    }

    public function setProducts(?Products $products): self
    {
        $this->products = $products;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = trim((string) ($name ?? ''));

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): self
    {
        $this->color = $color;

        return $this;
    }

    public function getColorCode(): ?string
    {
        return $this->colorCode;
    }

    public function setColorCode(?string $colorCode): self
    {
        $this->colorCode = $colorCode;

        return $this;
    }

    public function getSize(): ?string
    {
        return $this->size;
    }

    public function setSize(?string $size): self
    {
        $this->size = $size;

        return $this;
    }

    public function getLensWidthMm(): ?int
    {
        return $this->lensWidthMm;
    }

    public function setLensWidthMm(?int $lensWidthMm): self
    {
        $this->lensWidthMm = $lensWidthMm;

        return $this;
    }

    public function getBridgeWidthMm(): ?int
    {
        return $this->bridgeWidthMm;
    }

    public function setBridgeWidthMm(?int $bridgeWidthMm): self
    {
        $this->bridgeWidthMm = $bridgeWidthMm;

        return $this;
    }

    public function getTempleLengthMm(): ?int
    {
        return $this->templeLengthMm;
    }

    public function setTempleLengthMm(?int $templeLengthMm): self
    {
        $this->templeLengthMm = $templeLengthMm;

        return $this;
    }

    public function getLensHeightMm(): ?int
    {
        return $this->lensHeightMm;
    }

    public function setLensHeightMm(?int $lensHeightMm): self
    {
        $this->lensHeightMm = $lensHeightMm;

        return $this;
    }

    public function getSku(): ?string
    {
        return $this->sku;
    }

    public function setSku(?string $sku): self
    {
        $this->sku = $sku;

        return $this;
    }

    public function getBarcode(): ?string
    {
        return $this->barcode;
    }

    public function setBarcode(?string $barcode): self
    {
        $this->barcode = $barcode;

        return $this;
    }

    public function getPrice(): ?int
    {
        return $this->price;
    }

    public function setPrice(?int $price): self
    {
        $this->price = $price;

        return $this;
    }

    public function getStock(): ?int
    {
        return $this->stock;
    }

    public function setStock(?int $stock): self
    {
        $this->stock = $stock;

        return $this;
    }

    public function getPrimaryImage(): ?Images
    {
        return $this->primaryImage;
    }

    public function setPrimaryImage(?Images $primaryImage): self
    {
        $this->primaryImage = $primaryImage;

        return $this;
    }

    public function __toString(): string
    {
        return (string) ($this->name ?? 'Variant');
    }
}
