<?php

namespace App\Controller;

use App\Entity\Product;
use App\Entity\TelegramUser;
use App\Entity\UserOrder;
use App\Repository\ProductRepository;
use App\Repository\TelegramUserRepository;
use App\Repository\UserOrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
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
        #[MapEntity(id: 'id')] TelegramUser $telegramUser,
    ): JsonResponse
    {
        $response = $this->serializer->serialize(
            $telegramUser,
            'json',
            [AbstractNormalizer::IGNORED_ATTRIBUTES => ['scheduledSet']]
        );

        return new JsonResponse($response, Response::HTTP_OK, [], true);
    }

    #[Route('/admin/user/update', name: 'admin-user-update', options: ['expose' => true])]
    public function updateUser(
        Request $request,
        TelegramUserRepository $repository,
        EntityManagerInterface $em
    ): JsonResponse
    {
        $params = $request->request->all();
        $context = [
            AbstractNormalizer::CALLBACKS => [
                'additional_phones' => function (?array $additional_phones): ?array {
                    if (!$additional_phones) {
                        return $additional_phones;
                    }

                    return array_values($additional_phones);
                },
            ]
        ];
        if (!$request->request->has('user_id')) {
            throw new \Exception('user_id is required');
        }
        if (!$request->request->has('additional_phones')) {
            $params['additional_phones'] = [];
        }

        $currentUser = $repository->find($request->request->get('user_id'));
        $context += [
            AbstractNormalizer::OBJECT_TO_POPULATE => $currentUser,
        ];

        $updatedUser = $this->denormalizer->denormalize(
            $params,
            TelegramUser::class,
            null,
            $context
        );

        $em->persist($updatedUser);
        $em->flush();

        $response = $this->serializer->serialize(
            $updatedUser, 'json',
            [AbstractNormalizer::IGNORED_ATTRIBUTES => ['additional_phones']]
        );

        return new JsonResponse($response, Response::HTTP_OK, [], true);
    }
}
