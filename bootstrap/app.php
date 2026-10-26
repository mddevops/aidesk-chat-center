<?php

use App\Chat\ChatException;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\VisitorCredential;
use App\Http\Middleware\WidgetChannel;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/widget.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        channels: __DIR__.'/../routes/channels.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(RequestId::class);
        $middleware->alias(['tenant' => ResolveTenant::class, 'widget.channel' => WidgetChannel::class, 'visitor.credential' => VisitorCredential::class]);
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);
        $middleware->prependToPriorityList(ThrottleRequests::class, WidgetChannel::class);
        $middleware->prependToPriorityList(ThrottleRequests::class, VisitorCredential::class);
        $middleware->web(append: [HandleInertiaRequests::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([ChatException::class]);
        $exceptions->report(function (Throwable $error) {
            if (request()->is('api/v1/*', 'widget/v1/*')) {
                Log::error('chat.request_failed', ['request_id' => request()->attributes->get('request_id'), 'exception_class' => get_class($error)]);

                return false;
            }

            return null;
        });
        $exceptions->shouldRenderJsonWhen(fn ($request, $exception) => $request->is('api/v1/*', 'widget/v1/*') || $request->expectsJson());
        $exceptions->render(function (Throwable $error, Request $request) {
            if (! $request->is('api/v1/*', 'widget/v1/*')) {
                return null;
            }
            $status = $error instanceof ChatException ? $error->status : ($error instanceof ValidationException ? 422 : ($error instanceof AuthenticationException ? 401 : ($error instanceof HttpExceptionInterface ? $error->getStatusCode() : 500)));
            $code = $error instanceof ChatException ? $error->errorCode : match ($status) {
                401 => 'authentication_required', 403 => 'forbidden', 404 => 'not_found', 409 => 'conflict', 419 => 'csrf_expired', 422 => 'validation_failed', 429 => 'rate_limited', default => 'server_error'
            };
            $message = $error instanceof ChatException ? $error->getMessage() : match ($status) {
                401 => 'Требуется авторизация.', 403 => 'Доступ запрещён.', 404 => 'Объект не найден.', 419 => 'Сессия устарела. Обновите страницу.', 422 => 'Проверьте заполнение полей.', 429 => 'Слишком много запросов. Повторите позже.', default => 'Не удалось обработать запрос.'
            };
            $payload = ['error' => ['code' => $code, 'message' => $message], 'request_id' => $request->attributes->get('request_id')];
            if ($error instanceof ValidationException) {
                $payload['errors'] = $error->errors();
            }

            return response()->json($payload, $status)->withHeaders(['Cache-Control' => 'private, no-store']);
        });
    })->create();
