<?php
namespace Newageerp\SfUservice\Events;

use Symfony\Contracts\EventDispatcher\Event;

class UBeforeUpdateEvent extends Event {
    public const NAME = 'sfuservice.beforeupdate';

    protected object $entity;

    protected array $data;

    protected string $schema;

    public function __construct(object $entity, array &$data, string $schema)
    {
        $this->entity = $entity;
        $this->data = $data;
        $this->schema = $schema;
    }

    /**
     * Get the value of entity
     *
     * @return object
     */
    public function getEntity(): object
    {
        return $this->entity;
    }

    /**
     * Set the value of entity
     *
     * @param object $entity
     *
     * @return self
     */
    public function setEntity(object $entity): self
    {
        $this->entity = $entity;

        return $this;
    }

    /**
     * Get the value of data
     *
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Set the value of data
     *
     * @param array $data
     *
     * @return self
     */
    public function setData(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Get the value of schema
     *
     * @return string
     */
    public function getSchema(): string
    {
        return $this->schema;
    }

    /**
     * Set the value of schema
     *
     * @param string $schema
     *
     * @return self
     */
    public function setSchema(string $schema): self
    {
        $this->schema = $schema;

        return $this;
    }
}