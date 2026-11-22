<?php

namespace App\Controller;

use App\Repository\AccountRepository;
use App\Repository\AdminActionLogRepository;
use App\Service\ActionLog\ActionLogView;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * «Журнал дій» — who did what, in the panel, through the bot and by hand on the server.
 *
 * Read by everyone who can sign in (security.yaml), like the sign-in log and for the same
 * reason; GET only — a log that can be edited is not a log. See App\Entity\AdminActionLog.
 */
class AdminActionLogController extends AbstractController
{
    public const PER_PAGE = 50;

    #[Route('/admin/actions', name: 'app_admin_actions', methods: [Request::METHOD_GET])]
    public function index(
        Request $request,
        AdminActionLogRepository $log,
        AccountRepository $accounts,
        ActionLogView $view,
    ): Response {
        $filters = [
            'actor' => trim($request->query->getString('actor')),
            'q' => trim($request->query->getString('q')),
            'from' => self::date($request->query->getString('from')),
            'to' => self::date($request->query->getString('to')),
            'user' => self::int($request->query->get('user')),
        ];

        // «Об'єкт»: an id from a card link, or what a person types — the особовий рахунок
        // («220050») or «будинок/квартира» («19/50»).
        $object = trim($request->query->getString('object'));
        // Typed text wins over the card's id: arriving from a card and typing another
        // object must search the typed one.
        $accountId = $object === '' ? (int)self::int($request->query->get('account')) : 0;
        $matched = [];

        if ($accountId > 0) {
            $filters['accounts'] = [$accountId];
            $matched = array_filter([$accounts->find($accountId)]);
        } elseif ($object !== '') {
            $matched = self::resolveObject($object, $accounts);
            $filters['accounts'] = array_map(static fn ($a): int => (int)$a->getId(), $matched);
        }

        $page = max(1, (int)self::int($request->query->get('page')));
        $result = $log->search($filters, $page, self::PER_PAGE);

        return $this->render('admin/actions.html.twig', [
            'items' => $view->present($result['rows'], count($filters['accounts'] ?? []) === 1 ? $filters['accounts'][0] : null),
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int)ceil($result['total'] / self::PER_PAGE)),
            'actors' => $log->actors(),
            'filters' => $filters,
            'object' => $object,
            'account' => $accountId ?: null,
            'matched' => $matched,
        ]);
    }

    /** A hand-edited «?page=abc» is page 1, not a 400. */
    private static function int(mixed $value): ?int
    {
        return is_scalar($value) && ctype_digit((string)$value) ? (int)$value : null;
    }

    private static function date(string $value): ?\DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date ?: null;
    }

    /** @return list<\App\Entity\Account> */
    private static function resolveObject(string $text, AccountRepository $accounts): array
    {
        if (preg_match('/^(\d+)\s*[\/\s,]\s*(\d+[a-zA-Zа-яА-ЯіїєґІЇЄҐ]?)$/u', $text, $m) === 1) {
            return array_values($accounts->findBy(['house_number' => $m[1], 'apartment_number' => $m[2]]));
        }

        if (ctype_digit($text)) {
            return array_values($accounts->findBy(['account_number' => $text]));
        }

        return [];
    }
}
