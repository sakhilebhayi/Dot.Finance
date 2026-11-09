<?php

namespace App\Models\Reporting;

use App\Models\Concerns\HasLedgerContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A report export that is produced in the background (a large one) or kept for download. One row per distinct
 * (ledger, report, parameters, format, journal sequence): see ExportService for why asking twice does not make two files.
 */
class ReportExport extends Model
{
    use HasLedgerContext;

    public const STATUSES = ['queued', 'running', 'done', 'failed'];

    protected $fillable = [
        'user_id', 'team_id', 'requested_by', 'report_key', 'format', 'params', 'params_hash', 'idempotency_key', 'journal_seq',
        'status', 'disk', 'path', 'sha256', 'size_bytes', 'row_count', 'attempts', 'error', 'started_at', 'finished_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'journal_seq' => 'integer',
            'size_bytes' => 'integer',
            'row_count' => 'integer',
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isDone(): bool
    {
        return $this->status === 'done' && $this->path !== null;
    }

    public function auditLabel(): string
    {
        return 'Export '.$this->report_key.' '.$this->format;
    }

    public function downloadName(): string
    {
        return $this->report_key.'-'.$this->created_at?->format('Ymd-His').'.'.$this->format;
    }
}
