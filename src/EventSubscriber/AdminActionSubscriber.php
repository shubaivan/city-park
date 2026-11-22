<?php

namespace App\EventSubscriber;

use App\Entity\AdminActionLog;
use App\Service\ActionLog\ActorContext;
use App\Service\ActionLog\AdminActionJournal;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records every non-GET request under /admin in the «Журнал дій» — automatically, so a
 * button added next month is covered without anybody remembering to log it.
 *
 * Written on kernel.response, when the status is known (a 403 for somebody trying what
 * their role may not is worth a row too), with the route, the URL parameters and the form
 * fields — minus anything secret (passwords, tokens, CSRF) and minus file contents (an
 * upload is its name and size). Only for a signed-in user: an anonymous POST is a scanner
 * being sent to /login, and a row for each would bury the real ones.
 *
 * Also tells ActorContext who is acting when it is not a panel user: a Telegram update
 * (`bot:<telegram id>` of the person who pressed) or a console command.
 */
class AdminActionSubscriber implements EventSubscriberInterface
{
    /**
     * Reads that happen to be POSTs: the DataTables endpoints post their paging and search.
     * A row per scroll of the residents' table would bury everything else.
     */
    private const READ_ONLY_POSTS = ['admin-users-data-table', 'admin-schedule-data-table'];

    /** What each button is, in the panel's own words. Unknown routes fall back to the route name. */
    public const ROUTE_LABELS = [
        'app_admin_sms_debt' => 'Розсилка СМС боржникам',
        'app_admin_car_remove' => 'Видалення авто',
        'app_admin_block_vote_create' => 'Нове голосування за блокування',
        'app_admin_block_vote_question' => 'Нове питання для голосування',
        'app_admin_block_vote_broadcast' => 'Розсилка голосування',
        'app_admin_block_vote_cancel' => 'Скасування голосування',
        'app_admin_block_vote_remind' => 'Нагадування про голосування',
        'app_admin_complaint_status' => 'Статус заявки',
        'app_admin_complaint_result_photos' => 'Фото результату заявки',
        'app_admin_complaint_comment' => 'Коментар до заявки',
        'app_admin_rental_block' => 'Блок оголошення оренди',
        'app_admin_service_block' => 'Блок послуги',
        'app_admin_photo_request_resolve' => 'Закриття вимоги фото',
        'app_admin_objects_type' => 'Тип об’єкта',
        'app_admin_objects_expected_add' => 'Очікуваний мешканець: додано',
        'app_admin_objects_expected_remove' => 'Очікуваний мешканець: прибрано',
        'app_admin_objects_group_link' => 'Об’єднання об’єктів в одну групу',
        'app_admin_objects_group_unlink' => 'Виключення об’єкта з групи',
        'app_admin_resident_name' => 'ПІБ мешканця',
        'app_admin_resident_role' => 'Роль мешканця',
        'app_admin_resident_account' => 'Дані об’єкта мешканця',
        'app_admin_resident_status' => 'Блокування / розблокування',
        'app_admin_resident_chat' => 'Чат мешканців: вигнати / бан',
        'app_admin_resident_move' => 'Прив’язка / перенесення мешканця',
        'app_admin_resident_phones' => 'Телефони мешканця',
        'app_admin_resident_group_link' => 'Об’єднання об’єктів в одну групу',
        'app_admin_resident_group_unlink' => 'Виключення об’єкта з групи',
        'app_admin_resident_unlink' => 'Відв’язка мешканця від рахунку',
        'app_admin_area_upload' => 'Завантаження площ',
        'app_admin_tariff_save' => 'Зміна тарифу',
        'app_admin_debt_upload' => 'Завантаження боргів',
        'app_admin_staff_add' => 'Персонал: додано',
        'app_admin_staff_toggle' => 'Персонал: увімкнено / вимкнено',
        'app_admin_rental_video_send' => 'Відео оренди: надіслано',
    ];

    /** Form fields never written down, matched anywhere in the name. */
    private const SECRET_FIELD = '/pass|token|csrf|secret|signature|_sig\b/i';

    private const VALUE_MAX = 300;

    public function __construct(
        private AdminActionJournal $journal,
        private ActorContext $actor,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            // Right after the router (32), before RequestSubscriber (0) flushes anything for
            // the update, so that flush is already the bot's.
            KernelEvents::REQUEST => ['onRequest', 31],
            // Last, so the status is the one the browser gets.
            KernelEvents::RESPONSE => ['onResponse', -1024],
            ConsoleEvents::COMMAND => 'onCommand',
        ];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $name = $event->getCommand()?->getName();

        if ($name !== null) {
            $this->actor->markCommand($name);
        }
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ($request->attributes->get('_route') !== 'app_webhook' && $request->getPathInfo() !== '/hook') {
            return;
        }

        $this->actor->markBot(self::telegramSender($request->getContent()));
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || !self::isJournalled($request) || !$this->actor->isSignedIn()) {
            return;
        }

        $route = (string)$request->attributes->get('_route', '');
        $routeParams = (array)$request->attributes->get('_route_params', []);
        $fields = self::cleanFields($request);
        [$targetType, $targetId] = self::target($route, $routeParams, $fields);
        $status = $event->getResponse()->getStatusCode();

        $label = self::ROUTE_LABELS[$route] ?? ('Дія в панелі: ' . ($route !== '' ? $route : $request->getPathInfo()));
        $summary = $label . ($targetType !== null ? sprintf(' (%s #%d)', self::TARGET_LABELS[$targetType] ?? $targetType, $targetId) : '');

        if ($status >= 400) {
            $summary .= $status === 403 ? ' — відмовлено (немає прав)' : sprintf(' — помилка %d', $status);
        }

        $this->journal->record([
            'kind' => AdminActionLog::KIND_REQUEST,
            'summary' => $summary,
            'route' => $route !== '' ? $route : null,
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'status' => $status,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'account_id' => $targetType === 'account' ? $targetId : null,
            'user_id' => $targetType === 'telegram_user' ? $targetId : null,
            'changes' => $fields !== [] ? $fields : null,
        ]);
    }

    public const TARGET_LABELS = [
        'account' => 'об’єкт',
        'telegram_user' => 'мешканець',
        'expected_resident' => 'очікуваний',
        'car_plate' => 'авто',
        'guest_pass' => 'пропуск',
        'complaint' => 'заявка',
        'block_vote' => 'голосування',
        'rental' => 'оголошення',
        'service' => 'послуга',
        'staff' => 'персонал',
        'photo_request' => 'вимога фото',
    ];

    public static function isJournalled(Request $request): bool
    {
        if (in_array($request->getMethod(), [Request::METHOD_GET, Request::METHOD_HEAD, Request::METHOD_OPTIONS], true)) {
            return false;
        }

        $path = $request->getPathInfo();

        if ($path !== '/admin' && !str_starts_with($path, '/admin/')) {
            return false;
        }

        return !in_array($request->attributes->get('_route'), self::READ_ONLY_POSTS, true);
    }

    /**
     * The form, as something safe to keep and to show to four colleagues.
     *
     * @return array<string, mixed>
     */
    public static function cleanFields(Request $request): array
    {
        $out = [];

        foreach ($request->request->all() as $name => $value) {
            if (preg_match(self::SECRET_FIELD, (string)$name) === 1) {
                continue;
            }

            $out[(string)$name] = self::cleanValue($value);
        }

        foreach ($request->files->all() as $name => $file) {
            $out[(string)$name] = self::describeFile($file);
        }

        return $out;
    }

    /**
     * The person who pressed, from the raw Telegram update: `message.from.id`,
     * `callback_query.from.id`, … — whatever the update's one top-level object is.
     */
    public static function telegramSender(string $body): ?string
    {
        if ($body === '') {
            return null;
        }

        try {
            $update = json_decode($body, true, 32, \JSON_BIGINT_AS_STRING | \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($update)) {
            return null;
        }

        foreach ($update as $key => $part) {
            if ($key === 'update_id' || !is_array($part)) {
                continue;
            }

            $id = $part['from']['id'] ?? $part['user']['id'] ?? null;

            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                return (string)$id;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $routeParams
     * @param array<string, mixed> $fields
     * @return array{0: ?string, 1: ?int}
     */
    private static function target(string $route, array $routeParams, array $fields): array
    {
        $int = static fn (mixed $v): ?int => is_scalar($v) && ctype_digit((string)$v) ? (int)$v : null;

        if (str_starts_with($route, 'app_admin_resident') && ($id = $int($routeParams['id'] ?? null)) !== null) {
            return ['telegram_user', $id];
        }

        $byField = [
            'account_id' => 'account',
            'expected_id' => 'expected_resident',
            'plate_id' => 'car_plate',
            'campaign_id' => 'block_vote',
            'listing_id' => 'rental',
            'offer_id' => 'service',
        ];

        foreach ($byField as $field => $type) {
            if (($id = $int($fields[$field] ?? null)) !== null) {
                return [$type, $id];
            }
        }

        $byRouteId = [
            'app_admin_complaint' => 'complaint',
            'app_admin_photo_request' => 'photo_request',
            'app_admin_rental' => 'rental',
            'app_admin_staff' => 'staff',
        ];

        foreach ($byRouteId as $prefix => $type) {
            if (str_starts_with($route, $prefix)) {
                $id = $int($routeParams['id'] ?? null) ?? $int($fields['id'] ?? null);

                if ($id !== null) {
                    return [$type, $id];
                }
            }
        }

        return [null, null];
    }

    private static function cleanValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];

            foreach (array_slice($value, 0, 50, true) as $k => $v) {
                if (is_string($k) && preg_match(self::SECRET_FIELD, $k) === 1) {
                    continue;
                }

                $out[$k] = self::cleanValue($v);
            }

            return $out;
        }

        if (is_string($value)) {
            return mb_strlen($value) > self::VALUE_MAX ? mb_substr($value, 0, self::VALUE_MAX) . '…' : $value;
        }

        return is_scalar($value) || $value === null ? $value : get_debug_type($value);
    }

    private static function describeFile(mixed $file): mixed
    {
        if ($file instanceof UploadedFile) {
            return ['file' => mb_substr($file->getClientOriginalName(), 0, 120), 'size' => $file->getError() === UPLOAD_ERR_OK ? $file->getSize() : null];
        }

        if (is_array($file)) {
            return array_map(self::describeFile(...), array_slice($file, 0, 20, true));
        }

        return null;
    }
}
