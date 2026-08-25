<?php

namespace Tests\Unit;

use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\StateMachines\TransactionStateMachine;
use App\Exceptions\InvalidStateTransitionException;
use PHPUnit\Framework\TestCase;

final class TransactionStateMachineTest extends TestCase
{
    public function test_happy_path_transitions(): void
    {
        $this->assertTrue(TransactionStateMachine::canTransition(TransactionStatus::Initiated, TransactionStatus::Pending));
        $this->assertTrue(TransactionStateMachine::canTransition(TransactionStatus::Pending, TransactionStatus::Processing));
        $this->assertTrue(TransactionStateMachine::canTransition(TransactionStatus::Processing, TransactionStatus::Success));
        $this->assertTrue(TransactionStateMachine::canTransition(TransactionStatus::Success, TransactionStatus::Completed));
    }

    public function test_ambiguous_path_requires_verification(): void
    {
        $this->assertTrue(TransactionStateMachine::canTransition(TransactionStatus::Processing, TransactionStatus::Ambiguous));
        $this->assertTrue(TransactionStateMachine::canTransition(TransactionStatus::Ambiguous, TransactionStatus::Verifying));
        $this->assertTrue(TransactionStateMachine::canTransition(TransactionStatus::Verifying, TransactionStatus::Success));
        $this->assertTrue(TransactionStateMachine::canTransition(TransactionStatus::Verifying, TransactionStatus::Failed));
    }

    public function test_failed_path_from_processing(): void
    {
        $this->assertTrue(TransactionStateMachine::canTransition(TransactionStatus::Processing, TransactionStatus::Failed));
        $this->assertTrue(TransactionStateMachine::canTransition(TransactionStatus::Pending, TransactionStatus::Failed));
    }

    public function test_illegal_jumps_are_rejected(): void
    {
        // Cannot skip to COMPLETED without going through SUCCESS.
        $this->assertFalse(TransactionStateMachine::canTransition(TransactionStatus::Processing, TransactionStatus::Completed));
        // Cannot go backwards from PROCESSING.
        $this->assertFalse(TransactionStateMachine::canTransition(TransactionStatus::Processing, TransactionStatus::Pending));
        // AMBIGUOUS cannot jump straight to COMPLETED.
        $this->assertFalse(TransactionStateMachine::canTransition(TransactionStatus::Ambiguous, TransactionStatus::Completed));
    }

    public function test_terminal_states_cannot_transition(): void
    {
        $this->assertTrue(TransactionStateMachine::isTerminal(TransactionStatus::Completed));
        $this->assertTrue(TransactionStateMachine::isTerminal(TransactionStatus::Failed));
        $this->assertTrue(TransactionStateMachine::isTerminal(TransactionStatus::Reversed));
        $this->assertFalse(TransactionStateMachine::canTransition(TransactionStatus::Completed, TransactionStatus::Failed));
    }

    public function test_assert_throws_on_invalid_transition(): void
    {
        $this->expectException(InvalidStateTransitionException::class);

        TransactionStateMachine::assertCanTransition(TransactionStatus::Completed, TransactionStatus::Failed);
    }
}
