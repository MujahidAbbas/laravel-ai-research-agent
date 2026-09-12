<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\History\HistoryPolicy;
use App\Ai\History\KeepEverything;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\Conversational;

/**
 * The research agent, remembered across turns.
 *
 * RemembersConversations gives persistence and a messages() that loads the
 * last 100 rows verbatim. Defining messages() on the class shadows the
 * trait's version (class methods win over trait methods), which is the one
 * place a history policy can be applied, and also the trap: an override that
 * forgets to load from the store silently sends no history at all.
 */
class ConversationalResearchAgent extends ResearchAgent implements Conversational
{
    use RemembersConversations;

    public function __construct(
        public HistoryPolicy $history = new KeepEverything,
        string $caching = 'default',
    ) {
        parent::__construct($caching);
    }

    /**
     * @return \Laravel\Ai\Messages\Message[]
     */
    public function messages(): iterable
    {
        if (! $this->conversationId) {
            return [];
        }

        $loaded = resolve(ConversationStore::class)
            ->getLatestConversationMessages($this->conversationId, $this->maxConversationMessages())
            ->all();

        return $this->history->apply($loaded, $this->conversationId);
    }
}
