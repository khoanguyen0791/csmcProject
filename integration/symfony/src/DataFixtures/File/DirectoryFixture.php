<?php

namespace App\DataFixtures\File;
use Doctrine\Common\Persistence\EntityManagerInterface;
use App\DataFixtures\Misc\SubjectFixture;
use App\Entity\User\Info\Specialty;
use App\Entity\User\User;
use App\Entity\File\Directory;
use App\Entity\File\FileHash;
use App\Entity\File\VirturalFile;
use App\DataFixtures\User\UserFixture;
use App\DataFixtures\User\RoleFixture;
use App\DataFixtures\File\ParentFixture;
use App\Form\Data\ProfileFormData;
use App\Form\Data\SpecialtyFormData;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;

class DirectoryFixture extends Fixture implements DependentFixtureInterface
{


    public function load(ObjectManager $manager)
    {
        $user = $this->getReference(UserFixture::INSTRUCTOR_00);
        $directory1  = new Directory("SE6301",$user,"/root/SE6301");
        $directory2 = new Directory("SE6387",$user,"/root/SE6387");
        $directory1->setParent($this->getReference(ParentFixture::ROOT));
        $directory2->setParent($this->getReference(ParentFixture::ROOT));

        $manager->persist($directory1);
        $manager->persist($directory2);
        $this->addReference("directory1",$directory1);
        $this->addReference("directory2",$directory2);
        $manager->flush();
        
    }

    public function getDependencies() {
        return array(
            UserFixture::class,
            ParentFixture::class
        );
    }
}