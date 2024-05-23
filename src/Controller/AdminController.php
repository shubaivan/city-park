<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\TelegramUser;
use App\Repository\AccountRepository;
use App\Repository\TelegramUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
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
        EntityManagerInterface $em
    ): JsonResponse
    {
        $params = $request->request->all();

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

            $account = $this->denormalizer->denormalize(
                $account,
                Account::class,
                null,
                $accountContext
            );

            $em->persist($account);
            $currentUser->setAccount($account);
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
}
