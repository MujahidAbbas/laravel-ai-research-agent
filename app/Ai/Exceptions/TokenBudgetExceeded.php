<?php

declare(strict_types=1);

namespace App\Ai\Exceptions;

use RuntimeException;

class TokenBudgetExceeded extends RuntimeException
{
    public static function beforeStep(int $step, int $spent, int $estimate, int $ceiling): self
    {
        return new self(sprintf(
            'Refused step %d: %s tokens sent so far + ~%s estimated for this step would exceed the %s-token ceiling.',
            $step, number_format($spent), number_format($estimate), number_format($ceiling),
        ));
    }

    public static function afterStep(int $step, int $spent, int $ceiling): self
    {
        return new self(sprintf(
            'Stopped after step %d: %s tokens sent, over the %s-token ceiling.',
            $step, number_format($spent), number_format($ceiling),
        ));
    }
}
