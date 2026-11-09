<?php

namespace App\Http\Controllers\Intelligence;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Category;
use App\Models\Intelligence\AssistantDraft;
use App\Models\Intelligence\AssistantMessage;
use App\Services\Intelligence\DraftExecutor;
use App\Services\Intelligence\FinanceAssistant;
use App\Services\Intelligence\Intent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The assistant page: ask in plain words, read the answer with its sources and basis, review what it drafted.
 *
 * Asking only reads. A draft changes nothing until the confirmation form below is POSTED (CSRF-protected, bound to the
 * draft id and to the signed-in person who was shown it) and DraftExecutor agrees. A person only ever sees and deletes
 * their own exchanges.
 */
class AssistantController extends Controller
{
    public function index(Request $request): View
    {
        $context = $this->ledger();
        $user = $request->user();

        $messages = AssistantMessage::query()->askedBy($user)->with('drafts')->latest('id')->limit((int) config('intelligence.assistant.history_limit', 30))->get();

        return view('intel.assistant', [
            'messages' => $messages,
            'suggestions' => Intent::suggestions(),
            'modelOn' => (bool) config('intelligence.model.enabled') && filled(config('services.anthropic.api_key')),
            'ledger' => $context,
        ]);
    }

    public function ask(Request $request, FinanceAssistant $assistant): RedirectResponse
    {
        $data = $request->validate(['question' => ['required', 'string', 'min:3', 'max:500']]);

        $message = $assistant->ask($request->user(), $this->ledger(), $data['question']);

        return redirect(route('intel.assistant.index').'#answer-'.$message->id);
    }

    public function destroy(Request $request, AssistantMessage $message): RedirectResponse
    {
        abort_unless($message->asker_id === $request->user()->id, 404);

        $message->delete();

        return redirect()->route('intel.assistant.index')->with('status', 'That question and its answer were deleted.');
    }

    public function clear(Request $request): RedirectResponse
    {
        AssistantMessage::query()->askedBy($request->user())->delete();

        return redirect()->route('intel.assistant.index')->with('status', 'Your history in this ledger was deleted.');
    }

    public function draft(Request $request, AssistantDraft $draft): View
    {
        abort_unless($draft->asker_id === $request->user()->id, 404);

        return view('intel.draft', [
            'draft' => $draft,
            'accounts' => Account::orderBy('name')->get(),
            'categories' => Category::orderBy('name')->get(),
        ]);
    }

    public function confirm(Request $request, AssistantDraft $draft, DraftExecutor $executor): RedirectResponse
    {
        abort_unless($draft->asker_id === $request->user()->id, 404);

        $input = $request->validate([
            'account_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['nullable', 'string', 'max:20'],
            'type' => ['nullable', 'in:income,expense'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'items' => ['nullable', 'array', 'max:50'],
            'items.*' => ['integer'],
        ]);

        try {
            $executor->confirm($draft, $request->user(), array_filter($input, fn ($v) => $v !== null && $v !== ''));
        } catch (AuthorizationException) {
            abort(403);
        }

        return redirect()->route('intel.assistant.index')->with('status', 'Confirmed. It has been recorded.');
    }

    public function dismiss(Request $request, AssistantDraft $draft, DraftExecutor $executor): RedirectResponse
    {
        abort_unless($draft->asker_id === $request->user()->id, 404);

        try {
            $executor->dismiss($draft, Auth::user());
        } catch (AuthorizationException) {
            abort(403);
        }

        return redirect()->route('intel.assistant.index')->with('status', 'Draft dismissed. Nothing was recorded.');
    }
}
