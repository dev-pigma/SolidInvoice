<?php

declare(strict_types=1);

/*
 * This file is part of SolidInvoice project.
 *
 * (c) Pierre du Plessis <open-source@solidworx.co>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace SolidInvoice\ClientBundle\Listener;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Events;
use SolidInvoice\ClientBundle\Entity\Client;
use SolidInvoice\ClientBundle\Entity\Credit;
use SolidInvoice\ClientBundle\Model\Status;
use SolidInvoice\ClientBundle\Notification\ClientCreateNotification;
use SolidInvoice\InvoiceBundle\Entity\Invoice;
use SolidInvoice\NotificationBundle\Notification\NotificationManager;
use SolidInvoice\PaymentBundle\Entity\Payment;
use SolidInvoice\QuoteBundle\Entity\Quote;

class ClientListener implements EventSubscriber
{
    /**
     * @var NotificationManager
     */
    private $notification;

    public function __construct(NotificationManager $notification)
    {
        $this->notification = $notification;
    }

    public function getSubscribedEvents()
    {
        return [
            Events::prePersist,
            Events::postPersist,
            Events::postUpdate,
        ];
    }

    public function prePersist(LifecycleEventArgs $event): void
    {
        $entity = $event->getEntity();

        if (! $entity instanceof Client) {
            return;
        }

        if (! $entity->getId() && ! $entity->getStatus()) {
            $entity->setStatus(Status::STATUS_ACTIVE);
        }
    }

    public function postPersist(LifecycleEventArgs $event): void
    {
        $entity = $event->getEntity();

        if (! $entity instanceof Client) {
            return;
        }

        // client is created
        $notification = new ClientCreateNotification(['client' => $entity]);

        $this->notification->sendNotification('client_create', $notification);
    }

    public function postUpdate(LifecycleEventArgs $event): void
    {
        $entity = $event->getEntity();

        if (! $entity instanceof Client) {
            return;
        }

        $entityChangeSet = $event->getEntityManager()->getUnitOfWork()->getEntityChangeSet($entity);

        // Only update the currencies when the client currency changed
        if (array_key_exists('currency', $entityChangeSet)) {
            $em = $event->getEntityManager();

            $em->getRepository(Invoice::class)->updateCurrency($entity);
            $em->getRepository(Quote::class)->updateCurrency($entity);
            $em->getRepository(Payment::class)->updateCurrency($entity);
            $em->getRepository(Credit::class)->updateCurrency($entity);
        }
    }
}
