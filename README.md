# Photo Journal

Pet project: Laravel API для работы с фотосериями с асинхронной обработкой через очереди и outbox-паттерн.  
Проект используется для проработки событийной архитектуры, очередей и фоновых задач, outbox-паттерна для интеграций и чёткого разделения HTTP / domain / integration слоёв.

---

## Stack

Backend: Laravel (PHP 8.4)  
Database: MySQL (development)  
Queues: Laravel queue (database driver)  
Frontend: Vue + Vite (pnpm)  
DB UI (dev): Adminer  

---

## Local development

Проект предполагает локальный запуск:
- HTTP-сервера приложения
- Queue worker (обязателен для бизнес-логики)
- Frontend dev-server (опционально)

Схема базы данных управляется Laravel migrations.

Минимальный набор команд для работы с проектом:

$ composer install  
$ pnpm install  
$ cp .env.example .env  
$ php artisan key:generate  
$ php artisan migrate  
$ php artisan serve  
$ php artisan queue:work --tries=3  
$ pnpm run dev  

---

## Frontend Ready Checklist

Перед стартом разработки UI:

1. Поднять базу и миграции
- `php artisan migrate`

2. Заполнить демо-данные (админ + стартовая серия/фото/теги)
- `php artisan db:seed`

3. Проверить CORS и API URL
- `CORS_ALLOWED_ORIGINS=http://localhost:5173,http://127.0.0.1:5173`
- `VITE_API_BASE_URL=http://127.0.0.1:8091/api/v1`

4. Запустить backend на отдельном порту
- `php artisan serve --host=127.0.0.1 --port=8091`

5. Быстрый smoke-check auth
- `curl -X POST http://127.0.0.1:8091/api/v1/auth/login -H "Content-Type: application/json" -d '{"email":"admin@example.com","password":"admin12345"}'`
- использовать `token` из ответа в заголовке `Authorization: Bearer <token>`
- `curl http://127.0.0.1:8091/api/v1/auth/me -H "Authorization: Bearer <token>"`

Админка:
- URL: `http://127.0.0.1:8091/admin`
- Email: `admin@example.com`
- Password: `admin12345`

---

## Adminer (development only)

Локальный просмотр данных MySQL:

$ php -S 127.0.0.1:8081 tools/adminer/adminer.php

Используется только в development-среде и не является частью приложения.

---

## Architecture overview

Core flow: create series → async processing → outbox → integrations

API:  
POST /api/v1/series

Controller:
- валидирует входные данные
- создаёт Series
- диспатчит job ProcessSeries(seriesId)

Job ProcessSeries:
- загружает Series
- выполняет доменную обработку
- эмитит событие SeriesUploaded

Event listener:
- перехватывает SeriesUploaded
- создаёт запись в outbox_events  
  type = series.uploaded  
  status = pending  

Outbox polling:
- команда php artisan outbox:poll
- диспатчит job DispatchOutboxEvent(outboxEventId)

Job DispatchOutboxEvent:
- обрабатывает outbox-событие
- переводит статус pending → done
- увеличивает attempts
- заполняет processed_at
- в будущем вызывает реальные интеграции (AI, webhooks, уведомления)

---

## Why outbox pattern

- интеграционные события не теряются
- поддерживаются retries и идемпотентность
- интеграции изолированы от HTTP и domain-логики
- единая безопасная точка расширения

---

## API (current)

Актуальный контракт API вынесен в `API.md`.

Кратко:
- series: CRUD (`/api/v1/series`)
- photos: CRUD внутри series (`/api/v1/series/{series}/photos`)
- tags: привязка к фото + ручное создание/редактирование (`/api/v1/tags`)

---

## Useful commands

$ php artisan optimize:clear  
$ php artisan queue:work --once -vvv  
$ php artisan outbox:poll  

---

## Roadmap

- Preview generation & EXIF parsing in jobs  
- AI tags and shooting recommendations via outbox  
- Authentication (Sanctum) + access policies  
- Internal admin panel (optional)  

---

## License

MIT
