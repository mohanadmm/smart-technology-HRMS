<?php

use App\Modules\Settings\Application\BusinessRules;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The site manager's day (manager-attendance plan, Task 7): the `manager` source (P9) and the time yesterday closes for them (P8).
return new class extends Migration
{
    private const SETTINGS = ['manager_yesterday_until' => '12:00'];

    public function up(): void
    {
        $this->sourceCheck(['entered', 'imported', 'derived', 'leave', 'manager']);
        $now = now();
        DB::table('settings')->insert(array_map(
            fn (string $key, string $value) => ['id' => (string) Str::uuid7(), 'key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now],
            array_keys(self::SETTINGS),
            self::SETTINGS,
        ));
        BusinessRules::forgetCache();
    }

    public function down(): void
    {
        // A day a site manager saved keeps its meaning as HR-entered once the source is gone.
        DB::table('attendance_days')->where('source', 'manager')->update(['source' => 'entered']);
        $this->sourceCheck(['entered', 'imported', 'derived', 'leave']);
        DB::table('settings')->whereIn('key', array_keys(self::SETTINGS))->delete();
        BusinessRules::forgetCache();
    }

    /** @param  list<string>  $sources */
    private function sourceCheck(array $sources): void
    {
        DB::statement('alter table attendance_days drop constraint attendance_days_source_check');
        DB::statement("alter table attendance_days add constraint attendance_days_source_check check (source in ('".implode("', '", $sources)."'))");
    }
};
