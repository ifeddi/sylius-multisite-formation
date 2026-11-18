<?php

declare(strict_types=1);

namespace App\Formation\Module05Workflow\EventListener;

use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * Formation M5 : hook après la transition « complete » du workflow de paiement.
 *
 * Sylius 2.3 utilise Symfony Workflow par défaut (sylius_state_machine_abstraction.default_adapter).
 * Nom d'événement : workflow.<workflow>.completed.<transition>.
 */
final class PaymentCompletedListener
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    #[AsEventListener(event: 'workflow.sylius_payment.completed.complete')]
    public function __invoke(CompletedEvent $event): void
    {
        $payment = $event->getSubject();

        if (!$payment instanceof PaymentInterface) {
            return;
        }

        $this->logger->info('Formation M5 : paiement complété', [
            'payment_id' => $payment->getId(),
            'order_number' => $payment->getOrder()?->getNumber(),
            'channel' => $payment->getOrder()?->getChannel()?->getCode(),
        ]);
    }
}
