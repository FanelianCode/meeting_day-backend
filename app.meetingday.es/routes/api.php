<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\DataController;
use App\Http\Controllers\Api\V1\ContactsController;
use App\Http\Controllers\Api\V1\EventoInvitadoController;
use App\Http\Controllers\Api\V1\GruposController;
use App\Http\Controllers\Api\V1\EventoController;
use App\Http\Controllers\Api\V1\MiembrosController;
use App\Http\Controllers\Api\V1\NotificationsController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// =====================
// API v1 (versionado)
// =====================
Route::prefix('v1')->group(function () {

    // 🚧 Preparado para seguridad futura
    // Route::middleware(['auth:sanctum'])->group(function () { ... });

    // Media (imágenes privadas en storage/app/media/*)
    Route::get('/media/{folder}/{file}', [MediaController::class, 'show'])
        ->where('folder', 'img_eventos|img_grupos|img_profiles')
        ->where('file', '.*')
        ->name('api.media.show');
        
        
    // Usuarios (TODO por body)
    Route::post('/user/registro',   [DataController::class, 'store']);     // body
    Route::post('/user/consulta',   [DataController::class, 'show']);      // body (nick, token)
    Route::post('/user/actualizar', [DataController::class, 'update']);    // body (id/id_data, campos)
    Route::post('/user/eliminar',   [DataController::class, 'destroy']);   // body (id/id_data)
    
    
    //------ gestion de  contactos
    
    Route::post('/user/indicativo', [DataController::class, 'getIndicativoByNick']);
    
    Route::get('/user/contacts/numbers', [ContactsController::class, 'numbers']);
    Route::get('/user/contacts/estadisticas',   [ContactsController::class, 'estadisticasContacto']);
    
    Route::get('/grupos/list', [GruposController::class, 'index']);
    Route::post('/grupos/registro', [GruposController::class, 'store']);
    Route::post('/grupos/miembros/confirmar', [MiembrosController::class, 'confirmar']);
    Route::post('/grupos/miembros/sync', [MiembrosController::class, 'syncByPhones']);
    Route::post('/grupos/delete', [GruposController::class, 'destroy']);
    Route::get('/grupos/view/{id}', [GruposController::class, 'show']);
    
    //---- eventos
    
    
    
    //---eventosajsute de timing****
    
    Route::withoutMiddleware('throttle:api')->group(function () {
        Route::middleware('throttle:poll-activos')
            ->get('/eventos/activos', [EventoController::class, 'activos']); // ?user=

        Route::middleware('throttle:poll-creados')
            ->get('/eventos/creados', [DataController::class, 'eventosCreados']); // ?user=

        Route::middleware('throttle:poll-listar')
            ->get('/eventos/invitado/listar', [EventoInvitadoController::class, 'index']); // ?nick=
    });
    
    //----*****
    
    
    Route::post('/eventos/crear', [EventoController::class, 'store']);
    
    Route::post('/eventos/confirmar', [EventoController::class, 'confirmar']);
    
    Route::post('/eventos/cancelar', [EventoController::class, 'cancelar']);
    
    Route::post('/eventos/location', [EventoController::class, 'selectLocation']);
    
    Route::get('/eventos/{id_evento}/invitado/{id_invi}', [EventoController::class, 'detalleEvento']);
    
    Route::post('/eventos/cancelacion/motivo', [EventoController::class, 'motivoCancelacion']);
    
    Route::get('/eventos/{id_evento}/estadisticas', [EventoController::class, 'estadisticas']);

    
    Route::post('/eventos/invitado/confirm', [EventoController::class, 'updateInvitadoConfirm']);
    
    Route::post('/eventos/invitados/sync', [EventoInvitadoController::class, 'syncByPhones']);
    
    /// Notificaciones (badge server-driven)
    Route::post('/notifications/mark-read', [NotificationsController::class, 'markRead']);
    Route::post('/notifications/badge',     [NotificationsController::class, 'badge']);
    

});
