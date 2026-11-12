<?php

declare(strict_types=1);

use App\Modules\Documents\Application\ExperienceCertificates;
use App\Modules\Documents\Application\RenderHrForm;
use App\Modules\Documents\Application\SignatureImages;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
try {
    if ($argv[1] === 'setup') {
        $employee = app(EmployeeService::class)->create([
            'name_ar' => 'سارة مثال للاختبار', 'gender' => 'female', 'type' => 'office', 'effective_from' => '2022-01-01',
            'hire_date' => '2022-01-01', 'salary' => '9000', 'national_id' => '12345678901234', 'department' => 'اختبار',
        ])['id'];
        $hr = User::create(['name' => 'hr', 'email' => 'hr@synthetic.test', 'password' => Str::random(24), 'role_id' => Role::where('slug', 'hr')->value('id'), 'is_active' => true]);
        $row = app(ExperienceCertificates::class)->create($employee, $hr->id, (string) Str::uuid7(), 'مهندسة اختبار', null);
        RenderHrForm::dispatch($row->id);
        echo $row->id;
    } elseif ($argv[1] === 'approve') {
        $ceo = User::create(['name' => 'ceo', 'email' => 'ceo@synthetic.test', 'password' => Str::random(24), 'role_id' => Role::where('slug', 'ceo')->value('id'), 'is_active' => true]);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAADwAAAAUCAYAAADRA14pAAAA2klEQVR4nO1YwQ2DMAy0rUzBDt2nI3af7pA1UiHhqo1CsA/apKQnIfGIz3c5QwScUqKRIDQYhAZD+Faj6XJ9PjvxfmM6c8LTi9nWEK9wvTw1ebItN0CsCxGRvYyx2zCSUo9mTYYR4bWa1mMtiHCr6J6S3TSMjqTW1WqOTNnLEbYWIGY/iVIPTzjBSppjJtYjSu+tTT0oaVnjz4/MWFjH+ceDR7hHTK2+NuLo5uVcceF5M3z0G9krCuGw9okLb2hldm8d2oPnhPem1OPxswbWkf414Sj4/8eDzo0Hs4qzCU6LdigAAAAASUVORK5CYII=');
        $tmp = tempnam(sys_get_temp_dir(), 'sig');
        file_put_contents($tmp, $png);
        try {
            app(SignatureImages::class)->replace($ceo->id, new UploadedFile($tmp, 'synthetic.png', 'image/png', null, true));
        } finally {
            unlink($tmp);
        }
        Auth::setUser($ceo);
        $row = app(ExperienceCertificates::class)->approve($argv[2], $ceo->id);
        RenderHrForm::dispatch($row->id);
        echo $row->id;
    } elseif ($argv[1] === 'export') {
        $row = DB::table('hr_form_exports')->where('id', $argv[2])->firstOrFail();
        if ($row->status !== 'ready') {
            fwrite(STDERR, "PDF state: {$row->status}\n");
            exit(2);
        }
        $pdf = Storage::disk('local')->get($row->storage_path);
        if (! str_starts_with($pdf, '%PDF-') || hash('sha256', $pdf) !== $row->sha256 || strlen($pdf) !== $row->size_bytes) {
            exit(3);
        }
        echo $pdf;
    } else {
        throw new InvalidArgumentException('Expected setup, approve or export.');
    }

} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage().PHP_EOL);
    exit(1);
}
