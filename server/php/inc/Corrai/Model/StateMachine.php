<?php
namespace Corrai\Model;

interface StateMachine
{
    public function canTransition(
        object $object,
        string $event
    ): bool;

    public function transition(
        object $object,
        string $event
    ): TransitionResult;
}