<?php

namespace App\DoctrineEventSubscriber;

use App\Annotation\Uploadable;
use App\Utils\FileUploader;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\EventSubscriber;
use Doctrine\Common\Persistence\Event\LifecycleEventArgs;
use Doctrine\Common\Util\ClassUtils;
use Psr\Log\LoggerInterface;

/**
 * This is a symfony service. Subsribes to files and determine if a file is uploadabl. If there's something to upload, upload the file (hash, move it on disk) and continue the event lifecycle.
 */
class FileSubscriber implements EventSubscriber {
    private $reader;
    private $logger;
    private $uploader;

    public function __construct(Reader $reader, FileUploader $fileUploader, LoggerInterface $logger) {
        $this->reader = $reader;
        $this->uploader = $fileUploader;
        $this->logger = $logger;
    }

    /**
     * Returns an array of events this subscriber wants to listen to.
     *
     * @return array
     */
    public function getSubscribedEvents() {
        return [
            'prePersist',
            'preUpdate'
        ];
    }

    /**
     * Call this method when Doctrine Lifecycle Events prePersist happens.
     * If the entity is uploadable, then don't persist it (yet)
     */
    public function prePersist(LifecycleEventArgs $args) {
        $entity = $args->getObject();

        if (!$this->isUploadable($entity) /*|| !$this->hasUploadable($entity) */) {
            return;
        }

        $this->uploader->upload($entity);
    }

    /**
     * Not implemented yet.
     */
    public function preUpdate(LifecycleEventArgs $args) {
    }

    /**
     * Check if an entity class is uploadable.
     * Read the blue print of a class, and see if the class has uploadable annotation.
     */
    private function isUploadable($entity) {
        $annotation = $this->reader->getClassAnnotation(new \ReflectionClass(ClassUtils::getClass($entity)), Uploadable::class);
        return $annotation !== null;
    }

    /**
     * Check if a given class has uploadable file.
     * Read the blue print of a class, then go through all properties and see if any property has uploadable annotation. (not the class)
     */
    private function hasUploadable($entity) {
        $class = new \ReflectionClass(ClassUtils::getClass($entity));
        foreach($class->getProperties() as $p) {
            $annotation = $this->reader->getPropertyAnnotation($p, Uploadable::class);
            if($annotation !== null) {
                return true;
            }
        }

        return false;
    }
}
