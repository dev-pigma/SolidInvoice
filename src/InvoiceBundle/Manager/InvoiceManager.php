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

namespace SolidInvoice\InvoiceBundle\Manager;

use Carbon\Carbon;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use SolidInvoice\InvoiceBundle\Entity\BaseInvoice;
use SolidInvoice\InvoiceBundle\Entity\Invoice;
use SolidInvoice\InvoiceBundle\Entity\Item;
use SolidInvoice\InvoiceBundle\Entity\RecurringInvoice;
use SolidInvoice\InvoiceBundle\Event\InvoiceEvent;
use SolidInvoice\InvoiceBundle\Event\InvoiceEvents;
use SolidInvoice\InvoiceBundle\Exception\InvalidTransitionException;
use SolidInvoice\InvoiceBundle\Model\Graph;
use SolidInvoice\InvoiceBundle\Notification\InvoiceStatusNotification;
use SolidInvoice\NotificationBundle\Notification\NotificationManager;
use SolidInvoice\QuoteBundle\Entity\Quote;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Workflow\StateMachine;

/**
 * @see \SolidInvoice\InvoiceBundle\Tests\Manager\InvoiceManagerTest
 */
class InvoiceManager implements ContainerAwareInterface
{
    use ContainerAwareTrait;

    /**
     * @var ObjectManager
     */
    protected $entityManager;

    /**
     * @var EventDispatcherInterface
     */
    protected $dispatcher;

    /**
     * @var StateMachine
     */
    private $stateMachine;

    /**
     * @var NotificationManager
     */
    private $notification;

    public function __construct(
        ManagerRegistry $doctrine,
        EventDispatcherInterface $dispatcher,
        StateMachine $stateMachine,
        NotificationManager $notification
    ) {
        $this->entityManager = $doctrine->getManager();
        $this->dispatcher = $dispatcher;
        $this->stateMachine = $stateMachine;
        $this->notification = $notification;
    }

    public function createFromQuote(Quote $quote): Invoice
    {
        return $this->createFromObject($quote);
    }

    public function createFromRecurring(RecurringInvoice $recurringInvoice): Invoice
    {
        return $this->createFromObject($recurringInvoice);
    }

    private function createFromObject($object): Invoice
    {
        /** @var RecurringInvoice|Quote $object */
        $invoice = new Invoice();

        $now = Carbon::now();

        $invoice->setCreated($now);
        $invoice->setClient($object->getClient());
        $invoice->setBaseTotal($object->getBaseTotal());
        $invoice->setDiscount($object->getDiscount());
        $invoice->setNotes($object->getNotes());
        $invoice->setTotal($object->getTotal());
        $invoice->setTerms($object->getTerms());
        $invoice->setUsers($object->getUsers()->toArray());
        $invoice->setBalance($invoice->getTotal());

        if (null !== $object->getTax()) {
            $invoice->setTax($object->getTax());
        }

        /** @var \SolidInvoice\QuoteBundle\Entity\Item $item */
        foreach ($object->getItems() as $item) {
            $invoiceItem = new Item();
            $invoiceItem->setCreated($now);
            $invoiceItem->setTotal($item->getTotal());
            $invoiceItem->setDescription($item->getDescription());
            $invoiceItem->setPrice($item->getPrice());
            $invoiceItem->setQty($item->getQty());

            if (null !== $item->getTax()) {
                $invoiceItem->setTax($item->getTax());
            }

            $invoice->addItem($invoiceItem);
        }

        return $invoice;
    }

    /**
     * @throws InvalidTransitionException
     */
    public function create(BaseInvoice $invoice): BaseInvoice
    {
        // Set the invoice status as new and save, before we transition to the correct status
        $invoice->setStatus(Graph::STATUS_NEW);
        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        $this->applyTransition($invoice, Graph::TRANSITION_NEW);

        $this->dispatcher->dispatch(new InvoiceEvent($invoice), InvoiceEvents::INVOICE_PRE_CREATE);

        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        $this->dispatcher->dispatch(new InvoiceEvent($invoice), InvoiceEvents::INVOICE_POST_CREATE);

        return $invoice;
    }

    /**
     * @throws InvalidTransitionException
     */
    private function applyTransition(BaseInvoice $invoice, string $transition): bool
    {
        if ($this->stateMachine->can($invoice, $transition)) {
            $oldStatus = $invoice->getStatus();

            $this->stateMachine->apply($invoice, $transition);

            $newStatus = $invoice->getStatus();

            $parameters = [
                'invoice' => $invoice,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'transition' => $transition,
            ];

            // Do not send status updates for new invoices
            if (Graph::TRANSITION_NEW !== $transition) {
                $notification = new InvoiceStatusNotification($parameters);

                $this->notification->sendNotification('invoice_status_update', $notification);
            }

            return true;
        }

        throw new InvalidTransitionException($transition);
    }
}
