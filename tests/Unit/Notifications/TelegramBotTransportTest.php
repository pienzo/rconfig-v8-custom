<?php

use App\Services\Notifications\TelegramBotTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('rconfig-telegram.enabled', false);
    config()->set('rconfig-telegram.bot_token', '123456789:' . str_repeat('A', 35));
    config()->set('rconfig-telegram.chat_id', '-1001234567890');
    Http::preventStrayRequests();
});

afterEach(function () {
    config()->set('rconfig-telegram.enabled', false);
    config()->set('rconfig-telegram.bot_token', '');
    config()->set('rconfig-telegram.chat_id', '');
});

test('Telegram transport is inert by default', function () {
    Http::fake();

    expect(fn () => app(TelegramBotTransport::class)->send('<b>SYNTHETIC</b>'))->toThrow(RuntimeException::class, 'disabled');
    Http::assertNothingSent();
});

test('Telegram transport uses official HTTPS sendMessage and validated payload', function () {
    config()->set('rconfig-telegram.enabled', true);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 12]], 200)]);

    app(TelegramBotTransport::class)->send('<b>SYNTHETIC</b>' . "\n" . 'backup done');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_starts_with($request->url(), 'https://api.telegram.org/bot123456789:')
        && str_ends_with($request->url(), '/sendMessage')
        && $request['chat_id'] === '-1001234567890'
        && $request['parse_mode'] === 'HTML'
        && $request['text'] === '<b>SYNTHETIC</b>' . "\n" . 'backup done'
        && $request['link_preview_options'] === ['is_disabled' => true]
        && $request['protect_content'] === true);
});

test('Telegram refuses malformed credentials and malformed UTF8 before making requests', function () {
    config()->set('rconfig-telegram.enabled', true);
    Http::fake();

    config()->set('rconfig-telegram.bot_token', 'bad-token');
    expect(fn () => app(TelegramBotTransport::class)->send('sample'))->toThrow(RuntimeException::class, 'not configured');

    config()->set('rconfig-telegram.bot_token', '123456789:' . str_repeat('A', 35));
    config()->set('rconfig-telegram.chat_id', 'invalid chat id');
    expect(fn () => app(TelegramBotTransport::class)->send('sample'))->toThrow(RuntimeException::class, 'not configured');

    config()->set('rconfig-telegram.chat_id', '-1001234567890');
    expect(fn () => app(TelegramBotTransport::class)->send(''))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(TelegramBotTransport::class)->send(str_repeat('a', 4097)))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(TelegramBotTransport::class)->send("\xFF"))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

test('Telegram does not reveal provider error body or token in exceptions', function () {
    config()->set('rconfig-telegram.enabled', true);
    Http::fake(['api.telegram.org/*' => Http::response([
        'ok' => false,
        'description' => 'secret-leak-and-chat-id',
    ], 403)]);

    expect(fn () => app(TelegramBotTransport::class)->send('SYNTHETIC'))
        ->toThrow(RuntimeException::class, 'Telegram provider rejected the message.');
});

test('Telegram connection errors are sanitized', function () {
    config()->set('rconfig-telegram.enabled', true);
    Http::fake(fn () => throw new ConnectionException('Synthetic exception, URL may include a token'));

    expect(fn () => app(TelegramBotTransport::class)->send('SYNTHETIC'))
        ->toThrow(RuntimeException::class, 'Telegram transport connection failed.');
});
