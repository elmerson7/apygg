<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * Exportación de datos personales de un usuario (portabilidad RGPD; docs/privacy.md). No confundir con
 * `DataExport` (exportación de listados CSV/XLSX de un tenant; docs/export-import.md).
 *
 * @property int $id
 * @property int $user_id
 * @property string $status
 * @property string|null $disk
 * @property string|null $path
 * @property int|null $size
 * @property CarbonInterface|null $ready_at
 * @property CarbonInterface|null $expires_at
 * @property string|null $error
 * @property CarbonInterface $created_at
 */
class PersonalDataExport extends EloquentModel
{
    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    protected $table = 'personal_data_exports';

    protected $guarded = [];

    protected $hidden = ['disk', 'path'];

    protected $casts = ['ready_at' => 'datetime', 'expires_at' => 'datetime', 'size' => 'integer'];
}
