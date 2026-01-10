<?php

namespace App\Infrastructure\Telegram;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TelegramClient
{
    private HttpClientInterface $httpClient;
    private string $botToken;

    public function __construct(string $botToken)
    {
        $this->httpClient = HttpClient::create();
        $this->botToken = $botToken;
    }

    public function sendMessage(int $chatId, string $text, array $options = []): void
    {
        $payload = array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ], $options);

        $this->httpClient->request('POST', sprintf('https://api.telegram.org/bot%s/sendMessage', $this->botToken), [
            'body' => $payload,
        ]);
    }

    public function sendPhoto(int $chatId, string $photoUrl, array $options = []): void
    {
        $payload = array_merge([
            'chat_id' => $chatId,
            'photo' => $photoUrl,
            'parse_mode' => 'HTML',
        ], $options);
        $this->httpClient->request('POST', sprintf('https://api.telegram.org/bot%s/sendPhoto', $this->botToken), [
            'body' => $payload,
        ]);
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): void
    {
        $payload = [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
            'show_alert' => $showAlert,
        ];
        $this->httpClient->request('POST', sprintf('https://api.telegram.org/bot%s/answerCallbackQuery', $this->botToken), [
            'body' => $payload,
        ]);
    }
}

