<?php

namespace Tests\Feature\Shared;

use App\Models\Insight;
use App\Models\Shared\Attachment;
use App\Models\Shared\Party;
use App\Services\Shared\Attachments;
use App\Support\Ledger\LedgerContext;
use App\Support\Shared\Exceptions\AttachmentRejected;
use App\Support\Shared\Exceptions\DuplicateAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use LogicException;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

class AttachmentsTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function service(): Attachments
    {
        return app(Attachments::class);
    }

    private function pdf(string $name = 'receipt.pdf', string $body = 'one'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% {$body}\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    private function png(string $name = 'photo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    // ── storing ───────────────────────────────────────────────────────

    public function test_a_file_is_stored_privately_with_its_hash_and_details(): void
    {
        $user = $this->user();
        $this->inPersonal($user);
        $party = Party::factory()->create(['user_id' => $user->id]);
        $file = $this->pdf('Receipt 12.pdf');

        $attachment = $this->service()->store($file, $party, $user);

        $this->assertSame(hash('sha256', file_get_contents($file->getRealPath())), $attachment->sha256);
        $this->assertSame('application/pdf', $attachment->mime);
        $this->assertSame($file->getSize(), $attachment->size);
        $this->assertSame('Receipt 12.pdf', $attachment->original_name);
        $this->assertSame('local', $attachment->disk);
        $this->assertSame($user->id, $attachment->user_id);
        $this->assertNull($attachment->team_id);
        $this->assertSame($user->id, $attachment->uploaded_by);
        $this->assertSame($party->getMorphClass(), $attachment->attachable_type);
        $this->assertSame($party->id, $attachment->attachable_id);
        $this->assertSame('none', $attachment->extraction_status);
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertStringStartsWith('finance-attachments/personal-'.$user->id.'/', $attachment->path);
        $this->assertStringEndsWith('.pdf', $attachment->path);
        $this->assertStringNotContainsString('Receipt', $attachment->path, 'the client\'s file name never reaches the path');
        $this->assertTrue($this->service()->verify($attachment));
    }

    public function test_it_never_uses_the_public_disk(): void
    {
        $this->assertNotSame('public', config('shared.attachments.disk'));
        $this->assertSame('private', config('filesystems.disks.'.config('shared.attachments.disk').'.visibility', 'private'));
    }

    public function test_the_stored_type_comes_from_the_content_not_the_name(): void
    {
        $this->inPersonal($this->user());

        // html dressed up as a pdf
        try {
            $this->service()->store(UploadedFile::fake()->createWithContent('invoice.pdf', '<html><script>alert(1)</script></html>'));
            $this->fail('html must be refused');
        } catch (AttachmentRejected $e) {
            $this->assertStringContainsString('not accepted', $e->getMessage());
        }

        // a real png named .txt is stored as a png
        $stored = $this->service()->store($this->png('notes.txt'));
        $this->assertSame('image/png', $stored->mime);
        $this->assertStringEndsWith('.png', $stored->path);
    }

    public function test_script_capable_and_executable_types_are_refused(): void
    {
        $this->inPersonal($this->user());

        $bad = [
            'page.html' => '<!doctype html><html></html>',
            'image.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>',
            'run.php' => '<?php echo 1;',
            'run.sh' => "#!/bin/sh\necho hi\n",
            'data.json' => '{"a":1}',
            'blob.bin' => "\x00\x01\x02\x03\xfe\xff".str_repeat("\x00\x9f\x80", 20), // fixed bytes: random ones are sometimes sniffed as text and accepted (flaky)
        ];

        foreach ($bad as $name => $content) {
            try {
                $this->service()->store(UploadedFile::fake()->createWithContent($name, $content));
                $this->fail("{$name} must be refused");
            } catch (AttachmentRejected) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, Attachment::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_csv_text_and_images_are_accepted(): void
    {
        $this->inPersonal($this->user());

        $this->assertSame('text/csv', $this->service()->store(UploadedFile::fake()->createWithContent('bank.csv', "date,amount\n2026-01-01,10.00\n"))->mime);
        $this->assertSame('text/plain', $this->service()->store(UploadedFile::fake()->createWithContent('note.txt', "a plain note\n"))->mime);
        $this->assertSame('image/png', $this->service()->store($this->png())->mime);
    }

    public function test_size_limits(): void
    {
        $this->inPersonal($this->user());
        config(['shared.attachments.max_bytes' => 200]);

        $this->service()->store(UploadedFile::fake()->createWithContent('small.txt', str_repeat('a', 200)));

        try {
            $this->service()->store(UploadedFile::fake()->createWithContent('big.txt', str_repeat('b', 201)));
            $this->fail('too large');
        } catch (AttachmentRejected $e) {
            $this->assertStringContainsString('larger than', $e->getMessage());
        }

        try {
            $this->service()->store(UploadedFile::fake()->createWithContent('empty.txt', ''));
            $this->fail('empty');
        } catch (AttachmentRejected $e) {
            $this->assertStringContainsString('empty', $e->getMessage());
        }

        $this->assertSame(1, Attachment::query()->count());
    }

    public function test_the_display_name_is_cleaned_but_never_used_as_a_path(): void
    {
        $this->inPersonal($this->user());

        $stored = $this->service()->store($this->pdf('../../etc/passwd.pdf'));

        $this->assertSame('passwd.pdf', $stored->original_name);
        $this->assertStringNotContainsString('..', $stored->path);
        $this->assertStringNotContainsString('passwd', $stored->path);
    }

    // ── duplicates and overwriting ────────────────────────────────────

    public function test_the_same_file_in_the_same_ledger_is_a_duplicate(): void
    {
        $this->inPersonal($this->user());
        $first = $this->service()->store($this->pdf('a.pdf', 'same'));

        try {
            $this->service()->store($this->pdf('b.pdf', 'same'));
            $this->fail('duplicate');
        } catch (DuplicateAttachment $e) {
            $this->assertTrue($e->existing->is($first));
            $this->assertStringContainsString('a.pdf', $e->getMessage());
        }

        $this->assertSame(1, Attachment::query()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_a_deliberate_second_copy_gets_its_own_path_and_the_original_is_untouched(): void
    {
        $this->inPersonal($this->user());
        $first = $this->service()->store($this->pdf('a.pdf', 'same'));
        $bytes = Storage::disk('local')->get($first->path);

        $second = $this->service()->store($this->pdf('b.pdf', 'same'), allowDuplicate: true);

        $this->assertNotSame($first->path, $second->path);
        $this->assertSame($first->sha256, $second->sha256);
        $this->assertSame($bytes, Storage::disk('local')->get($first->path));
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function test_attaching_the_same_file_to_the_same_record_again_returns_the_existing_one(): void
    {
        $user = $this->user();
        $this->inPersonal($user);
        $party = Party::factory()->create(['user_id' => $user->id]);

        $a = $this->service()->store($this->pdf('a.pdf', 'same'), $party);
        $b = $this->service()->store($this->pdf('a.pdf', 'same'), $party);

        $this->assertTrue($a->is($b));
        $this->assertSame(1, Attachment::query()->count());
    }

    public function test_the_same_file_in_another_ledger_is_not_a_duplicate(): void
    {
        $alice = $this->user();
        $bob = $this->user();

        $this->service()->store($this->pdf('a.pdf', 'same'), context: LedgerContext::personal($alice));
        $other = $this->service()->store($this->pdf('a.pdf', 'same'), context: LedgerContext::personal($bob));

        $this->assertSame($bob->id, $other->user_id);
        $this->assertNull($this->service()->findDuplicate($other->sha256, LedgerContext::personal($this->user())));
        $this->assertNotNull($this->service()->findDuplicate($other->sha256, LedgerContext::personal($bob)));
    }

    public function test_a_stored_file_record_cannot_be_rewritten_or_deleted(): void
    {
        $this->inPersonal($this->user());
        $attachment = $this->service()->store($this->pdf());

        foreach (['path' => 'other/path.pdf', 'sha256' => str_repeat('c', 64), 'size' => 1, 'original_name' => 'x.pdf', 'mime' => 'text/plain', 'disk' => 'public'] as $column => $value) {
            try {
                $attachment->{$column} = $value;
                $attachment->save();
                $this->fail("{$column} must be frozen");
            } catch (LogicException) {
                $attachment = $attachment->fresh();
            }
        }

        $this->assertThrows(fn () => $attachment->delete(), LogicException::class);
    }

    public function test_archiving_keeps_the_file_and_the_record(): void
    {
        $this->inPersonal($this->user());
        $attachment = $this->service()->store($this->pdf());

        $this->service()->archive($attachment);

        $this->assertTrue($attachment->fresh()->isArchived());
        Storage::disk('local')->assertExists($attachment->path);
    }

    // ── ledger and record checks ──────────────────────────────────────

    public function test_a_file_cannot_be_attached_to_a_record_in_another_ledger(): void
    {
        $alice = $this->user();
        $bob = $this->user();
        $bobsParty = Party::factory()->create(['user_id' => $bob->id]);

        $this->expectException(AttachmentRejected::class);
        $this->service()->store($this->pdf(), $bobsParty, context: LedgerContext::personal($alice));
    }

    public function test_an_inbox_file_can_be_attached_once(): void
    {
        $user = $this->user();
        $this->inPersonal($user);
        $party = Party::factory()->create(['user_id' => $user->id]);
        $other = Party::factory()->create(['user_id' => $user->id]);

        $inbox = $this->service()->store($this->pdf());
        $this->assertNull($inbox->attachable_type);

        $this->service()->attach($inbox, $party);
        $this->assertSame($party->id, $inbox->fresh()->attachable_id);

        $this->expectException(LogicException::class);
        $this->service()->attach($inbox->fresh(), $other);
    }

    public function test_verify_notices_a_changed_or_missing_file(): void
    {
        $this->inPersonal($this->user());
        $attachment = $this->service()->store($this->pdf());

        $this->assertTrue($this->service()->verify($attachment));

        Storage::disk('local')->put($attachment->path, 'tampered');
        $this->assertFalse($this->service()->verify($attachment));

        Storage::disk('local')->delete($attachment->path);
        $this->assertFalse($this->service()->verify($attachment));
    }

    // ── extraction drafts ─────────────────────────────────────────────

    public function test_an_extraction_is_a_draft_until_a_person_reviews_it(): void
    {
        $user = $this->user();
        $this->inPersonal($user);
        $attachment = $this->service()->store($this->pdf());

        $this->service()->markExtractionPending($attachment);
        $this->assertSame('pending', $attachment->fresh()->extraction_status);

        $this->service()->saveExtractionDraft($attachment, ['supplier' => 'Acme', 'total_cents' => 11500, 'tax_cents' => 1500], 'rule-based');
        $draft = $attachment->fresh();
        $this->assertSame('draft', $draft->extraction_status);
        $this->assertSame('rule-based', $draft->extraction_engine);
        $this->assertSame(11500, $draft->extraction_json['total_cents']);
        $this->assertNotNull($draft->extracted_at);
        $this->assertNull($draft->extraction_reviewed_by);

        $this->service()->markReviewed($draft, $user, ['supplier' => 'Acme (Pty) Ltd', 'total_cents' => 11500, 'tax_cents' => 1500]);
        $reviewed = $attachment->fresh();
        $this->assertSame('reviewed', $reviewed->extraction_status);
        $this->assertSame('Acme (Pty) Ltd', $reviewed->extraction_json['supplier']);
        $this->assertSame($user->id, $reviewed->extraction_reviewed_by);
        $this->assertNotNull($reviewed->extraction_reviewed_at);
    }

    public function test_only_a_draft_can_be_reviewed_or_rejected(): void
    {
        $user = $this->user();
        $this->inPersonal($user);
        $attachment = $this->service()->store($this->pdf());

        $this->assertThrows(fn () => $this->service()->markReviewed($attachment, $user), LogicException::class);
        $this->assertThrows(fn () => $this->service()->rejectExtraction($attachment, $user), LogicException::class);

        $this->service()->saveExtractionDraft($attachment, ['a' => 1], 'x');
        $this->service()->rejectExtraction($attachment->fresh(), $user);
        $this->assertSame('rejected', $attachment->fresh()->extraction_status);
        $this->assertThrows(fn () => $this->service()->markReviewed($attachment->fresh(), $user), LogicException::class);
    }

    public function test_a_failed_extraction_records_why(): void
    {
        $this->inPersonal($this->user());
        $attachment = $this->service()->store($this->pdf());

        $this->service()->markExtractionFailed($attachment, 'the scan was unreadable');

        $this->assertSame('failed', $attachment->fresh()->extraction_status);
        $this->assertSame('the scan was unreadable', $attachment->fresh()->extraction_json['error']);
    }

    public function test_a_new_draft_clears_an_earlier_review(): void
    {
        $user = $this->user();
        $this->inPersonal($user);
        $attachment = $this->service()->store($this->pdf());

        $this->service()->saveExtractionDraft($attachment, ['v' => 1], 'x');
        $this->service()->markReviewed($attachment->fresh(), $user);
        $this->service()->saveExtractionDraft($attachment->fresh(), ['v' => 2], 'y');

        $fresh = $attachment->fresh();
        $this->assertSame('draft', $fresh->extraction_status);
        $this->assertNull($fresh->extraction_reviewed_by);
        $this->assertNull($fresh->extraction_reviewed_at);
    }

    // ── the signed streaming route ────────────────────────────────────

    private function storedFor(object $user, ?object $party = null, string $kind = 'pdf'): array
    {
        $party ??= Party::factory()->create(['user_id' => $user->id]);
        $attachment = $this->service()->store($kind === 'png' ? $this->png() : $this->pdf('Receipt.pdf', 'route'), $party, $user, context: LedgerContext::personal($user));

        return [$party, $attachment];
    }

    public function test_the_owner_can_stream_a_file_through_a_signed_url(): void
    {
        $user = $this->user();
        [, $attachment] = $this->storedFor($user);

        $response = $this->inPersonal($user)->get($this->service()->url($attachment));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('"'.$attachment->sha256.'"', $response->headers->get('ETag'));
        $this->assertStringStartsWith('%PDF', $response->streamedContent());
    }

    public function test_pdfs_and_images_may_open_inline_but_text_never_does(): void
    {
        $user = $this->user();
        [, $pdf] = $this->storedFor($user);
        [, $png] = $this->storedFor($user, kind: 'png');
        $text = $this->service()->store(UploadedFile::fake()->createWithContent('n.txt', 'a note'), null, $user, context: LedgerContext::personal($user));

        $this->inPersonal($user);
        $this->assertStringContainsString('inline', $this->get($this->service()->url($pdf, inline: true))->headers->get('Content-Disposition'));
        $this->assertStringContainsString('inline', $this->get($this->service()->url($png, inline: true))->headers->get('Content-Disposition'));
        $this->assertStringContainsString('attachment', $this->get($this->service()->url($text, inline: true))->headers->get('Content-Disposition'), 'text is always a download');
    }

    public function test_an_unsigned_or_tampered_or_expired_url_is_refused(): void
    {
        $user = $this->user();
        [, $attachment] = $this->storedFor($user);
        $this->inPersonal($user);

        $this->get(route('attachments.show', $attachment))->assertForbidden();

        $signed = $this->service()->url($attachment);
        $this->get($signed.'0')->assertForbidden();
        $this->get($signed.'&inline=1')->assertForbidden();

        $expired = URL::temporarySignedRoute('attachments.show', now()->subMinute(), ['attachment' => $attachment->id]);
        $this->get($expired)->assertForbidden();
    }

    public function test_a_signed_url_does_not_let_a_stranger_in(): void
    {
        $alice = $this->user();
        $bob = $this->user();
        [, $attachment] = $this->storedFor($alice);
        $url = $this->service()->url($attachment);

        // Bob follows a link Alice shared: the signature is valid but the file is not in his ledger
        $this->inPersonal($bob)->get($url)->assertNotFound();

    }

    public function test_a_signed_url_alone_is_not_enough_without_signing_in(): void
    {
        $user = $this->user();
        [, $attachment] = $this->storedFor($user);

        $response = $this->get($this->service()->url($attachment));

        $this->assertContains($response->getStatusCode(), [302, 401]);
        $this->assertStringNotContainsString('%PDF', (string) $response->getContent());
    }

    public function test_a_company_member_can_open_a_company_file_and_a_non_member_cannot(): void
    {
        $owner = $this->user();
        $company = $this->company($owner);
        $viewer = $this->memberOf($company, 'viewer');
        $outsider = $this->user();
        $party = Party::factory()->forCompany($company)->create();
        $attachment = $this->service()->store($this->pdf('c.pdf', 'company'), $party, $owner, context: LedgerContext::company($company));
        $url = $this->service()->url($attachment);

        $this->inCompany($viewer, $company)->get($url)->assertOk();
        $this->inCompany($owner, $company)->get($url)->assertOk();
        $this->inCompany($outsider, $company)->get($url)->assertNotFound();
        $this->inPersonal($viewer)->get($url)->assertNotFound();
    }

    public function test_a_file_is_authorised_through_the_record_it_belongs_to(): void
    {
        $user = $this->user();
        $this->inPersonal($user);

        // an owning record with no policy cannot vouch for access: refuse rather than guess
        $insight = Insight::create(['user_id' => $user->id, 'insight' => 'x', 'type' => 't', 'iso_week' => '2026-W01']);
        $attachment = $this->service()->store($this->pdf('i.pdf', 'insight'), $insight, $user);

        $this->get($this->service()->url($attachment))->assertForbidden();
    }

    public function test_a_file_whose_bytes_are_gone_is_a_404_not_a_500(): void
    {
        $user = $this->user();
        [, $attachment] = $this->storedFor($user);
        Storage::disk('local')->delete($attachment->path);

        $this->inPersonal($user)->get($this->service()->url($attachment))->assertNotFound();
    }

    public function test_an_archived_partys_files_stay_reachable(): void
    {
        $user = $this->user();
        [$party, $attachment] = $this->storedFor($user);
        $party->delete();

        $this->inPersonal($user)->get($this->service()->url($attachment))->assertOk();
    }
}
