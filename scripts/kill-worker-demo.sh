#!/bin/zsh
# Queue the research agent, kill the worker mid-run, watch the retry.
#
#   scripts/kill-worker-demo.sh sdk      # ->queue(): worker --timeout=20, retry re-plans from the prompt
#   scripts/kill-worker-demo.sh sigkill  # RunDurableResearch: kill -9 at 20 s, retry resumes from the checkpoint
#   scripts/kill-worker-demo.sh sigterm  # RunDurableResearch: SIGTERM at 20 s, clean stop between steps, retry resumes
#
# Every queue and agent event lands in storage/app/private/runs/<mode>.jsonl (see App\Ai\QueueRunLog).
set -e
cd "$(dirname "$0")/.."
MODE=${1:-sigkill}
TOPIC=${2:-"Laravel queued jobs that call an LLM agent: timeouts, retries and duplicate tool calls"}
KILL_AT=${KILL_AT:-20}
# The config default (300) sits above the job's $timeout, as it should in production. A killed
# job waits out retry_after before the next worker sees it, so the demo shortens it to 90 s.
export DB_QUEUE_RETRY_AFTER=${DB_QUEUE_RETRY_AFTER:-90}
export QUEUE_RUN_LOG="runs/$(date -u +%Y-%m-%d-%H%M%S)-$MODE.jsonl"

if [[ $(sqlite3 database/database.sqlite 'select count(*) from jobs') != 0 ]]; then
    echo "jobs table is not empty; drain it first" >&2; exit 1
fi

case $MODE in
    sdk)
        php artisan ai:research-queued "$TOPIC"
        php artisan queue:work database --max-jobs=1 --timeout=$KILL_AT --tries=2 --sleep=1 -v || echo "worker exit=$? (137 = SIGKILL from the timeout handler)"
        echo "job stays reserved for retry_after (90 s); the next worker picks it up then"
        php artisan queue:work database --max-jobs=1 --timeout=300 --tries=2 --sleep=1 -v
        ;;
    sigkill|sigterm)
        php artisan ai:research-durable "$TOPIC"
        php artisan queue:work database --max-jobs=1 --sleep=1 -v & WPID=$!
        sleep $KILL_AT
        [[ $MODE == sigkill ]] && kill -9 $WPID || kill -TERM $WPID
        wait $WPID || echo "worker exit=$?"
        php artisan queue:work database --max-jobs=1 --sleep=1 -v
        ;;
    *) echo "unknown mode $MODE" >&2; exit 1 ;;
esac

echo "log: storage/app/private/$QUEUE_RUN_LOG"
