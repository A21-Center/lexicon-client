<?php

namespace A21\LexiconClient\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Consumer-app copy of UGC translations synced from Lexicon.
 */
class EntityTranslation extends Model
{
    use HasUuids;

    protected $table = 'entity_translations';

    protected $fillable = [
        'entity_type',
        'entity_id',
        'field',
        'locale',
        'value',
        'needs_review',
        'origin',
        'locked',
        'source_hash',
    ];

    protected $casts = [
        'needs_review' => 'boolean',
        'locked' => 'boolean',
    ];
}
