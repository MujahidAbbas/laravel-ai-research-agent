<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One rolling summary per remembered conversation, written by the
 * SummaryCheckpoint history policy.
 */
class ConversationSummary extends Model
{
    protected $fillable = ['conversation_id', 'covers_messages', 'summary', 'usage'];

    protected function casts(): array
    {
        return [
            'covers_messages' => 'integer',
            'usage' => 'array',
        ];
    }
}
