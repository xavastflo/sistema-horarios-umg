<?php

use App\Http\Middleware\CheckRol;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Registrar el middleware de rol con alias
        $middleware->alias([
            'rol' => CheckRol::class,
        ]);

        // Configurar Sanctum para SPA (si se usa cookie-based auth)
        $middleware->statefulApi();

        // Respuestas JSON para requests que esperan JSON
        $middleware->append(\Illuminate\Http\Middleware\HandleCors::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Formatear errores de validación como JSON
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Error de validación.',
                    'errors'  => $e->errors(),
                ], 422);
            }
        });

        // Modelo no encontrado → 404 JSON
        $exceptions->render(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                $modelo = class_basename($e->getModel());
                return response()->json([
                    'message' => "Registro de {$modelo} no encontrado.",
                ], 404);
            }
        });

        // No autenticado → 401 JSON
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'No autenticado. Token inválido o expirado.',
                ], 401);
            }
        });

        // Error de integridad de base de datos (duplicados, FK) → 409 JSON
        $exceptions->render(function (\Illuminate\Database\QueryException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                if ($e->getCode() === '23000') {
                    return response()->json([
                        'message' => 'El registro ya existe o viola una restricción de integridad.',
                    ], 409);
                }
            }
        });
    })
    ->create();
