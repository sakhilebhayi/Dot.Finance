<?php

namespace App\Services\Sales;

use App\Models\Sales\SalesProfile;
use App\Models\Shared\DocumentSequence;
use App\Services\Shared\Numbering;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The business profile of a ledger (names, VAT number, address, banking, logo, defaults) and the document
 * number series that go with it. A ledger with no saved profile gets an in-memory default, never a fake one.
 */
final class SalesProfiles
{
    /** doc type => default prefix. Series are created the first time they are needed. */
    public const SERIES = ['invoice' => 'INV', 'quote' => 'QT', 'pro_forma' => 'PF', 'credit_note' => 'CN', 'debit_note' => 'DN'];

    public const LOGO_MAX_KB = 1024;

    public function __construct(private readonly Numbering $numbering) {}

    /** Kept for callers that used to clear a cache; profiles are read fresh every time. */
    public function flush(): void {}

    /** The saved profile, or a default (unsaved) when none exists yet. */
    public function for(LedgerContext $context): SalesProfile
    {
        return SalesProfile::query()->forContext($context)->first() ?? new SalesProfile([
            'vat_registered' => false, 'tax_jurisdiction' => 'ZA', 'default_terms_days' => 30, 'quote_validity_days' => 30,
            'reminder_before_days' => 3, 'reminder_after_days' => [7, 14, 30], 'prices_include_tax' => false,
        ] + $context->ownerAttributes());
    }

    public function exists(LedgerContext $context): bool
    {
        return $this->for($context)->exists;
    }

    /**
     * Create or update the profile. $data holds only validated columns. Returns the saved profile.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(LedgerContext $context, array $data, ?int $actorId = null): SalesProfile
    {
        return DB::transaction(function () use ($context, $data, $actorId) {
            $profile = SalesProfile::query()->forContext($context)->lockForUpdate()->first();
            $creating = $profile === null;
            $before = $creating ? [] : $profile->only(['vat_registered', 'vat_number', 'legal_name', 'default_terms_days']);

            if ($creating) {
                try {
                    $profile = SalesProfile::query()->create($context->ownerAttributes() + $data);
                } catch (UniqueConstraintViolationException) {
                    $profile = SalesProfile::query()->forContext($context)->firstOrFail();
                    $profile->fill($data)->save();
                }
            } else {
                $profile->fill($data);
                $profile->version = (int) $profile->version + 1;
                $profile->save();
            }

            FinanceAudit::record($context->ownerAttributes(), $creating ? 'created' : 'updated', 'sales_profile', $profile->id, 'Business profile', [
                'vat_registered' => ['before' => $before['vat_registered'] ?? null, 'after' => (bool) $profile->vat_registered],
            ], currency: null, actorUserId: $actorId);

            $this->ensureSeries($context);
            $this->flush();

            return $profile;
        });
    }

    /** Store the logo on the private disk. Replaces the old file only after the new one is written. */
    public function storeLogo(LedgerContext $context, UploadedFile $file): SalesProfile
    {
        $profile = $this->for($context);
        $old = $profile->logo_path;
        $disk = (string) config('shared.attachments.disk', 'local');
        $ext = strtolower($file->guessExtension() ?: 'png');
        $path = 'sales-logos/'.$context->key().'/'.Str::uuid().'.'.$ext;
        $contents = (string) file_get_contents($file->getRealPath());
        Storage::disk($disk)->put($path, $contents);

        $profile = $this->save($context, [
            'logo_disk' => $disk, 'logo_path' => $path, 'logo_mime' => $file->getMimeType(), 'logo_sha256' => hash('sha256', $contents),
        ]);

        if ($old !== null && $old !== $path) {
            Storage::disk($disk)->delete($old);
        }

        return $profile;
    }

    /** The logo as a data URI for the printable page and the PDF (the CSP forbids third-party images; this one is ours). */
    public function logoDataUri(SalesProfile $profile): ?string
    {
        if ($profile->logo_path === null) {
            return null;
        }

        $disk = Storage::disk($profile->logo_disk ?? 'local');
        if (! $disk->exists($profile->logo_path)) {
            return null;
        }

        return 'data:'.($profile->logo_mime ?: 'image/png').';base64,'.base64_encode($disk->get($profile->logo_path));
    }

    /** Make sure every document series exists, with its default prefix, without ever touching a counter that has issued numbers. */
    public function ensureSeries(LedgerContext $context): void
    {
        foreach (self::SERIES as $type => $prefix) {
            if (DocumentSequence::query()->forContext($context)->where('doc_type', $type)->exists()) {
                continue;
            }

            try {
                $this->numbering->configure($type, $prefix, 5, false, $context);
            } catch (UniqueConstraintViolationException|QueryException) {
                // someone created it first: theirs stands
            }
        }
    }
}
