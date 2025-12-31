<?php

namespace App\Entity;

use App\Repository\AboutPageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AboutPageRepository::class)]
class AboutPage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $section1Title = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $section1Text = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $section1Image = null;

    #[ORM\Column(length: 255)]
    private ?string $section2Title = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $section2Text = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $section2Image = null;

    #[ORM\Column(length: 255)]
    private ?string $section3Title = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $section3Text = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $service1Title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $service1Text = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $service2Title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $service2Text = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $service3Title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $service3Text = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $service4Title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $service4Text = null;

    public function __toString(): string
    {
        return 'Page À propos';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSection1Title(): ?string
    {
        return $this->section1Title;
    }

    public function setSection1Title(string $section1Title): static
    {
        $this->section1Title = $section1Title;

        return $this;
    }

    public function getSection1Text(): ?string
    {
        return $this->section1Text;
    }

    public function setSection1Text(string $section1Text): static
    {
        $this->section1Text = $section1Text;

        return $this;
    }

    public function getSection1Image(): ?string
    {
        return $this->section1Image;
    }

    public function setSection1Image(?string $section1Image): static
    {
        $this->section1Image = $section1Image;

        return $this;
    }

    public function getSection2Title(): ?string
    {
        return $this->section2Title;
    }

    public function setSection2Title(string $section2Title): static
    {
        $this->section2Title = $section2Title;

        return $this;
    }

    public function getSection2Text(): ?string
    {
        return $this->section2Text;
    }

    public function setSection2Text(string $section2Text): static
    {
        $this->section2Text = $section2Text;

        return $this;
    }

    public function getSection2Image(): ?string
    {
        return $this->section2Image;
    }

    public function setSection2Image(?string $section2Image): static
    {
        $this->section2Image = $section2Image;

        return $this;
    }

    public function getSection3Title(): ?string
    {
        return $this->section3Title;
    }

    public function setSection3Title(string $section3Title): static
    {
        $this->section3Title = $section3Title;

        return $this;
    }

    public function getSection3Text(): ?string
    {
        return $this->section3Text;
    }

    public function setSection3Text(string $section3Text): static
    {
        $this->section3Text = $section3Text;

        return $this;
    }

    public function getService1Title(): ?string
    {
        return $this->service1Title;
    }

    public function setService1Title(?string $service1Title): static
    {
        $this->service1Title = $service1Title;

        return $this;
    }

    public function getService1Text(): ?string
    {
        return $this->service1Text;
    }

    public function setService1Text(?string $service1Text): static
    {
        $this->service1Text = $service1Text;

        return $this;
    }

    public function getService2Title(): ?string
    {
        return $this->service2Title;
    }

    public function setService2Title(?string $service2Title): static
    {
        $this->service2Title = $service2Title;

        return $this;
    }

    public function getService2Text(): ?string
    {
        return $this->service2Text;
    }

    public function setService2Text(?string $service2Text): static
    {
        $this->service2Text = $service2Text;

        return $this;
    }

    public function getService3Title(): ?string
    {
        return $this->service3Title;
    }

    public function setService3Title(?string $service3Title): static
    {
        $this->service3Title = $service3Title;

        return $this;
    }

    public function getService3Text(): ?string
    {
        return $this->service3Text;
    }

    public function setService3Text(?string $service3Text): static
    {
        $this->service3Text = $service3Text;

        return $this;
    }

    public function getService4Title(): ?string
    {
        return $this->service4Title;
    }

    public function setService4Title(?string $service4Title): static
    {
        $this->service4Title = $service4Title;

        return $this;
    }

    public function getService4Text(): ?string
    {
        return $this->service4Text;
    }

    public function setService4Text(?string $service4Text): static
    {
        $this->service4Text = $service4Text;

        return $this;
    }
}
