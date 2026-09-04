<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\Complaint;
use App\Entity\AccountStatusLog;
use App\Entity\RentalListing;
use App\Entity\ScheduledSet;
use App\Entity\TelegramUser;
use App\Repository\AccountRepository;
use App\Repository\ComplaintRepository;
use App\Repository\AccountStatusLogRepository;
use App\Repository\PavilionPhotoRepository;
use App\Repository\PhotoUploadRequestRepository;
use App\Repository\RentalListingRepository;
use App\Repository\ScheduledSetRepository;
use App\Repository\TariffRepository;
use App\Repository\TelegramUserRepository;
use App\Entity\BlockVoteCampaign;
use App\Repository\BlockVoteBallotRepository;
use App\Repository\BlockVoteCampaignRepository;
use App\Service\AccountStatusAuditor;
use App\Service\BlockReasonResolver;
use App\Service\BlockVoteService;
use App\Service\DebtAnnouncer;
use App\Service\ImportArchive;
use App\Service\OwnerGroupService;
use App\Service\PropertyRegistry;
use App\Service\AccountAccessService;
use App\Service\ComplaintService;
use App\Service\DebtPolicy;
use App\Service\PavilionPhotoService;
use App\Service\RentalListingService;
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
        Request $request,
        AccountRepository $accountRepository,
        BlockVoteCampaignRepository $campaignRepository,
        BlockVoteBallotRepository $ballotRepository,
        ScheduledSetRepository $scheduledSetRepository,
        BlockVoteService $voteService,
    ): Response {
        // Optional ?candidate=<особовий рахунок> → show a confirmation preview of WHO is about
        // to be put up for a vote (apartment, residents, status) before the mass broadcast.
        $preview = null;
        $candidateNumber = trim((string)$request->query->get('candidate', ''));
        if ($candidateNumber !== '') {
            $cand = $accountRepository->findOneBy(['account_number' => $candidateNumber]);
            if (!$cand) {
                $preview = ['found' => false, 'account_number' => $candidateNumber];
            } else {
                $names = [];
                foreach ($cand->getUsers() as $u) {
                    $label = trim(implode(' ', array_filter([$u->getFirstName(), $u->getLastName()])));
                    if ($u->getUsername()) {
                        $label .= ($label !== '' ? ' ' : '') . '@' . $u->getUsername();
                    }
                    if ($u->getPhoneNumber()) {
                        $label .= ($label !== '' ? ' · ' : '') . $u->getPhoneNumber();
                    }
                    $names[] = $label !== '' ? $label : ('Telegram ID ' . $u->getTelegramId());
                }
                $eligible = count($voteService->eligibleVoters($cand));
                $lastBooking = $scheduledSetRepository->lastBookingForAccount($cand);
                $preview = [
                    'found' => true,
                    'label' => $voteService->candidateLabel($cand),
                    'last_booking' => $lastBooking
                        ? sprintf(
                            'Альтанка %s · %s',
                            $lastBooking->getPavilion() == 1 ? 'Перша' : 'Друга',
                            $lastBooking->getScheduledDateTime()->format('d.m.Y H:i')
                        )
                        : null,
                    'account_number' => $cand->getAccountNumber(),
                    'apartment_number' => $cand->getApartmentNumber(),
                    'street' => $cand->getStreet(),
                    'house_number' => $cand->getHouseNumber(),
                    'is_active' => $cand->isActive(),
                    'is_apartment' => $cand->isApartment(),
                    'vote_block_count' => $cand->getVoteBlockCount(),
                    'debt' => $cand->getDebt(),
                    'names' => $names,
                    'eligible' => $eligible,
                    'needed' => (int) floor($eligible * \App\Entity\BlockVoteCampaign::PASS_FRACTION) + 1,
                    'already_open' => $campaignRepository->findOpenForCandidate($cand) !== null,
                ];
            }
        }

        $open = [];
        foreach ($campaignRepository->findOpen() as $campaign) {
            $tally = $ballotRepository->tally($campaign);
            $open[] = [
                'id' => $campaign->getId(),
                'label' => $voteService->candidateLabel($campaign->getCandidate()),
                'account_number' => $campaign->getCandidate()->getAccountNumber(),
                'blocks' => $campaign->getCandidate()->getVoteBlockCount(),
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
                'blocks' => $campaign->getCandidate()->getVoteBlockCount(),
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
            'preview' => $preview,
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

    #[Route('/admin/block-vote/remind', name: 'app_admin_block_vote_remind', methods: [Request::METHOD_POST])]
    public function blockVoteRemind(
        Request $request,
        BlockVoteCampaignRepository $campaignRepository,
        BlockVoteService $voteService,
    ): Response {
        $id = (int)$request->request->get('campaign_id');
        $campaign = $id > 0 ? $campaignRepository->find($id) : null;
        if (!$campaign || !$campaign->isOpen()) {
            $this->addFlash('error', 'Голосування не знайдено або вже завершене.');
            return $this->redirectToRoute('app_admin_block_votes');
        }

        $n = $voteService->dispatchReminders($campaign);
        $this->addFlash('success', sprintf(
            'Нагадування поставлено в чергу для %d мешканців, які ще не проголосували. Розсилка йде у фоні.',
            $n,
        ));
        return $this->redirectToRoute('app_admin_block_votes');
    }

    #############
    # Rental listings ("здається квартира")
    #############

    #[Route('/admin/complaints', name: 'app_admin_complaints', methods: [Request::METHOD_GET])]
    public function complaints(ComplaintRepository $complaints, ComplaintService $complaintService): Response
    {
        $list = $complaints->findAllNewestFirst();

        return $this->render('admin/complaints.html.twig', [
            'complaints' => $list,
            // Both fetched in one query each: the page renders every complaint with its
            // discussion and the author's contact under it, and per-row lookups here are
            // how a page of 200 entries becomes 400 queries.
            'threads' => $complaintService->threadsFor($list),
        ]);
    }

    /**
     * The desktop half of what the head of the ОСББ can also do from the bot card.
     *
     * The bot is where a status actually gets moved — she is on a phone, standing next to
     * the thing that broke. This exists for the review pass: a table of everything, and a
     * place to type "що зробили", which is awkward on a phone keyboard.
     */
    #[Route('/admin/complaints/status', name: 'app_admin_complaint_status', methods: [Request::METHOD_POST])]
    public function complaintStatus(
        Request $request,
        ComplaintRepository $complaints,
        ComplaintService $complaintService,
    ): Response {
        $complaint = $complaints->find((int)$request->request->get('id'));
        $status = (string)$request->request->get('status');

        if (!$complaint instanceof Complaint || !in_array($status, Complaint::STATUSES, true)) {
            return $this->redirectToRoute('app_admin_complaints');
        }

        $resolution = trim((string)$request->request->get('resolution'));

        // A hold must say what it is waiting for. The service throws otherwise — which
        // would be a 500 on a form submit — so the panel catches it here and says so.
        if ($status === Complaint::STATUS_ON_HOLD && $resolution === '') {
            $this->addFlash('error', sprintf(
                'Заявка №%d: щоб відкласти, напишіть у полі «нотатка», чого вона чекає. '
                    . 'Без причини «відкладено» читається мешканцями як «нам байдуже».',
                $complaint->getId(),
            ));

            return $this->redirectToRoute('app_admin_complaints');
        }

        $complaintService->changeStatus(
            $complaint,
            $status,
            (string)$this->getUser()?->getUserIdentifier(),
            // An empty box means "no note", not "wipe the note": passing null lets
            // changeStatus keep it, or clear it when a hold is being left.
            note: $resolution !== '' ? $resolution : null,
        );

        return $this->redirectToRoute('app_admin_complaints');
    }

    /**
     * The desktop half of the official discussion.
     *
     * Typing a real answer — «майстер приїде у вівторок після 14:00» — on a phone keyboard
     * is why /admin/complaints exists at all. The comment reaches the author's Telegram the
     * same way it would from the bot: the notification lives in ComplaintService, not in
     * the handler, precisely so this path is not the silent one.
     */
    #[Route('/admin/complaints/comment', name: 'app_admin_complaint_comment', methods: [Request::METHOD_POST])]
    public function complaintComment(
        Request $request,
        ComplaintRepository $complaints,
        ComplaintService $complaintService,
    ): Response {
        $complaint = $complaints->find((int)$request->request->get('id'));
        $text = $complaintService->trimComment((string)$request->request->get('text'));

        if ($complaint instanceof Complaint && $text !== '') {
            $complaintService->comment(
                $complaint,
                null,
                $text,
                official: true,
                label: $complaintService->adminLabel($this->getUser()?->getUserIdentifier()),
            );

            $this->addFlash('notice', sprintf(
                'Заявка №%d: повідомлення додано в обговорення, автор отримав сповіщення.',
                $complaint->getId(),
            ));
        }

        return $this->redirectToRoute('app_admin_complaints');
    }

    #[Route('/admin/rentals', name: 'app_admin_rentals', methods: [Request::METHOD_GET])]
    public function rentals(RentalListingRepository $listingRepository, DebtPolicy $debtPolicy): Response
    {
        $listings = $listingRepository->findRecent();

        // Debt is shown for context only: a debtor may still advertise their own property
        // (see RentalListingService::canPublish), but an admin taking a call about a
        // listing should not have to look the account up in a second tab.
        $debtByListing = [];
        foreach ($listings as $listing) {
            $account = $listing->getAccount();
            $debtByListing[$listing->getId()] = [
                'debt' => (float)$account->getDebt(),
                'over' => (float)$account->getDebt() > $debtPolicy->getThresholdFor($account),
            ];
        }

        return $this->render('admin/rentals.html.twig', [
            'listings' => $listings,
            'debtByListing' => $debtByListing,
            'lifetimeDays' => RentalListing::LIFETIME_DAYS,
        ]);
    }

    #[Route('/admin/rentals/block', name: 'app_admin_rental_block', methods: [Request::METHOD_POST])]
    public function rentalBlock(
        Request $request,
        RentalListingRepository $listingRepository,
        RentalListingService $rentalService,
    ): Response {
        $id = (int)$request->request->get('listing_id');
        $listing = $id > 0 ? $listingRepository->find($id) : null;

        if (!$listing || !$listing->isActive()) {
            $this->addFlash('error', 'Оголошення не знайдено або вже неактивне.');
            return $this->redirectToRoute('app_admin_rentals');
        }

        $rentalService->block($listing, (string)$this->getUser()?->getUserIdentifier());
        $this->addFlash('success', 'Оголошення знято зі списку.');

        return $this->redirectToRoute('app_admin_rentals');
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
        AccountRepository $accountRepository,
        Request $request,
    ) {
        $dataTable = $repository
            ->getDataTablesData($request->request->all());

        $this->attachPhotoInfo($dataTable, $photoRepository, $requestRepository, $photoService);
        $this->attachVoteBlockCount($dataTable, $accountRepository);

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
     * Decorate schedule rows with the booker's community-vote-block tally. Rows carry only
     * account_number, so we map counts by that in one query rather than per-row lookups.
     */
    private function attachVoteBlockCount(array &$rows, AccountRepository $accountRepository): void
    {
        if (!$rows) {
            return;
        }

        $counts = [];
        foreach ($accountRepository->createQueryBuilder('a')
                     ->select('a.account_number AS an', 'a.vote_block_count AS c')
                     ->andWhere('a.vote_block_count > 0')
                     ->getQuery()->getResult() as $r) {
            $counts[(string)$r['an']] = (int)$r['c'];
        }

        foreach ($rows as &$row) {
            $row['vote_blocks'] = $counts[(string)($row['account_number'] ?? '')] ?? 0;
        }
        unset($row);
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
    #############
    # Об'єкти нерухомості — the register of properties, next to the register of people
    #############

    /**
     * Every property in the house, with its owners and its owner-group.
     *
     * `/admin/users` answers "who is this person and what do they owe"; this answers "what
     * is this object and who stands behind it". They are different questions and the panel
     * had only the first, which meant an object nobody had linked themselves to could not
     * be looked at at all — and those are exactly the ones nobody is chasing for the debt.
     *
     * Rendered server-side rather than as another DataTable: 172 rows is small enough that
     * paging is machinery for nothing, and a plain page with a filter box works on the
     * phone this is read on without a JS build step behind every change.
     */
    #[Route('/admin/objects', name: 'app_admin_objects', methods: [Request::METHOD_GET])]
    public function objects(PropertyRegistry $registry): Response
    {
        $rows = $registry->overview();

        return $this->render('admin/objects.html.twig', [
            'rows' => $rows,
            'stats' => $registry->stats($rows),
            'houses' => $registry->houses($rows),
            // The template names sibling objects, and the label rules live in one place.
            'registry' => $registry,
        ]);
    }

    /**
     * "Це той самий власник" — merge two objects into one owner group.
     *
     * A plain form post rather than the JSON endpoint the users page uses, because this
     * page has no JavaScript of its own. Both go through OwnerGroupService.
     */
    /**
     * Add an object nobody lives in yet.
     *
     * Creating an `Account` was possible before only as a side effect of *moving a person*:
     * typing an unknown особовий рахунок into a resident's card creates the row and drags
     * that resident onto it. That is right for "цей мешканець насправді у кв. 86" and wrong
     * for everything else — a кладова entered that way would take its owner off their flat.
     *
     * So objects that exist on paper but have no linked resident — a storage room, a parking
     * space, a flat whose owner has never opened the bot — had no way in at all, and those
     * are precisely the rows whose debt reaches nobody.
     */
    #[Route('/admin/objects/create', name: 'app_admin_objects_create', methods: [Request::METHOD_POST])]
    public function objectsCreate(
        Request $request,
        AccountRepository $accountRepository,
        EntityManagerInterface $em,
    ): Response {
        $number = trim((string)$request->request->get('account_number'));
        $house = trim((string)$request->request->get('house_number'));
        $unit = trim((string)$request->request->get('apartment_number'));
        $street = trim((string)$request->request->get('street')) ?: 'Козацька';
        $type = (string)$request->request->get('unit_type');
        $area = str_replace(',', '.', trim((string)$request->request->get('area')));

        if ($number === '' || $house === '' || $unit === '') {
            $this->addFlash('error', 'Потрібні особовий рахунок, будинок і номер обʼєкта.');

            return $this->redirectToRoute('app_admin_objects');
        }

        // The особовий рахунок is what the debt import matches on, so a duplicate silently
        // sends somebody's arrears to the wrong row. Refuse rather than create a second one.
        if ($accountRepository->findOneBy(['account_number' => $number]) instanceof Account) {
            $this->addFlash('error', sprintf('Рахунок %s уже є в базі.', $number));

            return $this->redirectToRoute('app_admin_objects');
        }

        $account = (new Account())
            ->setAccountNumber($number)
            ->setHouseNumber($house)
            ->setApartmentNumber($unit)
            ->setStreet($street);

        // Active, because a row created by hand must not arrive already blocked.
        //
        // The debt is deliberately NOT set: `setDebt()` stamps `debt_updated_at`, and an
        // object that has never been in an import would then claim «боргу немає станом на
        // сьогодні» — a statement about a file nobody ever uploaded. The column defaults to
        // '0' with no date, which is exactly the "we have not been told" the screens now
        // render.
        $account->setIsActive(true);

        if (isset(Account::UNIT_TYPES[$type])) {
            $account->setUnitType($type);
        }

        if ($area !== '' && is_numeric($area) && (float)$area > 0) {
            $account->setArea($area);
        }

        $em->persist($account);
        $em->flush();

        $this->addFlash('notice', sprintf(
            'Додано: %s — %s, буд. %s, %s. Прив’язати мешканця можна в розділі «Люди».',
            $number,
            Account::UNIT_TYPES[$account->getUnitType()],
            $house,
            $unit,
        ));

        return $this->redirectToRoute('app_admin_objects');
    }

    /**
     * Correct what kind of property an object is.
     *
     * The type used to be recomputed from the особовий рахунок on every read, so a row with
     * a mistyped number was permanently the wrong kind of thing and nobody could say
     * otherwise. It decides the label on the public debtors' board, the address in a
     * complaint posted to the residents' chat and the right to book the pavilion, so it is
     * worth being able to fix by hand.
     */
    #[Route('/admin/objects/type', name: 'app_admin_objects_type', methods: [Request::METHOD_POST])]
    public function objectsSetType(
        Request $request,
        AccountRepository $accountRepository,
        EntityManagerInterface $em,
    ): Response {
        $account = $accountRepository->find((int)$request->request->get('account_id'));
        $type = (string)$request->request->get('unit_type');

        if (!$account instanceof Account) {
            $this->addFlash('error', 'Об’єкт не знайдено.');

            return $this->redirectToRoute('app_admin_objects');
        }

        if (!isset(Account::UNIT_TYPES[$type])) {
            $this->addFlash('error', 'Невідомий тип об’єкта.');

            return $this->redirectToRoute('app_admin_objects');
        }

        $account->setUnitType($type);
        $em->flush();

        $this->addFlash('notice', sprintf(
            '%s — тепер %s.',
            $account->getAccountNumber(),
            Account::UNIT_TYPES[$type],
        ));

        return $this->redirectToRoute('app_admin_objects');
    }

    #[Route('/admin/objects/group/link', name: 'app_admin_objects_group_link', methods: [Request::METHOD_POST])]
    public function objectsGroupLink(
        Request $request,
        AccountRepository $accountRepository,
        OwnerGroupService $ownerGroups,
    ): Response {
        $source = $accountRepository->find((int)$request->request->get('account_id'));
        $partnerNumber = trim((string)$request->request->get('partner_account_number'));
        $partner = $partnerNumber === ''
            ? null
            : $accountRepository->findOneBy(['account_number' => $partnerNumber]);

        if (!$source instanceof Account) {
            $this->addFlash('error', 'Об’єкт не знайдено.');
        } elseif (!$partner instanceof Account) {
            $this->addFlash('error', sprintf('Особового рахунку «%s» немає в базі бота.', $partnerNumber));
        } else {
            $error = $ownerGroups->link($source, $partner);

            $error === null
                ? $this->addFlash('notice', sprintf(
                    'Об’єднано: %s і %s — тепер це один власник.',
                    $source->getAccountNumber(),
                    $partner->getAccountNumber(),
                ))
                : $this->addFlash('error', $error);
        }

        return $this->redirectToRoute('app_admin_objects');
    }

    #[Route('/admin/objects/group/unlink', name: 'app_admin_objects_group_unlink', methods: [Request::METHOD_POST])]
    public function objectsGroupUnlink(
        Request $request,
        AccountRepository $accountRepository,
        OwnerGroupService $ownerGroups,
    ): Response {
        $account = $accountRepository->find((int)$request->request->get('account_id'));

        if (!$account instanceof Account) {
            $this->addFlash('error', 'Об’єкт не знайдено.');

            return $this->redirectToRoute('app_admin_objects');
        }

        $error = $ownerGroups->unlink($account);

        $error === null
            ? $this->addFlash('notice', sprintf('%s відв’язано від групи.', $account->getAccountNumber()))
            : $this->addFlash('error', $error);

        return $this->redirectToRoute('app_admin_objects');
    }

    #[Route('/admin/users', name: 'app_admin_users')]
    public function users(
        EntityManagerInterface $em,
        AccountRepository $accountRepository,
        TelegramUserRepository $repository,
    ): Response
    {
        $fieldNames = TelegramUser::$dataTableFields;
        $fieldNames[] = 'action';
        // Appended LAST on purpose: telegram_users.js columnDefs target columns by index
        // (5,6,7,8,10,16), so a new column must not shift those — it goes after `action`.
        $fieldNames[] = 'vote_blocks';
        // Same rule again: appended after vote_blocks so the indexed columnDefs keep
        // pointing at the columns they were written for.
        $fieldNames[] = 'role';
        // And again. ПІБ is drawn inside the name cell rather than as a column of its own
        // — see telegram_users.js — but it still has to travel in the row payload.
        $fieldNames[] = 'full_name';

        array_map(function ($k) use (&$dataTableColumnData) {
            $dataTableColumnData[] = ['data' => $k];
        }, $fieldNames);

        $residents = $repository->countByHouse();

        return $this->render('admin/telegram-users.html.twig', [
            'th_keys' => $fieldNames,
            'dataTableKeys' => $dataTableColumnData,
            // For the per-building filter row. Derived from the data — a hardcoded list
            // silently drops a building the day one appears — and counting *residents*,
            // not objects: this table lists people, and a chip promising the flat count
            // next to a longer list of people is a small lie about the filter.
            'houses' => array_map(
                static fn (string $house): array => [
                    'house' => $house,
                    'count' => $residents[$house] ?? 0,
                ],
                $accountRepository->distinctHouseNumbers(),
            ),
        ]);
    }

    #############
    # Картка мешканця — a page, not a modal
    #############

    /**
     * One resident, on a page of their own.
     *
     * This was a modal: a narrow column over the table, unreadable on anything, and — the
     * part that actually cost time — it had no address. Close it and there was no way back
     * except finding the row again in a server-side table of 453 people. A card that other
     * screens link to (the objects register names its owners) has to be somewhere you can
     * link *to*.
     *
     * Every action on it is its own small form posting to its own endpoint, instead of one
     * JSON payload that meant "save the whole person" — so moving somebody to another flat
     * can no longer happen as a side effect of correcting their площа.
     */
    #[Route('/admin/users/{id}', name: 'app_admin_resident', requirements: ['id' => '\d+'], methods: [Request::METHOD_GET])]
    public function resident(
        int $id,
        TelegramUserRepository $repository,
        DebtPolicy $debtPolicy,
        BlockReasonResolver $blockReasonResolver,
        PropertyRegistry $registry,
        OwnerGroupService $ownerGroups,
        TariffRepository $tariffRepository,
        EntityManagerInterface $em,
    ): Response {
        $user = $repository->find($id);

        if (!$user instanceof TelegramUser) {
            throw $this->createNotFoundException('Немає такого мешканця');
        }

        $account = $user->getAccount();

        return $this->render('admin/resident.html.twig', [
            'user' => $user,
            'account' => $account,
            'threshold' => $account ? $debtPolicy->getThresholdFor($account) : null,
            'tariff' => (float)$tariffRepository->getOrCreate($em)->getPricePerMeter(),
            'block' => $blockReasonResolver->resolve($account),
            'siblings' => $account ? $ownerGroups->siblings($account) : [],
            'registry' => $registry,
            'history' => $account ? $this->statusLogRepository->findRecentForAccount($account, 10) : [],
            'roommates' => $account
                ? array_values(array_filter(
                    $account->getUsers()->toArray(),
                    static fn (TelegramUser $u): bool => $u->getId() !== $user->getId(),
                ))
                : [],
        ]);
    }

    /**
     * ПІБ as the ОСББ's registry spells it.
     *
     * Separate from the Telegram name on purpose: that one is whatever the person chose to
     * call themselves, and it is how the accountant recognises who is writing in the chat.
     * The registry name is what matches a квитанція. Both are true about the same person.
     */
    #[Route('/admin/users/{id}/name', name: 'app_admin_resident_name', requirements: ['id' => '\d+'], methods: [Request::METHOD_POST])]
    public function residentName(int $id, Request $request, TelegramUserRepository $repository, EntityManagerInterface $em): Response
    {
        $user = $this->residentOr404($id, $repository);
        $user->setFullName((string)$request->request->get('full_name'));
        $em->flush();

        $this->addFlash('notice', $user->getFullName() === null
            ? 'ПІБ очищено — показуємо ім’я з Telegram.'
            : 'ПІБ збережено: ' . $user->getFullName());

        return $this->redirectToRoute('app_admin_resident', ['id' => $id]);
    }

    /** Who this person is to the flat — owner / family / tenant. */
    #[Route('/admin/users/{id}/role', name: 'app_admin_resident_role', requirements: ['id' => '\d+'], methods: [Request::METHOD_POST])]
    public function residentRole(int $id, Request $request, TelegramUserRepository $repository, EntityManagerInterface $em): Response
    {
        $user = $this->residentOr404($id, $repository);
        $user->setRole(($role = (string)$request->request->get('role')) === '' ? null : $role);
        $em->flush();

        $this->addFlash('notice', 'Роль оновлено: ' . $user->getRoleLabel());

        return $this->redirectToRoute('app_admin_resident', ['id' => $id]);
    }

    /** The flat's own fields. Never the особовий рахунок — that is a move, and it has its own form. */
    #[Route('/admin/users/{id}/account', name: 'app_admin_resident_account', requirements: ['id' => '\d+'], methods: [Request::METHOD_POST])]
    public function residentAccount(int $id, Request $request, TelegramUserRepository $repository, EntityManagerInterface $em): Response
    {
        $user = $this->residentOr404($id, $repository);
        $account = $user->getAccount();

        if (!$account instanceof Account) {
            $this->addFlash('error', 'У мешканця немає особового рахунку — спершу прив’яжіть його.');

            return $this->redirectToRoute('app_admin_resident', ['id' => $id]);
        }

        $account
            ->setHouseNumber(trim((string)$request->request->get('house_number')))
            ->setApartmentNumber(trim((string)$request->request->get('apartment_number')))
            ->setStreet(trim((string)$request->request->get('street')) ?: 'Козацька');

        // Blank or nonsense leaves the stored area alone: the registry import is the real
        // source, and a typo here silently changes the block threshold for the whole flat.
        $area = trim(str_replace(',', '.', (string)$request->request->get('area')));

        if ($area !== '' && is_numeric($area) && (float)$area > 0) {
            $account->setArea(number_format((float)$area, 2, '.', ''));
        }

        $em->flush();
        $this->addFlash('notice', 'Дані об’єкта збережено.');

        return $this->redirectToRoute('app_admin_resident', ['id' => $id]);
    }

    /** Block or unblock, with every side effect, through the one service that knows them. */
    #[Route('/admin/users/{id}/status', name: 'app_admin_resident_status', requirements: ['id' => '\d+'], methods: [Request::METHOD_POST])]
    public function residentStatus(
        int $id,
        Request $request,
        TelegramUserRepository $repository,
        AccountAccessService $access,
    ): Response {
        $user = $this->residentOr404($id, $repository);
        $account = $user->getAccount();

        if (!$account instanceof Account) {
            $this->addFlash('error', 'У мешканця немає особового рахунку.');

            return $this->redirectToRoute('app_admin_resident', ['id' => $id]);
        }

        $reason = (string)$request->request->get('reason') ?: null;

        $error = $request->request->get('action') === 'block'
            ? $access->block($account, $reason)
            : $access->unblock($account, $reason);

        $error === null
            ? $this->addFlash('notice', 'Статус змінено, мешканців сповіщено.')
            : $this->addFlash('error', $error);

        return $this->redirectToRoute('app_admin_resident', ['id' => $id]);
    }

    /**
     * Move this person to another особовий рахунок.
     *
     * Its own form, on purpose. It used to ride inside the general save — type a different
     * number into the о/р field and the person moved — which is exactly the shape of edit
     * somebody makes by accident while fixing an address. The flat they leave keeps its
     * other residents and its data untouched.
     */
    #[Route('/admin/users/{id}/move', name: 'app_admin_resident_move', requirements: ['id' => '\d+'], methods: [Request::METHOD_POST])]
    public function residentMove(
        int $id,
        Request $request,
        TelegramUserRepository $repository,
        AccountRepository $accountRepository,
        EntityManagerInterface $em,
        LoggerInterface $logger,
    ): Response {
        $user = $this->residentOr404($id, $repository);
        $number = trim((string)$request->request->get('account_number'));

        if ($number === '') {
            $this->addFlash('error', 'Вкажіть особовий рахунок.');

            return $this->redirectToRoute('app_admin_resident', ['id' => $id]);
        }

        $target = $accountRepository->findOneBy(['account_number' => $number]);

        if (!$target instanceof Account) {
            $this->addFlash('error', sprintf(
                'Рахунку %s немає в базі. Створіть об’єкт у розділі «Об’єкти нерухомості», потім поверніться сюди.',
                $number,
            ));

            return $this->redirectToRoute('app_admin_resident', ['id' => $id]);
        }

        $from = $user->getAccount();
        $user->setAccount($target);
        $em->flush();

        $logger->info('Admin account reassignment', [
            'user_id' => $user->getId(),
            'from_account_number' => $from?->getAccountNumber(),
            'to_account_number' => $target->getAccountNumber(),
        ]);

        $this->addFlash('notice', sprintf(
            'Перенесено на рахунок %s (%s). Попередній рахунок і його мешканці не змінились.',
            $target->getAccountNumber(),
            trim(sprintf('буд. %s, %s', $target->getHouseNumber(), $target->getApartmentNumber())),
        ));

        return $this->redirectToRoute('app_admin_resident', ['id' => $id]);
    }

    /** Conditional owners: extra phones the bot recognises as belonging to this flat. */
    #[Route('/admin/users/{id}/phones', name: 'app_admin_resident_phones', requirements: ['id' => '\d+'], methods: [Request::METHOD_POST])]
    public function residentPhones(int $id, Request $request, TelegramUserRepository $repository, EntityManagerInterface $em): Response
    {
        $user = $this->residentOr404($id, $repository);

        $names = (array)$request->request->all('phone_name');
        $values = (array)$request->request->all('phone_value');
        $phones = [];

        foreach ($values as $i => $value) {
            $value = trim((string)$value);

            if ($value === '') {
                continue;
            }

            $phones[] = [
                'property_name' => trim((string)($names[$i] ?? '')) ?: 'Умовний власник',
                'property_value' => $value,
            ];
        }

        $user->setAdditionalPhones($phones);
        $em->flush();

        $this->addFlash('notice', sprintf('Збережено умовних власників: %d.', count($phones)));

        return $this->redirectToRoute('app_admin_resident', ['id' => $id]);
    }

    #[Route('/admin/users/{id}/group/link', name: 'app_admin_resident_group_link', requirements: ['id' => '\d+'], methods: [Request::METHOD_POST])]
    public function residentGroupLink(
        int $id,
        Request $request,
        TelegramUserRepository $repository,
        AccountRepository $accountRepository,
        OwnerGroupService $ownerGroups,
    ): Response {
        $user = $this->residentOr404($id, $repository);
        $account = $user->getAccount();
        $partner = $accountRepository->findOneBy([
            'account_number' => trim((string)$request->request->get('partner_account_number')),
        ]);

        if (!$account instanceof Account || !$partner instanceof Account) {
            $this->addFlash('error', 'Не знайдено рахунок для об’єднання.');

            return $this->redirectToRoute('app_admin_resident', ['id' => $id]);
        }

        $error = $ownerGroups->link($account, $partner);

        $error === null
            ? $this->addFlash('notice', 'Об’єкти об’єднані: тепер це один власник.')
            : $this->addFlash('error', $error);

        return $this->redirectToRoute('app_admin_resident', ['id' => $id]);
    }

    #[Route('/admin/users/{id}/group/unlink', name: 'app_admin_resident_group_unlink', requirements: ['id' => '\d+'], methods: [Request::METHOD_POST])]
    public function residentGroupUnlink(
        int $id,
        Request $request,
        TelegramUserRepository $repository,
        AccountRepository $accountRepository,
        OwnerGroupService $ownerGroups,
    ): Response {
        $this->residentOr404($id, $repository);
        $account = $accountRepository->find((int)$request->request->get('account_id'));

        if (!$account instanceof Account) {
            $this->addFlash('error', 'Об’єкт не знайдено.');

            return $this->redirectToRoute('app_admin_resident', ['id' => $id]);
        }

        $error = $ownerGroups->unlink($account);

        $error === null
            ? $this->addFlash('notice', sprintf('%s відв’язано.', $account->getAccountNumber()))
            : $this->addFlash('error', $error);

        return $this->redirectToRoute('app_admin_resident', ['id' => $id]);
    }

    private function residentOr404(int $id, TelegramUserRepository $repository): TelegramUser
    {
        $user = $repository->find($id);

        if (!$user instanceof TelegramUser) {
            throw $this->createNotFoundException('Немає такого мешканця');
        }

        return $user;
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
                $row['vote_blocks'] = 0;
                continue;
            }
            $account = $accountRepository->findOneBy(['account_number' => $accNum]);
            $row['debt_threshold'] = $account
                ? number_format($debtPolicy->getThresholdFor($account), 2, '.', '')
                : null;
            $row['vote_blocks'] = $account ? $account->getVoteBlockCount() : 0;
            $reason = $blockReasonResolver->resolve($account);
            $row['block_reason_label'] = $reason['label'] ?? null;
            $row['block_reason_details'] = $reason['details'] ?? null;
        }
        unset($row);

        // Show the label, not the enum value: the column is read by the accountant and
        // the head of the ОСББ, neither of whom should have to translate "tenant".
        foreach ($dataTable as &$row) {
            $row['role'] = TelegramUser::ROLES[$row['role'] ?? ''] ?? '—';
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

    #[Route('/admin/debt', name: 'app_admin_debt')]
    public function debt(ImportArchive $archive): Response
    {
        return $this->render('admin/debt.html.twig', [
            'archive' => $archive->recent(ImportArchive::KIND_DEBT),
        ]);
    }

    /**
     * Hand back one archived spreadsheet.
     *
     * Behind ^/admin like everything else — these files carry every account's arrears, so
     * they are exactly as private as the panel that lists them. The filename arrives over
     * HTTP and is validated inside ImportArchive::path(), which also re-checks that the
     * resolved path is still inside the archive directory.
     */
    #[Route('/admin/import-archive/{kind}/{name}', name: 'app_admin_import_archive_download', methods: [Request::METHOD_GET])]
    public function downloadImportArchive(string $kind, string $name, ImportArchive $archive): Response
    {
        $path = $archive->path($kind, $name);

        if ($path === null) {
            throw $this->createNotFoundException('No such archived import');
        }

        return $this->file($path, $name);
    }

    #[Route('/admin/area', name: 'app_admin_area', methods: [Request::METHOD_GET])]
    public function area(EntityManagerInterface $em, ImportArchive $archive): Response
    {
        return $this->renderArea($em, archive: $archive);
    }

    #[Route('/admin/area/upload', name: 'app_admin_area_upload', methods: [Request::METHOD_POST])]
    public function uploadArea(
        Request $request,
        AccountRepository $accountRepository,
        EntityManagerInterface $em,
        LoggerInterface $logger,
        ImportArchive $archive,
    ): Response {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('area_file');

        if (!$file || !$file->isValid()) {
            return $this->renderArea($em, ['error' => 'Файл не завантажено або пошкоджено.'], $archive);
        }

        // Archived for the same reason as the debt file, and arguably a stronger one: every
        // per-account block threshold is area × tariff × 1.5, so this registry is the input
        // to who gets blocked, and it is overwritten in place too.
        $archive->store($file, ImportArchive::KIND_AREA, $this->getUser()?->getUserIdentifier());

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
        ], $archive);
    }

    private function renderArea(EntityManagerInterface $em, array $extra = [], ?ImportArchive $archive = null): Response
    {
        $stats = $em->createQuery(
            'SELECT COUNT(a.id) AS total,
                    SUM(CASE WHEN a.area IS NOT NULL AND a.area > 0 THEN 1 ELSE 0 END) AS with_area
             FROM App\Entity\Account a'
        )->getSingleResult();

        return $this->render('admin/area.html.twig', array_merge([
            'total' => (int)$stats['total'],
            'with_area' => (int)$stats['with_area'],
            'archive' => $archive?->recent(ImportArchive::KIND_AREA) ?? [],
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
        PavilionPhotoService $photoService,
        DebtAnnouncer $announcer,
        ImportArchive $archive,
    ): Response
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('debt_file');

        if (!$file || !$file->isValid()) {
            return $this->render('admin/debt.html.twig', [
                'archive' => $archive->recent(ImportArchive::KIND_DEBT),
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

        // Keep the spreadsheet before parsing it. Everything downstream overwrites: the
        // debt column is written in place and DebtSnapshot keeps only the totals, so
        // without this the only copy of what the house was billed on lives in the
        // accountant's Telegram history.
        $archive->store($file, ImportArchive::KIND_DEBT, $this->getUser()?->getUserIdentifier());

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
        $missingDebt = 0.0;
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
                        // The audit entry is not optional bookkeeping — it is the only
                        // record of who blocked this account and why, and the unblock
                        // branch below has always written one. Without it a web upload
                        // blocked people silently: on 03.09.2026 an import took the house
                        // from 9 blocked accounts to 22, and account_status_log showed
                        // nothing but unblocks for that whole day. The CLI paths
                        // (debt:import-file, debt:recompute) always logged; only this one
                        // did not, and this is the one the accountant actually uses.
                        $this->statusAuditor->log(
                            $account, true, false,
                            AccountStatusLog::SOURCE_DEBT_IMPORT,
                            'debt',
                            sprintf('web debt upload: debt=%.2f, threshold=%.2f', $debt, $accountThreshold),
                        );
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
                // Kept with its figure, not just counted. The house total the bot publishes
                // is the sum over accounts we know about, and rows we cannot match are
                // silently absent from it — so "скільки боргу лишилось за кадром" has to be
                // answerable on the same screen, or the published number quietly drifts
                // away from the ОСББ's own books.
                $missing[$accountNumber] = $debt;
                $missingDebt += $debt;
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

        // Same tail as debt:import-file: record the new totals and tell the residents'
        // chat. Non-fatal by construction — the accountant's upload has already landed.
        $announced = $announcer->afterImport();

        $logger->info('Debt upload complete', [
            'updated' => $updated,
            'not_found' => $notFound,
            'missing_debt' => round($missingDebt, 2),
            'blocked' => $blocked,
            'reset' => $reset,
            'announced' => $announced,
        ]);

        return $this->render('admin/debt.html.twig', [
            'archive' => $archive->recent(ImportArchive::KIND_DEBT),
            'result' => [
                'success' => true,
                'processed' => $processed,
                'updated' => $updated,
                'not_found' => $notFound,
                'missing_debt' => round($missingDebt, 2),
                'blocked' => $blocked,
                'reset' => $reset,
                'missing' => $missing,
                'announced' => $announced,
            ],
        ]);
    }
}
