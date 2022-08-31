<?php

namespace Newageerp\SfUservice\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Newageerp\SfBaseEntity\Controller\OaBaseController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Newageerp\SfSocket\Service\SocketService;

class UControllerBase extends OaBaseController
{
    protected array $properties = [];

    protected array $schemas = [];

    public function __construct(EntityManagerInterface $em, EventDispatcherInterface $eventDispatcher, SocketService $socketService)
    {
        parent::__construct($em, $eventDispatcher, $socketService);
        $filePath = $_ENV['NAE_SFS_PROPERTIES_FILE_PATH'];
        $this->properties = json_decode(file_get_contents($filePath), true);

        $schemaFilePath = $_ENV['NAE_SFS_SCHEMAS_FILE_PATH'];
        $this->schemas = json_decode(file_get_contents($schemaFilePath), true);
    }

    protected function getPropertiesForSchema(string $schema)
    {
        $properties = [];
        foreach ($this->properties as $property) {
            if ($property['schema'] === $schema) {
                $properties[$property['key']] = $property;
            }
        }
        return $properties;
    }

    public function getSchemas() : array {
        return $this->schemas;
    }
}
