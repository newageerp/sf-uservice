<?php

namespace Newageerp\SfUservice\EventListener;

use App\Kernel;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

class OnFlushEventListener {
    protected $options = [];

    protected LoggerInterface $ajLogger;

    protected MessageBusInterface $bus;

    public function __construct(LoggerInterface $ajLogger, MessageBusInterface $bus)
    {
        $this->ajLogger = $ajLogger;
        $this->bus = $bus;
        $this->options = json_decode(
            file_get_contents($_ENV['NAE_SFS_CP_STORAGE_PATH'] . '/entity-changes.json'),
            true
        );
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

                        if ($entity::class === $className) {
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
            foreach ($this->options as $option) {
                if (isset($option['onCreate'])) {
                    $onCreates = is_array($option['onCreate']) ? $option['onCreate'] : [$option['onCreate']];
                    foreach ($onCreates as $entityName) {
                        $className = 'App\Entity\\' . $entityName;

                        if ($entity::class === $className) {
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

            foreach ($this->options as $option) {
                if (isset($option['onChange'])) {
                    $onChanges = is_array($option['onChange']) ? $option['onChange'] : [$option['onChange']];
                    foreach ($onChanges as $onChange) {
                        [$entityName, $field] = explode(".", $onChange);
                        $className = 'App\Entity\\' . $entityName;

                        if ($entity::class === $className && isset($changes[$field])) {
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
        $em = $postFlushEventArgs->getEntityManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            foreach ($this->options as $option) {
                if (isset($option['afterCreate'])) {
                    $onCreates = is_array($option['afterCreate']) ? $option['afterCreate'] : [$option['afterCreate']];
                    foreach ($onCreates as $entityName) {
                        $className = 'App\Entity\\' . $entityName;

                        if ($entity::class === $className) {
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
    }
}