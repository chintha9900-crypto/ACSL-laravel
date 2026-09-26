<?php

namespace App\Actions\Membership;

use App\Models\Membership;
use LogicException;
use SensitiveParameter;

/**
 * The outcome of an activation: the committed membership plus the one-time setup
 * token, held only in memory so the link can be delivered after the commit.
 *
 * The plaintext exists nowhere else (the database keeps only its hash). It is kept
 * out of var_dump()/dd() output and cannot be serialised, so it cannot end up in a
 * queue payload, cache or log by accident.
 */
final class ActivatedMembership
{
    public function __construct(
        public readonly Membership $membership,
        #[SensitiveParameter] public readonly string $setupToken,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['membership' => $this->membership->getKey(), 'setupToken' => '[redacted]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('An activated membership carries a one-time secret and cannot be serialised.');
    }
}
