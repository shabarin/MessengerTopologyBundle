<?php

namespace Shabarin\MessengerTopologyBundle\Tests;

use Shabarin\MessengerTopologyBundle\ModuleOrder;
use PHPUnit\Framework\TestCase;

class ModuleOrderTest extends TestCase
{
    public function testFollowsTheFlowOfMessagesInsteadOfTheAlphabet(): void
    {
        // Search → Booking → Billing: alphabetically it would be Billing, Booking, Search.
        $order = ModuleOrder::topological(
            ['Billing', 'Booking', 'Search'],
            [['Search', 'Booking'], ['Booking', 'Billing']],
        );

        self::assertSame(['Search', 'Booking', 'Billing'], $order);
    }

    public function testTiesAndIsolatedModulesAreAlphabetical(): void
    {
        $order = ModuleOrder::topological(
            ['Zeta', 'Alpha', 'Mid', 'Lonely'],
            [['Zeta', 'Mid'], ['Alpha', 'Mid']],
        );

        self::assertSame(['Alpha', 'Lonely', 'Zeta', 'Mid'], $order);
    }

    public function testCyclesAreBrokenDeterministically(): void
    {
        // Billing ↔ Booking is a cycle and Search leads into it. The snapshot matters by itself: node
        // order drives the diagram layout and must be the same from run to run.
        $edges = [['Billing', 'Booking'], ['Booking', 'Billing'], ['Search', 'Booking']];

        $order = ModuleOrder::topological(['Billing', 'Booking', 'Search'], $edges);

        self::assertSame(['Search', 'Billing', 'Booking'], $order, 'after Search a cycle with equal degrees remains; it is broken alphabetically');
        self::assertSame($order, ModuleOrder::topological(['Search', 'Booking', 'Billing'], array_reverse($edges)), 'independent of the input order');
    }
}
