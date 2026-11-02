<?php

use App\Modules\Settings\Application\BusinessRules;
use App\Modules\Settings\Infrastructure\Eloquent\Setting;
use Illuminate\Database\Migrations\Migration;

// A data migration: the field shift (all three field types) ends at 17:00, the default departure (calculations §13).
// Only an unedited 16:00 moves, so an HR edit is left alone; the Setting model writes the audit row.
return new class extends Migration
{
    public function up(): void
    {
        $setting = Setting::where(['key' => 'worker_shift_end', 'value' => '16:00'])->first();
        if ($setting !== null) {
            $setting->value = '17:00';
            $setting->save();
        }
        BusinessRules::forgetCache();
    }

    // Nothing to undo safely: a 17:00 here may be HR's own choice.
    public function down(): void {}
};
