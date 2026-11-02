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
        $this->move('16:00', '17:00');
    }

    public function down(): void
    {
        $this->move('17:00', '16:00');
    }

    private function move(string $from, string $to): void
    {
        $setting = Setting::where(['key' => 'worker_shift_end', 'value' => $from])->first();
        if ($setting !== null) {
            $setting->value = $to;
            $setting->save();
        }
        BusinessRules::forgetCache();
    }
};
