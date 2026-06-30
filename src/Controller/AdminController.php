<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\AccountStatusLog;
use App\Entity\ScheduledSet;
use App\Entity\TelegramUser;
use App\Repository\AccountRepository;
use App\Repository\AccountStatusLogRepository;
use App\Repository\PavilionPhotoRepository;
use App\Repository\PhotoUploadRequestRepository;
use App\Repository\ScheduledSetRepository;
use App\Repository\TariffRepository;
use App\Repository\TelegramUserRepository;
use App\Entity\BlockVoteCampaign;
use App\Repository\BlockVoteBallotRepository;
use App\Repository\BlockVoteCampaignRepository;
use App\Service\AccountStatusAuditor;
use App\Service\BlockReasonResolver;
use App\Service\BlockVoteService;
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
        protected readonly SerializerInterface $serializer,
        protected readonly AccountStatusAuditor $statusAuditor,
        protected readonly AccountStatusLogRepository $statusLogRepository,
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
    # Community vote-to-block
    #############

    #[Route('/admin/block-votes', name: 'app_admin_block_votes', methods: [Request::METHOD_GET])]
    public function blockVotes(
        BlockVoteCampaignRepository $campaignRepository,
        BlockVoteBallotRepository $ballotRepository,
        BlockVoteService $voteService,
    ): Response {
        $open = [];
        foreach ($campaignRepository->findOpen() as $campaign) {
            $tally = $ballotRepository->tally($campaign);
            $open[] = [
                'id' => $campaign->getId(),
                'label' => $voteService->candidateLabel($campaign->getCandidate()),
                'account_number' => $campaign->getCandidate()->getAccountNumber(),
                'eligible' => $campaign->getEligibleCount(),
                'yes' => $tally['yes'],
                'no' => $tally['no'],
                'needed' => $campaign->yesNeeded(),
                'deadline' => $campaign->getDeadlineAt(),
                'created_by' => $campaign->getCreatedBy(),
            ];
        }

        $recent = [];
        foreach ($campaignRepository->findRecent(50) as $campaign) {
            if ($campaign->isOpen()) {
                continue;
            }
            $recent[] = [
                'label' => $voteService->candidateLabel($campaign->getCandidate()),
                'account_number' => $campaign->getCandidate()->getAccountNumber(),
                'status' => $campaign->getStatus(),
                'eligible' => $campaign->getEligibleCount(),
                'yes' => $campaign->getResultYes(),
                'no' => $campaign->getResultNo(),
                'needed' => $campaign->yesNeeded(),
                'closed_at' => $campaign->getClosedAt(),
            ];
        }

        return $this->render('admin/block-votes.html.twig', [
            'open' => $open,
            'recent' => $recent,
            'vote_days' => BlockVoteService::VOTE_DAYS,
            'block_days' => BlockVoteService::BLOCK_DAYS,
        ]);
    }

    #[Route('/admin/block-vote/create', name: 'app_admin_block_vote_create', methods: [Request::METHOD_POST])]
    public function blockVoteCreate(
        Request $request,
        AccountRepository $accountRepository,
        BlockVoteService $voteService,
    ): Response {
        $accountNumber = trim((string)$request->request->get('account_number'));
        if ($accountNumber === '') {
            $this->addFlash('error', 'Вкажіть особовий рахунок кандидата.');
            return $this->redirectToRoute('app_admin_block_votes');
        }

        $account = $accountRepository->findOneBy(['account_number' => $accountNumber]);
        if (!$account) {
            $this->addFlash('error', sprintf('Аккаунт з особовим рахунком «%s» не знайдено.', $accountNumber));
            return $this->redirectToRoute('app_admin_block_votes');
        }

        try {
            $actor = $this->getUser()?->getUserIdentifier();
            $campaign = $voteService->openCampaign($account, $actor);
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('app_admin_block_votes');
        }

        $this->addFlash('success', sprintf(
            'Голосування відкрито: %s. Потрібно «За»: %d з %d. Сповіщено мешканців.',
            $voteService->candidateLabel($account),
            $campaign->yesNeeded(),
            $campaign->getEligibleCount(),
        ));
        return $this->redirectToRoute('app_admin_block_votes');
    }

    #[Route('/admin/block-vote/cancel', name: 'app_admin_block_vote_cancel', methods: [Request::METHOD_POST])]
    public function blockVoteCancel(
        Request $request,
        BlockVoteCampaignRepository $campaignRepository,
        BlockVoteService $voteService,
    ): Response {
        $id = (int)$request->request->get('campaign_id');
        $campaign = $id > 0 ? $campaignRepository->find($id) : null;
        if (!$campaign) {
            $this->addFlash('error', 'Голосування не знайдено.');
            return $this->redirectToRoute('app_admin_block_votes');
        }

        $voteService->cancelCampaign($campaign);
        $this->addFlash('success', 'Голосування скасовано.');
        return $this->redirectToRoute('app_admin_block_votes');
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
        // Fetch all PavilionPhoto + all requests once and match by account_number+pavilion+window.
        // Both open and resolved requests are loaded: a resolved request with no photo on file
        // (blocked then forgiven / bulk-unblocked) must render distinctly from a session still
        // awaiting a photo — otherwise both look like "⏳ Очікує".
        $em = $photoRepository->createQueryBuilder('p')->getEntityManager();
        $photos = $em->createQuery(
            'SELECT p, a FROM App\Entity\PavilionPhoto p JOIN p.account a'
        )->getResult();
        $requests = $em->createQuery(
            'SELECT r, a FROM App\Entity\PhotoUploadRequest r JOIN r.account a'
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
                if ($matchedReq->getResolvedAt() !== null) {
                    // Request closed but no photo on file (the photo case is handled above):
                    // session was blocked then forgiven / bulk-unblocked without an upload.
                    $row['photo_status'] = 'forgiven';
                } else {
                    $row['photo_status'] = $matchedReq->getBlockedAt() ? 'blocked' : 'pending';
                }
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
    public function getUsersDataTable(
        TelegramUserRepository $repository,
        AccountRepository $accountRepository,
        DebtPolicy $debtPolicy,
        BlockReasonResolver $blockReasonResolver,
        TariffRepository $tariffRepository,
        EntityManagerInterface $em,
        Request $request,
    ) {
        // Inject tariff + fallback so the repository can build the per-row
        // debt-threshold predicate used by the "Заблоковані за борг" filter.
        $params = $request->request->all();
        $params['_debt_price_per_meter'] = (float)$tariffRepository->getOrCreate($em)->getPricePerMeter();
        $params['_debt_fallback_threshold'] = (float)$debtPolicy->getThreshold();

        $dataTable = $repository->getDataTablesData($params);

        foreach ($dataTable as &$row) {
            $accNum = $row['account_number'] ?? null;
            if ($accNum === null) {
                $row['debt_threshold'] = null;
                $row['block_reason_label'] = null;
                $row['block_reason_details'] = null;
                continue;
            }
            $account = $accountRepository->findOneBy(['account_number' => $accNum]);
            $row['debt_threshold'] = $account
                ? number_format($debtPolicy->getThresholdFor($account), 2, '.', '')
                : null;
            $reason = $blockReasonResolver->resolve($account);
            $row['block_reason_label'] = $reason['label'] ?? null;
            $row['block_reason_details'] = $reason['details'] ?? null;
        }
        unset($row);

        return $this->json(
            array_merge(
                [
                    "draw" => $request->request->get('draw'),
                    "recordsTotal" => $repository->getDataTablesData($params, true, true),
                    "recordsFiltered" => $repository->getDataTablesData($params, true),
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
        TariffRepository $tariffRepository,
        DebtPolicy $debtPolicy,
        BlockReasonResolver $blockReasonResolver,
        EntityManagerInterface $em,
    ): JsonResponse
    {
        $telegramUser = $repository->getUserInfoById($id);

        if (!$telegramUser) {
            return $this->json([sprintf('User by id: %s was not found', $id)], Response::HTTP_BAD_REQUEST);
        }

        $telegramUser['group_siblings'] = [];
        $telegramUser['debt_threshold'] = null;
        $telegramUser['tariff_price_per_meter'] = (float)$tariffRepository->getOrCreate($em)->getPricePerMeter();
        $telegramUser['fallback_threshold'] = $debtPolicy->getThreshold();
        $telegramUser['block_reason_label'] = null;
        $telegramUser['block_reason_details'] = null;
        $telegramUser['status_history'] = [];

        if (!empty($telegramUser['account_id'])) {
            $account = $accountRepository->find($telegramUser['account_id']);
            if ($account) {
                $telegramUser['debt_threshold'] = number_format($debtPolicy->getThresholdFor($account), 2, '.', '');
                $reason = $blockReasonResolver->resolve($account);
                $telegramUser['block_reason_label'] = $reason['label'] ?? null;
                $telegramUser['block_reason_details'] = $reason['details'] ?? null;
                foreach ($this->statusLogRepository->findRecentForAccount($account, 5) as $entry) {
                    $telegramUser['status_history'][] = [
                        'new_active' => $entry->getNewActive(),
                        'source' => $entry->getSource(),
                        'reason_code' => $entry->getReasonCode(),
                        'reason_text' => $entry->getReasonText(),
                        'actor' => $entry->getActorUsername(),
                        'at' => $entry->getCreatedAt()->format('Y-m-d H:i'),
                    ];
                }
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

            // unblock_reason / block_reason are admin-only metadata, not Account fields.
            $unblockReason = $account['unblock_reason'] ?? null;
            $blockReason = $account['block_reason'] ?? null;
            unset($account['unblock_reason'], $account['block_reason']);

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

            // Block reason is mandatory on active → blocked transition so the audit log,
            // bot notification, and admin UI all have a non-empty cause to display.
            $isCurrentlyActive = $accountEntity ? $accountEntity->isActive() : true;
            $willBeBlocked = $account['is_active'] === false;
            if ($isCurrentlyActive && $willBeBlocked && !in_array($blockReason, ['debt', 'photo', 'other'], true)) {
                return $this->json(
                    ['Оберіть причину блокування (борг / фото / інша)'],
                    Response::HTTP_BAD_REQUEST,
                );
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
            // An explicit admin unblock overrides any active community vote-block too, so the
            // 30-day window doesn't linger and re-gate later debt/photo unblocks.
            $updatedUser->getAccount()->setBlockedUntil(null);
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

            $this->statusAuditor->log(
                $updatedUser->getAccount(), false, true,
                AccountStatusLog::SOURCE_ADMIN,
                $unblockReason ?: 'other',
                $forgiven > 0 ? sprintf('forgave %d open photo request(s)', $forgiven) : null,
            );
            $em->flush();

            $unblockText = match ($unblockReason) {
                'photo' => "✅ <b>Доступ до бронювання відновлено.</b>\n\n"
                    . "Дякуємо за надіслане фото — обмеження знято. Можна знову бронювати.",
                'debt' => "✅ <b>Доступ до бронювання відновлено.</b>\n\n"
                    . "Борг сплачено — обмеження знято. Можна знову бронювати.\n\n"
                    . "<i>Нагадуємо: блок вмикається автоматично, якщо борг перевищить персональний поріг (площа × тариф ОСББ × 1.5, тобто 150% місячної плати).</i>",
                default => "✅ <b>Доступ до бронювання відновлено.</b>\n\nМожна знову бронювати.",
            };

            $this->notifyAccountUsers($bot, $logger, $updatedUser->getAccount(), $unblockText, 'unblock');
        }

        if (isset($isWasInActive) && !$isWasInActive && $updatedUser->getAccount() && !$updatedUser->getAccount()->isActive()) {
            $logger->info('Admin block', [
                'account_id' => $updatedUser->getAccount()->getId(),
                'account_number' => $updatedUser->getAccount()->getAccountNumber(),
                'reason' => $blockReason ?: 'unspecified',
            ]);

            $this->statusAuditor->log(
                $updatedUser->getAccount(), true, false,
                AccountStatusLog::SOURCE_ADMIN,
                $blockReason,
            );
            $em->flush();

            $blockText = match ($blockReason) {
                'debt' => "⛔ <b>Ваш аккаунт заблоковано</b>\n\n"
                    . "Причина: <b>борг</b> — сума перевищила персональний поріг (площа × тариф ОСББ × 1.5).\n\n"
                    . "Зверніться для розблокування до Аліни Бухгалтера (+380 93 658 32 02) або голови ОСББ Люди (+380 67 470 46 24).",
                'photo' => "⛔ <b>Ваш аккаунт заблоковано</b>\n\n"
                    . "Причина: не завантажене фото після бронювання.\n\n"
                    . "Зверніться для розблокування до Аліни Бухгалтера (+380 93 658 32 02) або голови ОСББ Люди (+380 67 470 46 24).",
                default => "⛔ <b>Ваш аккаунт заблоковано</b>\n\n"
                    . "Зверніться для уточнення причини та розблокування до Аліни Бухгалтера (+380 93 658 32 02) або голови ОСББ Люди (+380 67 470 46 24).",
            };

            $this->notifyAccountUsers(
                $bot,
                $logger,
                $updatedUser->getAccount(),
                $blockText,
                'block',
            );
        }

        $response = $this->serializer->serialize(
            $updatedUser, 'json',
            [AbstractNormalizer::IGNORED_ATTRIBUTES => ['additional_phones', 'account']]
        );

        return new JsonResponse($response, Response::HTTP_OK, [], true);
    }

    /**
     * Block / unblock applies to the whole Account, so the notice must reach every
     * TelegramUser hanging off it — not just the row the admin happened to click on.
     * Skips users without a chat_id and swallows per-user send errors so one offline
     * family member can't fail the whole admin save.
     */
    private function notifyAccountUsers(
        Nutgram $bot,
        LoggerInterface $logger,
        Account $account,
        string $text,
        string $kind,
    ): void {
        foreach ($account->getUsers() as $user) {
            /** @var TelegramUser $user */
            if (!$user->getChatId()) {
                continue;
            }
            try {
                $bot->sendMessage(
                    text: $text,
                    chat_id: $user->getChatId(),
                    parse_mode: ParseMode::HTML,
                );
            } catch (\Throwable $t) {
                $logger->warning(sprintf('admin %s notice send failed', $kind), [
                    'account_id' => $account->getId(),
                    'user_id' => $user->getId(),
                    'chat_id' => $user->getChatId(),
                    'error' => $t->getMessage(),
                ]);
            }
        }
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
        DebtPolicy $debtPolicy,
        PavilionPhotoService $photoService
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
                                            "🚫 Вас <b>ЗАБЛОКОВАНО</b> через борг: <b>%s грн</b>\n\n"
                                            . "Персональний поріг для вашої квартири: <b>%s грн</b>\n"
                                            . "<i>(площа × тариф ОСББ × 1.5 = 150%% місячної плати)</i>\n\n"
                                            . "Сплатіть заборгованість, щоб поновити доступ до бронювання.",
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
                    // Debt within threshold: reactivate — UNLESS a standing photo block
                    // must keep the account down. is_active is shared between debt and photo
                    // blocks, so clearing a debt must never lift a photo block: that stays
                    // until an admin clears it explicitly.
                    if (!$wasActive && !$photoService->hasOpenBlockingRequest($account) && !$account->isUnderVoteBlock()) {
                        $account->setIsActive(true);
                        $this->statusAuditor->log(
                            $account, false, true,
                            AccountStatusLog::SOURCE_DEBT_IMPORT,
                            'debt',
                            'web debt upload: debt within threshold',
                        );
                    }
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

            // Clear the debt unconditionally, but only restore access if no standing photo
            // block remains — a photo block must outlive the debt reset (admin-only release).
            $keepBlockedByPhoto = $wasInactive && ($photoService->hasOpenBlockingRequest($account) || $account->isUnderVoteBlock());
            if (!$keepBlockedByPhoto) {
                if ($wasInactive) {
                    $account->setIsActive(true);
                    $this->statusAuditor->log(
                        $account, false, true,
                        AccountStatusLog::SOURCE_DEBT_IMPORT,
                        'debt',
                        'web debt upload: reset (not in file)',
                    );
                }
            } else {
                $logger->info('Debt reset but account kept blocked by open photo request', [
                    'account_id' => $account->getId(),
                ]);
            }
            $em->persist($account);
            $reset++;

            if ($wasInactive && !$keepBlockedByPhoto) {
                foreach ($account->getUsers() as $user) {
                    if ($user->getChatId()) {
                        try {
                            $bot->sendMessage(
                                text: "✅ Ваш борг <b>повністю погашено</b> — доступ до бронювання відновлено.\n\n"
                                    . "<i>Нагадуємо: блок вмикається автоматично, якщо борг перевищить персональний поріг (площа × тариф ОСББ × 1.5, тобто 150% місячної плати).</i>",
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
