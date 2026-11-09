<?php

namespace App\Console\Commands;

use App\Enums\FinanceRole;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Controls\ApprovalPolicy;
use App\Models\Controls\ApprovalRequest;
use App\Models\Controls\SpendLimit;
use App\Models\Shared\Party;
use App\Models\Team;
use App\Models\User;
use App\Services\Accounting\ChartTemplates;
use App\Services\Accounting\GlBackfill;
use App\Services\Accounting\GlSync;
use App\Services\Accounting\Periods;
use App\Services\Controls\AccessReviews;
use App\Services\Controls\ApprovalPolicies;
use App\Services\Controls\ApprovalPresets;
use App\Services\Controls\LedgerRoles;
use App\Services\Controls\SpendLimits;
use App\Services\Shared\Attachments;
use App\Services\Shared\FxService;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;

/**
 * LOCAL DEMO DATA ONLY, a second layer on top of `finance:demo-seed` (run that first). It puts the accounting, parties
 * and controls screens into realistic states for looking at them: accounting switched on through the real backfill
 * (so the journals, trial balance and periods are real), a handful of fictional customers and suppliers, four fictional
 * team members at different roles, approval policies from the real presets, spend limits, an access review and two
 * closed months. Everything goes through the real services; nothing here is a real person or business.
 *
 * Refuses outside local/testing; running it twice is safe (it skips what exists).
 */
#[Signature('finance:demo-controls')]
#[Description('Local only: switch accounting on for the demo company and add parties, members, policies and limits')]
class DemoControlsSeed extends Command
{
    public function handle(Periods $periods, LedgerRoles $roles, ApprovalPolicies $policies, SpendLimits $limits, AccessReviews $reviews, Attachments $attachments): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('finance:demo-controls only runs in a local or testing environment.');

            return self::FAILURE;
        }

        $owner = User::query()->where('email', DemoSeed::EMAIL)->first();
        $company = $owner ? Team::query()->where('user_id', $owner->id)->where('name', DemoSeed::COMPANY)->first() : null;
        if (! $owner || ! $company) {
            $this->error('Run finance:demo-seed first.');

            return self::FAILURE;
        }

        $context = LedgerContext::company($company);

        // The demo company keeps one USD account. The posting engine converts at a rate on record (FxServiceRateProvider, the one
        // binding), so the demo records a user-provided USD/ZAR rate every week for the last 14 months: clearly a local stand-in,
        // entered through the real exchange-rate service as a ledger rate, never a published one.
        $fx = app(FxService::class);
        for ($day = CarbonImmutable::today()->subDays(430); $day->lte(CarbonImmutable::today()); $day = $day->addDays(7)) {
            $fx->record('USD', 'ZAR', 1_840_000_000, $day, 'manual', $owner, 'Demo stand-in rate (not a market rate)', false, $context);
        }
        app()->forgetScopedInstances();
        $backfill = app()->make(GlBackfill::class);

        // Members: InfoDot role (the team pivot) plus, for some, a narrower or different local role.
        $people = [
            ['Pieter van Wyk', 'pieter@karoo.test', 'admin', FinanceRole::FinanceManager],
            ['Thandi Mokoena', 'thandi@karoo.test', 'editor', FinanceRole::Accountant],
            ['Ayesha Patel', 'ayesha@karoo.test', 'editor', null],
            ['Sipho Nkosi', 'sipho@karoo.test', 'viewer', FinanceRole::Approver],
        ];
        $members = [];
        foreach ($people as [$name, $email, $infodot, $role]) {
            $user = User::query()->where('email', $email)->first() ?? User::forceCreate([
                'name' => $name, 'email' => $email, 'email_verified_at' => now(), 'password' => Hash::make(DemoSeed::PASSWORD),
            ]);
            if (! $company->users()->where('users.id', $user->id)->exists()) {
                $company->users()->attach($user, ['role' => $infodot]);
            }
            $members[$email] = $user;
            if ($role !== null) {
                $roles->assign($owner, $context, $user, $role);
            }
        }

        // Accounting through the real backfill, only if it is not on yet.
        LedgerContext::run($context, function () use ($backfill, $context, $owner, $periods): void {
            $settings = $backfill->prepare($context, 'ZAR', 3, ChartTemplates::COMPANY);
            if (! $settings->isEnabled()) {
                $run = $backfill->apply($context, $owner);
                $this->line('Backfill '.$run->status.': '.$run->entries_posted.' entries.');
                if ($run->status === 'verified') {
                    $backfill->enable($context);
                }
            }
            if ($backfill->prepare($context, 'ZAR', 3, ChartTemplates::COMPANY)->isEnabled()) {
                $periods->closeThrough($context, CarbonImmutable::now()->subMonths(3)->endOfMonth(), $owner);
            }
        });

        // Parties and a document.
        LedgerContext::run($context, function () use ($company, $attachments, $owner, $context): void {
            if (Party::query()->exists()) {
                return;
            }
            $owned = ['user_id' => null, 'team_id' => $company->id];
            $rows = [
                ['customer', 'Salt River Deli', 'Salt River Deli (Pty) Ltd', 'orders@saltriverdeli.test', '021 555 0142', '4210198765', '2014/118233/07', 30, 2500000, 'ZAR', 'Cape Town'],
                ['customer', 'Bo-Kaap Bakehouse', null, 'accounts@bokaapbakehouse.test', '021 555 0177', null, null, 14, null, 'ZAR', 'Cape Town'],
                ['customer', 'Observatory Co-working', 'Obs Workspaces CC', 'hello@obscowork.test', '021 555 0108', '4455667788', null, 30, 1500000, 'ZAR', 'Cape Town'],
                ['both', 'Gardens Pantry', null, 'finance@gardenspantry.test', '021 555 0166', '4300112233', null, 21, 800000, 'ZAR', 'Cape Town'],
                ['supplier', 'Cerrado Green Coffee Importers', 'Cerrado Importers (Pty) Ltd', 'sales@cerradoimport.test', '011 555 0191', '4900554433', '2009/004455/07', 30, null, 'USD', 'Johannesburg'],
                ['supplier', 'Woodstock Print and Pack', null, 'billing@woodstockpack.test', '021 555 0123', '4120998877', null, 7, null, 'ZAR', 'Cape Town'],
                ['supplier', 'City of Cape Town', null, null, null, null, null, null, null, 'ZAR', 'Cape Town'],
            ];
            $first = null;
            foreach ($rows as [$type, $name, $legal, $email, $phone, $vat, $reg, $terms, $limit, $currency, $city]) {
                $party = Party::create($owned + [
                    'type' => $type, 'name' => $name, 'legal_name' => $legal, 'email' => $email, 'phone' => $phone, 'tax_number' => $vat,
                    'registration_number' => $reg, 'payment_terms_days' => $terms, 'credit_limit_cents' => $limit, 'currency' => $currency,
                    'billing_address' => ['line1' => '14 Albert Road', 'suburb' => 'Woodstock', 'city' => $city, 'province' => 'Western Cape', 'postal_code' => '7925', 'country' => 'South Africa'],
                    'notes' => $name === 'Salt River Deli' ? 'Orders every Tuesday. Pays by EFT on the 28th.' : null,
                ]);
                $first ??= $party;
            }
            Party::create($owned + ['type' => 'customer', 'name' => 'Constantia Market Stall', 'email' => 'stall@constantia.test'])->delete();

            $path = tempnam(sys_get_temp_dir(), 'demo').'.pdf';
            file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
            try {
                $attachments->store(new UploadedFile($path, 'wholesale-agreement-2026.pdf', 'application/pdf', null, true), $first, $owner, false, $context);
            } catch (\Throwable $e) {
                $this->warn('Document not attached: '.$e->getMessage());
            }
        });

        // Controls: presets, a limit, a review, and approvals waiting.
        $policies->applyPreset($owner, $context, 'approver_expenses', 500_000, 'ZAR');
        foreach (array_keys(ApprovalPresets::all()) as $key) {
            if ($key !== 'approver_expenses') {
                try {
                    $policies->applyPreset($owner, $context, $key, null, 'ZAR');
                    break;
                } catch (\Throwable) {
                }
            }
        }
        if (! SpendLimit::query()->forContext($context)->exists()) {
            $limits->set($owner, $context, $members['sipho@karoo.test'], null, 'ZAR', 'monthly', 2_000_000);
            $limits->set($owner, $context, null, FinanceRole::Accountant, 'ZAR', 'monthly', 10_000_000);
        }
        if (! $reviews->latest($context)) {
            $reviews->record($owner, $context, 'Checked after Ayesha joined. Everyone listed still works here.');
        }

        LedgerContext::run($context, function () use ($context, $company, $members): void {
            if (ApprovalRequest::query()->exists()) {
                return;
            }
            $policy = ApprovalPolicy::query()->forContext($context)->first();
            // Stand-in subjects: the bill and invoice modules do not exist on this branch, so the inbox is shown with two requests
            // whose subject is a labelled placeholder. They exist only in this local database.
            foreach ([['Bill B-0007 Cerrado Green Coffee', 'bill', 7_450_000], ['Bill B-0008 Woodstock Print and Pack', 'bill', 1_240_000]] as $i => [$label, $kind, $cents]) {
                ApprovalRequest::create([
                    'team_id' => $company->id, 'approval_policy_id' => $policy?->id, 'subject_kind' => $kind,
                    'approvable_type' => 'demo.bill', 'approvable_id' => 9000 + $i, 'label' => $label, 'amount_cents' => $cents, 'currency' => 'ZAR',
                    'snapshot' => ['kind' => $kind, 'amount_cents' => $cents, 'currency' => 'ZAR', 'label' => $label],
                    'requested_by' => $members['ayesha@karoo.test']->id, 'requested_at' => now()->subHours(5 + $i * 20), 'status' => 'pending',
                    'approver_capability' => 'approve-bill', 'min_role' => null, 'steps_required' => 1, 'steps_done' => 0, 'allow_self_approval' => false,
                ]);
            }
        });

        LedgerContext::run($context, function () use ($context): void {
            $this->info('Demo controls ready: accounting '.(app(GlSync::class)->isEnabled($context) ? 'on' : 'still off (see the review page)').', '.Party::query()->count().' parties, '.AccountingPeriod::query()->whereNotNull('closed_at')->count().' closed months, 4 members, presets, limits, a review.');
        });

        return self::SUCCESS;
    }
}
