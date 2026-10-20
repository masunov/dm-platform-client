# mnd/dm-platform-client

PHP-клиент Platform API, через который кабинет вызывает инсталляции школ.

Требуется PHP 8.3 или новее.

## Установка

```sh
composer require mnd/dm-platform-client
```

## Подключение к Laravel-проекту

`DmPlatformServiceProvider` подключается сам, через автообнаружение пакетов Laravel.

Он регистрирует `DmPlatformClients` как singleton. Этот объект хранит общие настройки и выдаёт клиент для выбранной инсталляции.

Сами инсталляции (адрес, API-ключ, секрет вебхуков) пакет не хранит: их держит приложение, например в таблице с зашифрованными полями.

```php
use Mnd\DmPlatform\Endpoint;
use Mnd\DmPlatform\DmPlatformClients;

final class SchoolProvisioner
{
    public function __construct(private readonly DmPlatformClients $dmPlatform)
    {
    }

    public function check(Installation $installation, string $techName): bool
    {
        $client = $this->dmPlatform->for(
            new Endpoint($installation->code, $installation->base_url, $installation->api_key, $installation->webhook_secret),
        );

        return $client->availability($techName)->available;
    }
}
```

Вебхук проверяется секретом той инсталляции, от которой он пришёл:

```php
$event = $this->dmPlatform->for($endpoint)->webhooks()->verify(
    $request->header(WebhookVerifier::HEADER),
    $request->getContent(),
);
```

### Переменные `.env`

Все переменные необязательные: если переменная не задана, берётся значение по умолчанию.

| Переменная | По умолчанию | Что задаёт |
|---|---|---|
| `DMPLATFORM_CACHE_STORE` | хранилище кэша по умолчанию | Хранилище для предохранителя. Оно должно быть общим для всех воркеров, например `redis`. |
| `DMPLATFORM_TIMEOUT` | `5` | Время на весь запрос, в секундах. |
| `DMPLATFORM_CONNECT_TIMEOUT` | `2` | Время на соединение, в секундах. |
| `DMPLATFORM_RETRY_DELAYS_MS` | `200,500` | Паузы перед повторами, в миллисекундах. Количество значений равно количеству повторов. Пустое значение отключает повторы. |
| `DMPLATFORM_RETRY_AFTER_CAP` | `2` | Максимальное ожидание по `Retry-After`, в секундах. |
| `DMPLATFORM_CIRCUIT_THRESHOLD` | `5` | Сколько сбоев подряд нужно, чтобы остановить вызовы инсталляции. |
| `DMPLATFORM_CIRCUIT_COOLDOWN` | `30` | Через сколько секунд пропускается пробный вызов. |
| `DMPLATFORM_WEBHOOK_TOLERANCE` | `300` | Максимальный возраст подписи вебхука, в секундах. |

Конфиг можно скопировать в приложение и править там:

```sh
php artisan vendor:publish --tag=dmplatform-config
```

### Без Laravel

Ядро пакета от Laravel не зависит. Подойдёт любой PSR-16 кэш:

```php
$clients = new DmPlatformClients(new CircuitBreakers($cache));

$clients->for(new Endpoint('ru-1', 'https://localhost', $apiKey, $webhookSecret))->availability('drift');
```

## Поведение

Запрос несёт `Authorization: Bearer`, `X-Request-Id` и, для `POST`, `Idempotency-Key`.

Ответы `408`, `425`, `429` и `5xx`, как и обрыв сети, повторяются.

Остальные `4xx` не повторяются.

Предохранитель свой у каждой инсталляции: падение региона `ru-1` не останавливает `by-1`.

Когда предохранитель остывает, к инсталляции проходит один пробный вызов. Остальные получают отказ, пока проба не ответит.

С кэшем Laravel сбои считаются атомарно через `increment()`, а пробу занимает `add()`. Атомарно это работает в хранилищах `redis`, `memcached` и `database`.
