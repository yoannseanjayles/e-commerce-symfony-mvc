<?php

namespace App\Entity;

use App\Repository\ImagesRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ImagesRepository::class)]
class Images
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'string', length: 255)]
    private $name;

    #[ORM\ManyToOne(targetEntity: Products::class, inversedBy: 'images')]
    #[ORM\JoinColumn(nullable: false)]
    private $products;

    #[ORM\Column(type: 'string', length: 2048, nullable: true)]
    private $sourceUrl;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private $colorTag;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        // EasyAdmin ImageField may submit null on edit when no new file is uploaded.
        // In that case, keep the existing filename.
        if ($name === null || trim($name) === '') {
            return $this;
        }

        $this->name = $name;

        return $this;
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

    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function setSourceUrl(?string $sourceUrl): self
    {
        $this->sourceUrl = $sourceUrl;

        return $this;
    }

    public function getColorTag(): ?string
    {
        return $this->colorTag;
    }

    public function setColorTag(?string $colorTag): self
    {
        if ($colorTag !== null) {
            $colorTag = trim($colorTag);
            if ($colorTag === '') {
                $colorTag = null;
            }
        }

        $this->colorTag = $colorTag;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
