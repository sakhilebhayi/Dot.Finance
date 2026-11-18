<?php

namespace App\Models\Licences;

use Illuminate\Database\Eloquent\Model;

class LicenceOutboxEvent extends Model
{
    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const REJECTED = 'rejected';

    public const DEAD = 'dead';

    protected $table = 'licence_outbox';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'next_attempt_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return (array) json_decode((string) $this->payload, true);
    }
}
