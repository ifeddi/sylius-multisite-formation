<?php

declare(strict_types=1);

namespace App\Tests\Formation\Module05Workflow;

use App\Formation\Module05Workflow\EventListener\PaymentCompletedListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;

/** Formation M5 : le listener journalise le numéro de commande quand un paiement est complété. */
final class PaymentCompletedListenerTest extends TestCase
{
    public function testItLogsTheOrderNumberAndChannel(): void
    {
        $channel = $this->createStub(ChannelInterface::class);
        $channel->method('getCode')->willReturn('SITE_FR');

        $order = $this->createStub(OrderInterface::class);
        $order->method('getNumber')->willReturn('000000042');
        $order->method('getChannel')->willReturn($channel);

        $payment = $this->createStub(PaymentInterface::class);
        $payment->method('getId')->willReturn(7);
        $payment->method('getOrder')->willReturn($order);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            self::stringContains('paiement complété'),
            self::equalTo(['payment_id' => 7, 'order_number' => '000000042', 'channel' => 'SITE_FR']),
        );

        (new PaymentCompletedListener($logger))(
            new CompletedEvent($payment, new Marking(), new Transition('complete', 'processing', 'completed')),
        );
    }

    public function testItIgnoresOtherSubjects(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        (new PaymentCompletedListener($logger))(
            new CompletedEvent(new \stdClass(), new Marking(), new Transition('complete', 'a', 'b')),
        );
    }
}
