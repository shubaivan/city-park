<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\ScheduledSet;
use App\Entity\TelegramUser;
use App\Repository\AccountRepository;
use App\Repository\PavilionPhotoRepository;
use App\Repository\PhotoUploadRequestRepository;
use App\Repository\ScheduledSetRepository;
use App\Repository\TariffRepository;
use App\Repository\TelegramUserRepository;
use App\Service\DebtPolicy;
use App\Service\PavilionPhotoService;
use App\Service\SchedulePavilionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;

class AdminController extends AbstractController
{
    public function __construct(
        protected readonly DenormalizerInterface $denormalizer,
        protected readonly SerializerInterface $serializer
    ) {}

    #[Route('/admin/guide', name: 'app_admin_guide')]
    public function guide(): Response
    {
        return $this->render('admin/guide.html.twig');
    }

    #[Route('/admin', name: 'app_admin')]
    public function index(EntityManagerInterface $em): Response
    {
        return $this->render('admin/index.html.twig', [
        ]);
    }

    #############
    # Schedule
    #############

    #[Route('/admin/schedule', name: 'app_admin_schedule')]
    public function schedule(): Response
    {
        $fieldNames = ScheduledSet::$dataTableFields;

        array_map(function ($k) use (&$dataTableColumnData) {
            $dataTableColumnData[] = ['data' => $k];
        }, $fieldNames);

        return $this->render('admin/schedule.html.twig', [
            'th_keys' => $fieldNames,
            'dataTableKeys' => $dataTableColumnData,
        ]);
    }

    #[Route('/admin/schedule/data-table', name: 'admin-schedule-data-table', options: ['expose' => true])]
    public function getScheduleDataTable(
        ScheduledSetRepository $repository,
        PavilionPhotoRepository $photoRepository,
        PhotoUploadRequestRepository $requestRepository,
        PavilionPhotoService $photoService,
        Request $request,
    ) {
        $dataTable = $repository
            ->getDataTablesData($request->request->all());

        $this->attachPhotoInfo($dataTable, $photoRepository, $requestRepository, $photoService);

        return $this->json(
            array_merge(
                [
                    "draw" => $request->request->get('draw'),
                    "recordsTotal" => $repository
                        ->getDataTablesData($request->request->all(), true, true),
                    "recordsFiltered" => $repository
                        ->getDataTablesData($request->request->all(), true)
                ],
                ['data' => $dataTable]
            )
        );
    }

    /**
     * Decorate schedule rows with the photo/status of the session each hour belongs to.
     */
    private function attachPhotoInfo(
        array &$rows,
        PavilionPhotoRepository $photoRepository,
        PhotoUploadRequestRepository $requestRepository,
        PavilionPhotoService $photoService,
    ): void {
        if (!$rows) {
            return;
        }

        $obligationStart = $photoService->obligationStartAt();

        // We don't have account_id in the result rows (just account_number).
        // Fetch all PavilionPhoto + open requests once and match by account_number+pavilion+window.
        $em = $photoRepository->createQueryBuilder('p')->getEntityManager();
        $photos = $em->createQuery(
            'SELECT p, a FROM App\Entity\PavilionPhoto p JOIN p.account a'
        )->getResult();
        $requests = $em->createQuery(
            'SELECT r, a FROM App\Entity\PhotoUploadRequest r JOIN r.account a WHERE r.resolved_at IS NULL'
        )->getResult();

        $photosByKey = [];
        /** @var \App\Entity\PavilionPhoto $photo */
        foreach ($photos as $photo) {
            $photosByKey[$photo->getAccount()->getAccountNumber() . ':' . $photo->getPavilion()][] = $photo;
        }
        $reqsByKey = [];
        /** @var \App\Entity\PhotoUploadRequest $req */
        foreach ($requests as $req) {
            $reqsByKey[$req->getAccount()->getAccountNumber() . ':' . $req->getPavilion()][] = $req;
        }

        foreach ($rows as &$row) {
            $row['photo_url'] = null;
            $row['photo_status'] = 'legacy';

            $accountNumber = $row['account_number'] ?? null;
            $pavilion = $row['pavilion'] ?? null;
            $scheduledAtStr = $row['scheduled_at'] ?? null;
            if (!$accountNumber || $pavilion === null || !$scheduledAtStr) {
                continue;
            }
            try {
                $scheduledAt = new \DateTime($scheduledAtStr);
            } catch (\Throwable) {
                continue;
            }

            $key = $accountNumber . ':' . $pavilion;
            $sessionEnd = (clone $scheduledAt)->modify('+1 hour');

            if ($sessionEnd <= $obligationStart) {
                $row['photo_status'] = 'legacy';
                continue;
            }

            $matchedPhoto = null;
            foreach ($photosByKey[$key] ?? [] as $photo) {
                if ($photo->getSessionStartAt() <= $scheduledAt && $photo->getSessionEndAt() > $scheduledAt) {
                    $matchedPhoto = $photo;
                    break;
                }
            }
            if ($matchedPhoto) {
                $row['photo_url'] = $matchedPhoto->getFilePath();
                $row['photo_status'] = 'uploaded';
                continue;
            }

            $matchedReq = null;
            foreach ($reqsByKey[$key] ?? [] as $req) {
                if ($req->getSessionStartAt() <= $scheduledAt && $req->getSessionEndAt() > $scheduledAt) {
                    $matchedReq = $req;
                    break;
                }
            }
            if ($matchedReq) {
                $row['photo_status'] = $matchedReq->getBlockedAt() ? 'blocked' : 'pending';
                continue;
            }

            if ($scheduledAt > new \DateTime()) {
                $row['photo_status'] = 'future';
            } else {
                $row['photo_status'] = 'pending';
            }
        }
    }

    #############
    # Photo Upload Requests
    #############

    #[Route('/admin/photo-requests', name: 'app_admin_photo_requests')]
    public function photoRequests(PhotoUploadRequestRepository $requestRepository, PavilionPhotoRepository $photoRepository): Response
    {
        $open = $requestRepository->findOpen();
        // Sessions resolved within the last 14 days too, for context.
        $recent = $requestRepository->createQueryBuilder('r')
            ->andWhere('r.resolved_at IS NOT NULL')
            ->andWhere('r.resolved_at >= :since')
            ->setParameter('since', (new \DateTime())->modify('-14 days'))
            ->orderBy('r.resolved_at', 'DESC')
            ->setMaxResults(50)
            ->getQuery()->getResult();

        $allPhotos = $photoRepository->findAll();

        $photosByKey = [];
        foreach ($allPhotos as $photo) {
            $key = $photo->getAccount()->getId() . ':' . $photo->getPavilion() . ':' . $photo->getSessionStartAt()->format('Y-m-d H:i');
            $photosByKey[$key] = $photo;
        }

        // For each open request, find any photo this account uploaded on the same
        // calendar day in the same pavilion. Lets admin close the request using an
        // adjacent-session photo when the family-pair edge case happens.
        $candidatePhotosByReq = [];
        foreach ($open as $req) {
            $candidates = [];
            $reqDay = $req->getSessionStartAt()->format('Y-m-d');
            foreach ($allPhotos as $photo) {
                if ($photo->getAccount()->getId() !== $req->getAccount()->getId()) {
                    continue;
                }
                if ($photo->getPavilion() !== $req->getPavilion()) {
                    continue;
                }
                if ($photo->getSessionStartAt()->format('Y-m-d') !== $reqDay) {
                    continue;
                }
                $candidates[] = $photo;
            }
            $candidatePhotosByReq[$req->getId()] = $candidates;
        }

        return $this->render('admin/photo-requests.html.twig', [
            'open' => $open,
            'recent' => $recent,
            'photosByKey' => $photosByKey,
            'candidatePhotosByReq' => $candidatePhotosByReq,
        ]);
    }

    #[Route('/admin/photo-requests/{id}/resolve', name: 'app_admin_photo_request_resolve', methods: [Request::METHOD_POST])]
    public function resolvePhotoRequest(
        int $id,
        PhotoUploadRequestRepository $requestRepository,
        PavilionPhotoService $photoService,
    ): JsonResponse {
        $req = $requestRepository->find($id);
        if (!$req) {
            return $this->json(['ok' => false, 'error' => 'not found'], Response::HTTP_NOT_FOUND);
        }
        if ($req->isOpen()) {
            $photoService->resolveRequest($req, SchedulePavilionService::createNewDate());
        }
        return $this->json(['ok' => true]);
    }

    #############
    # Telegram Users
    #############
    #[Route('/admin/users', name: 'app_admin_users')]
    public function users(EntityManagerInterface $em): Response
    {
        $fieldNames = TelegramUser::$dataTableFields;
        $fieldNames[] = 'action';

        array_map(function ($k) use (&$dataTableColumnData) {
            $dataTableColumnData[] = ['data' => $k];
        }, $fieldNames);

        return $this->render('admin/telegram-users.html.twig', [
            'th_keys' => $fieldNames,
            'dataTableKeys' => $dataTableColumnData,
        ]);
    }

    #[Route('/admin/users/data-table', name: 'admin-users-data-table', options: ['expose' => true])]
    public function getUsersDataTable(TelegramUserRepository $repository, Request $request)
    {
        $dataTable = $repository
            ->getDataTablesData($request->request->all());

        return $this->json(
            array_merge(
                [
                    "draw" => $request->request->get('draw'),
                    "recordsTotal" => $repository
                        ->getDataTablesData($request->request->all(), true, true),
                    "recordsFiltered" => $repository
                        ->getDataTablesData($request->request->all(), true)
                ],
                ['data' => $dataTable]
            )
        );
    }

    #[Route('/admin/user/{id}', name: 'admin-user-get', options: ['expose' => true], methods: [Request::METHOD_GET])]
    public function getUserById(
        int $id,
        TelegramUserRepository $repository,
        AccountRepository $accountRepository,
    ): JsonResponse
    {
        $telegramUser = $repository->getUserInfoById($id);

        if (!$telegramUser) {
            return $this->json([sprintf('User by id: %s was not found', $id)], Response::HTTP_BAD_REQUEST);
        }

        $telegramUser['group_siblings'] = [];
        if (!empty($telegramUser['account_id'])) {
            $account = $accountRepository->find($telegramUser['account_id']);
            if ($account) {
                foreach ($accountRepository->findGroupSiblings($account) as $sibling) {
                    if ($sibling->getId() === $account->getId()) {
                        continue;
                    }
                    $telegramUser['group_siblings'][] = [
                        'id' => $sibling->getId(),
                        'account_number' => $sibling->getAccountNumber(),
                        'apartment_number' => $sibling->getApartmentNumber(),
                        'street' => $sibling->getStreet(),
                        'house_number' => $sibling->getHouseNumber(),
                        'debt' => $sibling->getDebt(),
                    ];
                }
            }
        }

        return new JsonResponse($telegramUser, Response::HTTP_OK);
    }

    #[Route('/admin/account/group/link', name: 'admin-account-group-link', options: ['expose' => true], methods: [Request::METHOD_POST])]
    public function linkAccountGroup(
        Request $request,
        AccountRepository $accountRepository,
        EntityManagerInterface $em,
    ): JsonResponse
    {
        $sourceId = (int)$request->request->get('source_account_id');
        $partnerAccountNumber = trim((string)$request->request->get('partner_account_number'));

        if ($sourceId <= 0 || $partnerAccountNumber === '') {
            return $this->json(['source_account_id and partner_account_number are required'], Response::HTTP_BAD_REQUEST);
        }

        $source = $accountRepository->find($sourceId);
        if (!$source) {
            return $this->json([sprintf('Source account %d not found', $sourceId)], Response::HTTP_BAD_REQUEST);
        }

        $partner = $accountRepository->findOneBy(['account_number' => $partnerAccountNumber]);
        if (!$partner) {
            return $this->json([sprintf('Partner account with особовий рахунок "%s" not found', $partnerAccountNumber)], Response::HTTP_BAD_REQUEST);
        }

        if ($partner->getId() === $source->getId()) {
            return $this->json(['Cannot link account to itself'], Response::HTTP_BAD_REQUEST);
        }

        $sourceGid = $source->getOwnerGroupId();
        $partnerGid = $partner->getOwnerGroupId();

        if ($sourceGid !== null && $partnerGid !== null && $sourceGid === $partnerGid) {
            return $this->json(['Accounts are already in the same group'], Response::HTTP_BAD_REQUEST);
        }

        // Pick the surviving group id: prefer existing group(s) over a fresh id,
        // and the smaller of two existing groups (deterministic).
        if ($sourceGid !== null && $partnerGid !== null) {
            $survivor = min($sourceGid, $partnerGid);
            $disappearing = max($sourceGid, $partnerGid);
            foreach ($accountRepository->findBy(['owner_group_id' => $disappearing]) as $acct) {
                $acct->setOwnerGroupId($survivor);
            }
        } elseif ($sourceGid !== null) {
            $partner->setOwnerGroupId($sourceGid);
        } elseif ($partnerGid !== null) {
            $source->setOwnerGroupId($partnerGid);
        } else {
            $survivor = min($source->getId(), $partner->getId());
            $source->setOwnerGroupId($survivor);
            $partner->setOwnerGroupId($survivor);
        }

        $em->flush();

        $siblings = [];
        foreach ($accountRepository->findGroupSiblings($source) as $sibling) {
            if ($sibling->getId() === $source->getId()) {
                continue;
            }
            $siblings[] = [
                'id' => $sibling->getId(),
                'account_number' => $sibling->getAccountNumber(),
                'apartment_number' => $sibling->getApartmentNumber(),
                'street' => $sibling->getStreet(),
                'house_number' => $sibling->getHouseNumber(),
                'debt' => $sibling->getDebt(),
            ];
        }

        return $this->json([
            'owner_group_id' => $source->getOwnerGroupId(),
            'group_siblings' => $siblings,
        ]);
    }

    #[Route('/admin/account/group/unlink', name: 'admin-account-group-unlink', options: ['expose' => true], methods: [Request::METHOD_POST])]
    public function unlinkAccountGroup(
        Request $request,
        AccountRepository $accountRepository,
        EntityManagerInterface $em,
    ): JsonResponse
    {
        $accountId = (int)$request->request->get('account_id');
        if ($accountId <= 0) {
            return $this->json(['account_id is required'], Response::HTTP_BAD_REQUEST);
        }

        $account = $accountRepository->find($accountId);
        if (!$account) {
            return $this->json([sprintf('Account %d not found', $accountId)], Response::HTTP_BAD_REQUEST);
        }

        $oldGid = $account->getOwnerGroupId();
        if ($oldGid === null) {
            return $this->json(['Account is not in any group'], Response::HTTP_BAD_REQUEST);
        }

        $account->setOwnerGroupId(null);
        $em->flush();

        // If only one sibling remains in the original group, clear its group too
        // (a group of one is meaningless — it behaves identically to ungrouped via getEffectiveGroupId).
        $remaining = $accountRepository->findBy(['owner_group_id' => $oldGid]);
        if (count($remaining) === 1) {
            $remaining[0]->setOwnerGroupId(null);
            $em->flush();
        }

        return $this->json([
            'owner_group_id' => null,
            'group_siblings' => [],
        ]);
    }

    #[Route('/admin/user/update', name: 'admin-user-update', options: ['expose' => true])]
    public function updateUser(
        Nutgram $bot,
        Request $request,
        TelegramUserRepository $repository,
        AccountRepository $accountRepository,
        EntityManagerInterface $em,
        LoggerInterface $logger,
        PavilionPhotoService $photoService,
    ): JsonResponse
    {
        $params = $request->request->all();
        $logger->info('##################### admin-user-update', $params);
        if (!$request->request->has('user_id')) {
            return $this->json(['user_id is required'], Response::HTTP_BAD_REQUEST);
        }

        if (!$request->request->has('additional_phones')) {
            $params['additional_phones'] = [];
        }

        $currentUser = $repository->find($request->request->get('user_id'));

        if (!$currentUser) {
            return $this->json([sprintf('User by id: %s was not found', $request->request->get('user_id'))], Response::HTTP_BAD_REQUEST);
        }

        $unblockReason = null;
        if (isset($params['account'])) {
            $account = $params['account'];
            if (isset($account['is_active'])) {
                $account['is_active'] = $account['is_active'] == 'true';
            } else {
                $account['is_active'] = false;
            }

            // unblock_reason is admin-only metadata, not an Account field.
            $unblockReason = $account['unblock_reason'] ?? null;
            unset($account['unblock_reason']);

            // Normalize area: blank or invalid → leave existing value untouched.
            if (isset($account['area'])) {
                $areaInput = trim(str_replace(',', '.', (string)$account['area']));
                if ($areaInput === '' || !is_numeric($areaInput) || (float)$areaInput <= 0) {
                    unset($account['area']);
                } else {
                    $account['area'] = number_format((float)$areaInput, 2, '.', '');
                }
            }

            unset($params['account']);
            $accountContext = [];
            $isWasInActive = true;
            $accountEntity = $currentUser->getAccount() ?: null;
            if (!$accountEntity) {
                $accountEntity = $accountRepository->findOneBy(['account_number' => $account['account_number']]);
            }

            if ($accountEntity) {
                $accountContext += [
                    AbstractNormalizer::OBJECT_TO_POPULATE => $accountEntity
                ];
                $isWasInActive = !$accountEntity->isActive();
            }

            $accountEntity = $this->denormalizer->denormalize(
                $account,
                Account::class,
                null,
                $accountContext
            );

            $em->persist($accountEntity);
            $currentUser->setAccount($accountEntity);
        }

        $context = [
            AbstractNormalizer::OBJECT_TO_POPULATE => $currentUser,
            AbstractNormalizer::CALLBACKS => [
                'additional_phones' => function (?array $additional_phones): ?array {
                    if (!$additional_phones) {
                        return $additional_phones;
                    }

                    return array_values($additional_phones);
                },
            ]
        ];

        $updatedUser = $this->denormalizer->denormalize(
            $params,
            TelegramUser::class,
            null,
            $context
        );

        $em->persist($updatedUser);
        $em->flush();

        if (isset($isWasInActive) && $isWasInActive && $updatedUser->getAccount() && $updatedUser->getAccount()->isActive()) {
            $forgiven = $photoService->forgiveBlockingRequests(
                $updatedUser->getAccount(),
                SchedulePavilionService::createNewDate()
            );
            if ($forgiven > 0) {
                $logger->info(sprintf(
                    'Admin unblock: forgave %d open photo-upload request(s) for account %d',
                    $forgiven,
                    $updatedUser->getAccount()->getId()
                ));
            }
            $logger->info('Admin unblock', [
                'account_id' => $updatedUser->getAccount()->getId(),
                'account_number' => $updatedUser->getAccount()->getAccountNumber(),
                'reason' => $unblockReason ?: 'unspecified',
            ]);

            $unblockText = match ($unblockReason) {
                'photo' => "✅ <b>Доступ до бронювання відновлено.</b>\n\n"
                    . "Дякуємо за надіслане фото — обмеження знято. Можна знову бронювати.",
                'debt' => "✅ <b>Доступ до бронювання відновлено.</b>\n\n"
                    . "Борг сплачено — обмеження знято. Можна знову бронювати.",
                default => "✅ <b>Доступ до бронювання відновлено.</b>\n\nМожна знову бронювати.",
            };

            $bot->sendMessage(
                text: $unblockText,
                chat_id: $updatedUser->getChatId(),
                parse_mode: ParseMode::HTML,
            );
        }

        if (isset($isWasInActive) && !$isWasInActive && $updatedUser->getAccount() && !$updatedUser->getAccount()->isActive()) {
            $bot->sendMessage(
                text: 'Вас <b>ЗАБЛОКУВАЛИ</b> тепер НЕ можете броювати',
                chat_id: $updatedUser->getChatId(),
                parse_mode: ParseMode::HTML
            );
        }

        $response = $this->serializer->serialize(
            $updatedUser, 'json',
            [AbstractNormalizer::IGNORED_ATTRIBUTES => ['additional_phones', 'account']]
        );

        return new JsonResponse($response, Response::HTTP_OK, [], true);
    }

    #############
    # Debt Management
    #############

    #[Route('/admin/debt', name: 'app_admin_debt')]
    public function debt(): Response
    {
        return $this->render('admin/debt.html.twig');
    }

    #[Route('/admin/area', name: 'app_admin_area', methods: [Request::METHOD_GET])]
    public function area(EntityManagerInterface $em): Response
    {
        return $this->renderArea($em);
    }

    #[Route('/admin/area/upload', name: 'app_admin_area_upload', methods: [Request::METHOD_POST])]
    public function uploadArea(
        Request $request,
        AccountRepository $accountRepository,
        EntityManagerInterface $em,
        LoggerInterface $logger,
    ): Response {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('area_file');

        if (!$file || !$file->isValid()) {
            return $this->renderArea($em, ['error' => 'Файл не завантажено або пошкоджено.']);
        }

        $spreadsheet = IOFactory::load($file->getPathname());

        // The registry usually has multiple sheets. We pick the one whose row 1
        // contains "Особовий" (the registry header).
        $worksheet = null;
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $a1 = (string)$sheet->getCell('A1')->getValue();
            $b1 = (string)$sheet->getCell('B1')->getValue();
            $c1 = (string)$sheet->getCell('C1')->getValue();
            if (
                stripos($b1, 'особов') !== false
                || stripos($c1, 'площ') !== false
                || stripos($a1, 'id') !== false
            ) {
                $worksheet = $sheet;
                break;
            }
        }
        if ($worksheet === null) {
            $worksheet = $spreadsheet->getActiveSheet();
        }

        $areaData = [];
        $skipped = 0;
        foreach ($worksheet->getRowIterator(2) as $row) {
            $rowIndex = $row->getRowIndex();
            $accountNumber = $worksheet->getCell('B' . $rowIndex)->getValue();
            $area = $worksheet->getCell('C' . $rowIndex)->getValue();

            if ($accountNumber === null || $area === null) {
                continue;
            }
            $accountNumber = trim((string)$accountNumber);
            $areaStr = trim((string)$area);
            if ($accountNumber === '' || $areaStr === '') {
                continue;
            }

            $areaFloat = (float)str_replace(',', '.', $areaStr);
            if ($areaFloat <= 0) {
                $skipped++;
                continue;
            }

            $areaData[$accountNumber] = $areaFloat;
        }

        $updated = 0;
        $notFound = [];
        foreach ($areaData as $accountNumber => $area) {
            $account = $accountRepository->findOneBy(['account_number' => $accountNumber]);
            if (!$account) {
                $notFound[] = $accountNumber;
                continue;
            }
            $account->setArea(number_format($area, 2, '.', ''));
            $em->persist($account);
            $updated++;
        }

        $em->flush();

        $logger->info('Area upload', [
            'parsed' => count($areaData),
            'updated' => $updated,
            'not_found' => count($notFound),
            'skipped' => $skipped,
        ]);

        return $this->renderArea($em, [
            'success' => sprintf(
                'Опрацьовано рядків: %d. Оновлено акаунтів: %d. Не знайдено в базі: %d. Пропущено (0/нечислових): %d.',
                count($areaData),
                $updated,
                count($notFound),
                $skipped
            ),
            'not_found' => $notFound,
        ]);
    }

    private function renderArea(EntityManagerInterface $em, array $extra = []): Response
    {
        $stats = $em->createQuery(
            'SELECT COUNT(a.id) AS total,
                    SUM(CASE WHEN a.area IS NOT NULL AND a.area > 0 THEN 1 ELSE 0 END) AS with_area
             FROM App\Entity\Account a'
        )->getSingleResult();

        return $this->render('admin/area.html.twig', array_merge([
            'total' => (int)$stats['total'],
            'with_area' => (int)$stats['with_area'],
        ], $extra));
    }

    #[Route('/admin/tariff', name: 'app_admin_tariff', methods: [Request::METHOD_GET])]
    public function tariff(TariffRepository $tariffRepository, EntityManagerInterface $em, DebtPolicy $debtPolicy): Response
    {
        $tariff = $tariffRepository->getOrCreate($em);
        return $this->render('admin/tariff.html.twig', [
            'tariff' => $tariff,
            'fallback_threshold' => $debtPolicy->getThreshold(),
        ]);
    }

    #[Route('/admin/tariff/save', name: 'app_admin_tariff_save', methods: [Request::METHOD_POST])]
    public function tariffSave(
        Request $request,
        TariffRepository $tariffRepository,
        EntityManagerInterface $em,
        LoggerInterface $logger,
        DebtPolicy $debtPolicy,
    ): Response {
        $raw = trim((string)$request->request->get('price_per_meter', ''));
        $normalized = str_replace(',', '.', $raw);

        if ($normalized === '' || !is_numeric($normalized) || (float)$normalized < 0) {
            $tariff = $tariffRepository->getOrCreate($em);
            return $this->render('admin/tariff.html.twig', [
                'tariff' => $tariff,
                'fallback_threshold' => $debtPolicy->getThreshold(),
                'error' => 'Ціна має бути невідʼємним числом (наприклад, 13.50).',
            ]);
        }

        $tariff = $tariffRepository->getOrCreate($em);
        $old = $tariff->getPricePerMeter();
        $tariff->setPricePerMeter(number_format((float)$normalized, 2, '.', ''));
        $em->flush();

        $logger->info('Admin tariff updated', [
            'old_price' => $old,
            'new_price' => $tariff->getPricePerMeter(),
        ]);

        return $this->render('admin/tariff.html.twig', [
            'tariff' => $tariff,
            'fallback_threshold' => $debtPolicy->getThreshold(),
            'success' => sprintf('Збережено. Нова ціна: %s грн/м². Перерахунок порогів відбудеться при наступному завантаженні файлу боржників.', $tariff->getPricePerMeter()),
        ]);
    }

    #[Route('/admin/debt/example', name: 'app_admin_debt_example')]
    public function debtExample(): Response
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Боржники');

        $sheet->setCellValue('A1', 'Боржники (приклад файлу для завантаження)');
        $sheet->mergeCells('A1:C1');
        $sheet->getStyle('A1')->getFont()->setBold(true);

        $sheet->setCellValue('A2', '№ кв.');
        $sheet->setCellValue('B2', 'Особ. рах.');
        $sheet->setCellValue('C2', 'Борг');
        $sheet->getStyle('A2:C2')->getFont()->setBold(true);

        $sheet->setCellValue('A3', '74');
        $sheet->setCellValue('B3', '1010074');
        $sheet->setCellValue('C3', 1350.50);

        foreach (['A', 'B', 'C'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'debt_example_');
        (new XlsxWriter($spreadsheet))->save($tmp);
        $content = file_get_contents($tmp);
        @unlink($tmp);

        return new Response(
            $content,
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="debtors-example.xlsx"',
                'Content-Length' => (string)strlen($content),
            ]
        );
    }

    #[Route('/admin/debt/upload', name: 'app_admin_debt_upload', methods: [Request::METHOD_POST])]
    public function uploadDebt(
        Request $request,
        AccountRepository $accountRepository,
        EntityManagerInterface $em,
        LoggerInterface $logger,
        Nutgram $bot,
        DebtPolicy $debtPolicy
    ): Response
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('debt_file');

        if (!$file || !$file->isValid()) {
            return $this->render('admin/debt.html.twig', [
                'result' => [
                    'success' => false,
                    'processed' => 0,
                    'updated' => 0,
                    'not_found' => 0,
                    'reset' => 0,
                    'missing' => ['Файл не завантажено або пошкоджено'],
                ],
            ]);
        }

        $spreadsheet = IOFactory::load($file->getPathname());
        $worksheet = $spreadsheet->getActiveSheet();

        $debtData = [];
        $processed = 0;

        foreach ($worksheet->getRowIterator(3) as $row) {
            $rowIndex = $row->getRowIndex();
            $accountNumber = $worksheet->getCell('B' . $rowIndex)->getValue();
            $debt = $worksheet->getCell('C' . $rowIndex)->getValue();

            if ($accountNumber === null || $debt === null) {
                continue;
            }

            $accountNumber = trim((string)$accountNumber);
            if ($accountNumber === '' || $accountNumber === 'Сума:') {
                continue;
            }

            $debtData[$accountNumber] = (float)$debt;
            $processed++;
        }

        $logger->info('Debt upload: parsed rows', ['count' => $processed]);

        $updated = 0;
        $notFound = 0;
        $missing = [];
        $blocked = 0;

        foreach ($debtData as $accountNumber => $debt) {
            $account = $accountRepository->findOneBy(['account_number' => $accountNumber]);
            if ($account) {
                $account->setDebt((string)$debt);
                $wasActive = $account->isActive();
                $accountThreshold = $debtPolicy->getThresholdFor($account);

                if ($debtPolicy->isOverThreshold($debt, $account)) {
                    $account->setIsActive(false);
                    $em->persist($account);

                    if ($wasActive) {
                        $blocked++;
                        foreach ($account->getUsers() as $user) {
                            if ($user->getChatId()) {
                                try {
                                    $bot->sendMessage(
                                        text: sprintf(
                                            "🚫 Вас <b>ЗАБЛОКОВАНО</b> через борг: <b>%s грн</b>\n\nПерсональний поріг для вашої квартири — %s грн (150%% від місячної плати).\n\nСплатіть заборгованість для відновлення доступу до бронювання.",
                                            number_format($debt, 2, '.', ' '),
                                            number_format($accountThreshold, 2, '.', ' ')
                                        ),
                                        chat_id: $user->getChatId(),
                                        parse_mode: ParseMode::HTML
                                    );
                                } catch (\Throwable $e) {
                                    $logger->error('Failed to notify user: ' . $e->getMessage());
                                }
                            }
                        }
                    }
                } else {
                    // Debt within threshold: ensure account stays active.
                    $account->setIsActive(true);
                    $em->persist($account);
                }

                $updated++;
            } else {
                $missing[] = $accountNumber;
                $notFound++;
            }
        }

        // Reset debt for accounts NOT present in the uploaded file.
        // The file is treated as a full snapshot of outstanding debt — any account
        // missing from it is considered to have no debt and must be reactivated.
        // We only touch accounts that previously had debt > 0, so admin-deactivated
        // (debt = 0, is_active = false) accounts awaiting confirmation stay untouched.
        $reset = 0;
        $allAccounts = $accountRepository->findAll();
        $uploadedAccountNumbers = array_map('strval', array_keys($debtData));

        foreach ($allAccounts as $account) {
            if (in_array($account->getAccountNumber(), $uploadedAccountNumbers, true)) {
                continue;
            }

            $hadDebt = $account->getDebt() !== null && (float)$account->getDebt() > 0;
            if (!$hadDebt) {
                continue;
            }

            $wasInactive = !$account->isActive();

            $account->setDebt('0');
            $account->setIsActive(true);
            $em->persist($account);
            $reset++;

            if ($wasInactive) {
                foreach ($account->getUsers() as $user) {
                    if ($user->getChatId()) {
                        try {
                            $bot->sendMessage(
                                text: "✅ Ваш борг <b>погашено</b>. Доступ до бронювання відновлено!",
                                chat_id: $user->getChatId(),
                                parse_mode: ParseMode::HTML
                            );
                        } catch (\Throwable $e) {
                            $logger->error('Failed to notify user about debt reset: ' . $e->getMessage());
                        }
                    }
                }
            }
        }

        $em->flush();

        $logger->info('Debt upload complete', [
            'updated' => $updated,
            'not_found' => $notFound,
            'blocked' => $blocked,
            'reset' => $reset,
        ]);

        return $this->render('admin/debt.html.twig', [
            'result' => [
                'success' => true,
                'processed' => $processed,
                'updated' => $updated,
                'not_found' => $notFound,
                'blocked' => $blocked,
                'reset' => $reset,
                'missing' => $missing,
            ],
        ]);
    }
}
