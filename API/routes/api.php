use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\Auth\CsrfTokenController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\Registry\MigrantArcoController;
use App\Http\Controllers\Api\Registry\MigrantRegistryController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::middleware('web')->group(function (): void {
    Route::get('/csrf-token', CsrfTokenController::class);
    Route::post('/login', LoginController::class);

    Route::middleware('auth')->group(function (): void {
        Route::get('/audit-events', [AuditController::class, 'index']);

        Route::prefix('registry/migrants')->group(function (): void {
            Route::get('/', [MigrantRegistryController::class, 'index']);
            Route::post('/', [MigrantRegistryController::class, 'store']);
            Route::get('/{migrantRegistryEntry}', [MigrantRegistryController::class, 'show']);
            Route::patch('/{migrantRegistryEntry}', [MigrantRegistryController::class, 'update']);
            Route::post('/{migrantRegistryEntry}/submit', [MigrantRegistryController::class, 'submit']);

            Route::prefix('arco')->group(function (): void {
                Route::get('/', [MigrantArcoController::class, 'index']);
                Route::post('/', [MigrantArcoController::class, 'store']);
                Route::post('/{migrantArcoRequest}/resolve', [MigrantArcoController::class, 'resolve']);
            });
        });
    });
});