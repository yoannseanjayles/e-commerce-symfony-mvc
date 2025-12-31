<?php

namespace App\DataFixtures;

use App\Entity\Products;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\String\Slugger\SluggerInterface;
use Faker;

class ProductsFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(private SluggerInterface $slugger){}

    public function getDependencies(): array
    {
        return [
            CategoriesFixtures::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        // use the factory to create a Faker\Generator instance
        $faker = Faker\Factory::create('fr_FR');

        $brands = ['Shion House', 'Nova Style', 'Lumine Fashion', 'Velos Wear', 'Arcadia', 'Solstice'];
        $colors = ['Noir', 'Blanc', 'Bleu', 'Rouge', 'Gris', 'Vert', 'Beige', 'Rose', 'Marron'];
        
        $productNames = [
            'Veste en cuir élégante',
            'Robe d\'été fleurie',
            'Jean slim stretch',
            'Pull en laine mérinos',
            'Chemise Oxford blanc',
            'Blazer classique',
            'Pantalon chino beige',
            'T-shirt col V',
            'Manteau long en laine',
            'Jupe plissée midi',
            'Sweat à capuche',
            'Short en denim',
            'Chemisier en soie',
            'Gilet sans manches',
            'Combinaison élégante',
            'Polo coton piqué',
            'Cardigan boutonné',
            'Trench-coat imperméable',
            'Robe cocktail noire',
            'Jogging confort',
            'Chemise à carreaux',
            'Jupe crayon',
            'Bomber jacket',
            'Robe longue bohème',
            'Pull col roulé',
            'Short de sport',
            'Veste en jean',
            'Pantalon palazzo',
            'Tunique brodée',
            'Parka d\'hiver'
        ];

        shuffle($productNames);

        for($prod = 1; $prod <= 30; $prod++){
            $product = new Products();
            $name = $productNames[$prod - 1] ?? $faker->words(3, true);
            $product->setName($name);
            $product->setDescription($faker->paragraphs(3, true));
            $product->setSlug($this->slugger->slug($product->getName())->lower());
            $product->setPrice($faker->numberBetween(1999, 29999)); // Prix entre 19.99€ et 299.99€
            $product->setStock($faker->numberBetween(5, 50));
            $product->setBrand($faker->randomElement($brands));
            $product->setColor($faker->randomElement($colors));

            //On va chercher une référence de catégorie
            $category = $this->getReference('cat-'. rand(1, 8));
            $product->setCategories($category);

            $this->setReference('prod-'.$prod, $product);
            $manager->persist($product);
        }

        $manager->flush();
    }
}
