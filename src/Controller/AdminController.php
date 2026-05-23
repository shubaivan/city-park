<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\ScheduledSet;
use App\Entity\TelegramUser;
use App\Repository\AccountRepository;
use App\Repository\PavilionPhotoRepository;
use App\Repository\PhotoUploadRequestRepository;
use App\Repository\ScheduledSetRepository;
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

        $photosByKey = [];
        foreach ($photoRepository->findAll() as $photo) {
            $key = $photo->getAccount()->getId() . ':' . $photo->getPavilion() . ':' . $photo->getSessionStartAt()->format('Y-m-d H:i');
            $photosByKey[$key] = $photo;
        }

        return $this->render('admin/photo-requests.html.twig', [
            'open' => $open,
            'recent' => $recent,
            'photosByKey' => $photosByKey,
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
        TelegramUserRepository $repository
    ): JsonResponse
    {
        $telegramUser = $repository->getUserInfoById($id);

        if (!$telegramUser) {
            return $this->json([sprintf('User by id: %s was not found', $id)], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($telegramUser, Response::HTTP_OK);
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

        if (isset($params['account'])) {
            $account = $params['account'];
            if (isset($account['is_active'])) {
                $account['is_active'] = $account['is_active'] == 'true';
            } else {
                $account['is_active'] = false;
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
            $bot->sendMessage(
                text: 'Вас <b>АКТИВУВАЛИ</b> тепер можете броювати',
                chat_id: $updatedUser->getChatId(),
                parse_mode: ParseMode::HTML
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
            $accountNumber = $worksheet->getCell('B' . $row->getRowIndex())->getValue();
            $debt = $worksheet->getCell('C' . $row->getRowIndex())->getValue();

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

                if ($debtPolicy->isOverThreshold($debt)) {
                    $account->setIsActive(false);
                    $em->persist($account);

                    if ($wasActive) {
                        $blocked++;
                        foreach ($account->getUsers() as $user) {
                            if ($user->getChatId()) {
                                try {
                                    $bot->sendMessage(
                                        text: sprintf(
                                            "🚫 Вас <b>ЗАБЛОКОВАНО</b> через борг: <b>%s грн</b>\n\nСплатіть заборгованість для відновлення доступу до бронювання.",
                                            number_format($debt, 2, '.', ' ')
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
