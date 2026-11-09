<?php

namespace App\Services\Intelligence\Handlers;

use App\Models\Intelligence\AssistantDraft;
use App\Models\Intelligence\FinanceInsight;
use App\Models\User;
use App\Services\Controls\ApprovalService;
use App\Services\Intelligence\Answer;
use App\Services\Intelligence\Intent;
use App\Services\Intelligence\RecognisedIntent;
use App\Support\Ledger\LedgerContext;
use App\Support\Ui\StateLine;

/** "What needs attention?" The state line, the open findings, approvals waiting for this person and drafts waiting to be confirmed. */
final class AttentionHandler extends Handler
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function intents(): array
    {
        return [Intent::NeedsAttention];
    }

    public function handle(RecognisedIntent $question, LedgerContext $context, ?User $user = null): Answer
    {
        $items = [];

        foreach (StateLine::for($context) as $item) {
            // The findings are listed below in full; the state line's own entry for them would repeat them.
            if (($item['key'] ?? '') === 'attention-findings') {
                continue;
            }
            $items[] = ['text' => (string) $item['text'], 'href' => $item['href'] ?? null, 'rank' => ($item['tone'] ?? '') === 'variance' ? 3 : 1];
        }

        $findings = FinanceInsight::query()->forContext($context)->visible()->get()->sortByDesc(fn (FinanceInsight $f) => [$f->severityRank(), $f->last_seen_at?->timestamp])->values();
        foreach ($findings->take(6) as $finding) {
            $items[] = ['text' => $finding->title, 'href' => $finding->href ?: (fx_route('intel.attention.index')), 'rank' => $finding->severityRank()];
        }

        if ($user !== null) {
            $waiting = $this->approvals->waitingFor($user, $context)->count();
            if ($waiting > 0) {
                $items[] = ['text' => $this->plural($waiting, 'approval is', 'approvals are').' waiting for you', 'href' => fx_route(['controls.approvals.index', 'approvals.index']), 'rank' => 2];
            }

            $drafts = AssistantDraft::query()->forContext($context)->where('asker_id', $user->id)->where('status', 'pending')->where('expires_at', '>', now())->count();
            if ($drafts > 0) {
                $items[] = ['text' => $this->plural($drafts, 'draft is', 'drafts are').' waiting for you to review and confirm', 'href' => fx_route('intel.assistant.index'), 'rank' => 1];
            }
        }

        $link = $this->sources(['What needs attention', fx_route('intel.attention.index')]);

        if ($items === []) {
            return new Answer(Intent::NeedsAttention, Answer::ANSWERED, 'Nothing needs your attention right now.', sources: $link, notes: ['This checks the entries recorded in this ledger. It cannot see anything that has not been entered.']);
        }

        usort($items, fn ($a, $b) => $b['rank'] <=> $a['rank']);

        $rows = [];
        foreach (array_slice($items, 0, 8) as $item) {
            $rows[] = ['cells' => [$item['text']], 'href' => $item['href']];
        }

        return new Answer(
            Intent::NeedsAttention,
            Answer::ANSWERED,
            $this->plural(count($items), 'thing needs', 'things need').' your attention; the first is: '.$items[0]['text'].'.',
            columns: ['What'],
            rows: $rows,
            sources: $link,
            notes: ['Ask me to "categorise my uncategorised transactions" and I will draft categories for you to review.'],
        );
    }
}
