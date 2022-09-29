<?php

namespace Newageerp\SfUservice\EventListener;

use App\Kernel;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

class OnFlushEventListener
{
    protected $options = [];

    protected LoggerInterface $ajLogger;

    protected MessageBusInterface $bus;

    protected array $insertions = [];
    protected array $updates = [];

    public function __construct(LoggerInterface $ajLogger, MessageBusInterface $bus)
    {
        $this->ajLogger = $ajLogger;
        $this->bus = $bus;
        $this->options = json_decode(
            file_get_contents($_ENV['NAE_SFS_CP_STORAGE_PATH'] . '/entity-changes.json'),
            true
        );
        $this->insertions = [];
        $this->updates = [];
    }

    public function onFlush(OnFlushEventArgs $onFlushEventArgs)
    {
        $em = $onFlushEventArgs->getEntityManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            foreach ($this->options as $option) {
                if (isset($option['onDelete'])) {
                    $onCreates = is_array($option['onDelete']) ? $option['onDelete'] : [$option['onDelete']];
                    foreach ($onCreates as $entityName) {
                        $className = 'App\Entity\\' . $entityName;

                        if ($entity::class === $className || 'Proxies\__CG__\\' . $className === $entity::class) {
                            $callAble = explode("::", $option['call']);

                            $resp = $callAble($entity);
                            if (!is_array($resp)) {
                                $resp = [$resp];
                            }
                            foreach ($resp as $m) {
                                $this->bus->dispatch($m, [new DelayStamp(3 * 1000)]);
                            }
                        }
                    }
                }
            }
        }

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $this->insertions[] = $entity;

            foreach ($this->options as $option) {
                if (isset($option['onCreate'])) {
                    $onCreates = is_array($option['onCreate']) ? $option['onCreate'] : [$option['onCreate']];
                    foreach ($onCreates as $entityName) {
                        $className = 'App\Entity\\' . $entityName;

                        if ($entity::class === $className || 'Proxies\__CG__\\' . $className === $entity::class) {
                            $callAble = explode("::", $option['call']);

                            $resp = $callAble($entity);
                            if (!is_array($resp)) {
                                $resp = [$resp];
                            }
                            foreach ($resp as $m) {
                                $this->bus->dispatch($m, [new DelayStamp(3 * 1000)]);
                            }
                        }
                    }
                }
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $changes = $em->getUnitOfWork()->getEntityChangeSet($entity);
            $this->updates[] = ['entity' => $entity, 'changes' => $changes];

            foreach ($this->options as $option) {
                if (isset($option['onChange'])) {
                    $onChanges = is_array($option['onChange']) ? $option['onChange'] : [$option['onChange']];
                    foreach ($onChanges as $onChange) {
                        [$entityName, $field] = explode(".", $onChange);
                        $className = 'App\Entity\\' . $entityName;

                        if (($entity::class === $className || 'Proxies\__CG__\\' . $className === $entity::class) && isset($changes[$field])) {
                            $callAble = explode("::", $option['call']);

                            $resp = $callAble($entity);
                            if (!is_array($resp)) {
                                $resp = [$resp];
                            }
                            foreach ($resp as $m) {
                                $this->bus->dispatch($m, [new DelayStamp(3 * 1000)]);
                            }
                        }
                    }
                }

                if (isset($option['onChangeWithParams'])) {
                    $onChanges = is_array($option['onChangeWithParams']) ? $option['onChangeWithParams'] : [$option['onChangeWithParams']];
                    foreach ($onChanges as $onChange) {
                        [$entityName, $field] = explode(".", $onChange);
                        $className = 'App\Entity\\' . $entityName;

                        if (($entity::class === $className || 'Proxies\__CG__\\' . $className === $entity::class) && isset($changes[$field])) {
                            $callAble = explode("::", $option['call']);

                            if ($changes[$field][0]) {
                                $resp = $callAble($entity, $changes[$field][0]);
                                if (!is_array($resp)) {
                                    $resp = [$resp];
                                }
                                foreach ($resp as $m) {
                                    $this->bus->dispatch($m, [new DelayStamp(3 * 1000)]);
                                }
                            }
                            if ($changes[$field][1]) {
                                $resp = $callAble($entity, $changes[$field][1]);
                                if (!is_array($resp)) {
                                    $resp = [$resp];
                                }
                                foreach ($resp as $m) {
                                    $this->bus->dispatch($m, [new DelayStamp(3 * 1000)]);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    public function postFlush(PostFlushEventArgs $postFlushEventArgs)
    {
        foreach ($this->insertions as $entity) {
            foreach ($this->options as $option) {
                if (isset($option['afterCreate'])) {
                    $onCreates = is_array($option['afterCreate']) ? $option['afterCreate'] : [$option['afterCreate']];
                    foreach ($onCreates as $entityName) {
                        $className = 'App\Entity\\' . $entityName;

                        if ($entity::class === $className || 'Proxies\__CG__\\' . $className === $entity::class) {
                            $callAble = explode("::", $option['call']);

                            $resp = $callAble($entity);
                            if (!is_array($resp)) {
                                $resp = [$resp];
                            }
                            foreach ($resp as $m) {
                                $this->bus->dispatch($m);
                            }
                        }
                    }
                }
            }
        }

        foreach ($this->updates as $updateData) {
            $entity = $updateData['entity'];
            $changes = $updateData['changes'];

            foreach ($this->options as $option) {
                if (isset($option['afterChange'])) {
                    $afterChanges = is_array($option['afterChange']) ? $option['afterChange'] : [$option['afterChange']];
                    foreach ($afterChanges as $afterChange) {
                        [$entityName, $field] = explode(".", $afterChange);
                        $className = 'App\Entity\\' . $entityName;

                        if ($entity::class === $className && isset($changes[$field])) {
                            $callAble = explode("::", $option['call']);

                            $resp = $callAble($entity);
                            if (!is_array($resp)) {
                                $resp = [$resp];
                            }
                            foreach ($resp as $m) {
                                $this->bus->dispatch($m);
                            }
                        }
                    }
                }

                if (isset($option['afterChangeWithParams'])) {
                    $afterChanges = is_array($option['afterChangeWithParams']) ? $option['afterChangeWithParams'] : [$option['afterChangeWithParams']];
                    foreach ($afterChanges as $afterChange) {
                        [$entityName, $field] = explode(".", $afterChange);
                        $className = 'App\Entity\\' . $entityName;

                        if ($entity::class === $className && isset($changes[$field])) {
                            $callAble = explode("::", $option['call']);

                            if ($changes[$field][0]) {
                                $resp = $callAble($entity, $changes[$field][0]);
                                if (!is_array($resp)) {
                                    $resp = [$resp];
                                }
                                foreach ($resp as $m) {
                                    $this->bus->dispatch($m, [new DelayStamp(3 * 1000)]);
                                }
                            }
                            if ($changes[$field][1]) {
                                $resp = $callAble($entity, $changes[$field][1]);
                                if (!is_array($resp)) {
                                    $resp = [$resp];
                                }
                                foreach ($resp as $m) {
                                    $this->bus->dispatch($m);
                                }
                            }
                        }
                    }
                }
            }
        }

        $this->insertions = [];
        $this->updates = [];
    }
}
