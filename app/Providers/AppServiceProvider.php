<?php

namespace App\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Exact amounts and readable labels in templates (domain §13 G7): @amount($v), @qty($v), @label($v).
        Blade::directive('amount', fn ($e) => "<?php echo e(\\App\\Ui\\Fmt::amount($e)); ?>");
        Blade::directive('qty', fn ($e) => "<?php echo e(\\App\\Ui\\Fmt::qty($e)); ?>");
        Blade::directive('label', fn ($e) => "<?php echo e(\\App\\Ui\\Fmt::label($e)); ?>");
        // A form's hidden token (App\Http\Middleware\UiSession).
        Blade::directive('token', fn () => '<?php echo \'<input type="hidden" name="_token" value="\'.e($csrf ?? \'\').\'">\'; ?>');
    }
}
