<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\CacheToolDefinitions;

/**
 * Same agent, with Anthropic cache breakpoints on the fixed prefix only:
 * the instructions and the tool definitions. The growing message history is
 * NOT cached. Attributes are read off the concrete class, hence a subclass.
 */
#[CacheInstructions]
#[CacheToolDefinitions]
class PrefixCachedResearchAgent extends ResearchAgent
{
    public function __construct()
    {
        parent::__construct(caching: 'prefix');
    }
}
