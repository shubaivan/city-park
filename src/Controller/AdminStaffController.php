<?php

namespace App\Controller;

use App\Repository\ServiceStaffRepository;
use App\Service\CleaningStaffService;
use App\Service\PhoneKey;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * «🧹 Клінінг» in the panel: the numbers the bot recognises as cleaning staff. Same thing
 * as `bin/console staff:cleaning`. ROLE_ADMIN only (the /admin catch-all in security.yaml).
 */
class AdminStaffController extends AbstractController
{
    #[Route('/admin/staff', name: 'app_admin_staff', methods: [Request::METHOD_GET])]
    public function index(ServiceStaffRepository $staff): Response
    {
        return $this->render('admin/staff.html.twig', ['rows' => $staff->findAllOrdered()]);
    }

    #[Route('/admin/staff/add', name: 'app_admin_staff_add', methods: [Request::METHOD_POST])]
    public function add(Request $request, CleaningStaffService $staff, LoggerInterface $logger): Response
    {
        $phone = trim((string)$request->request->get('phone'));
        $label = trim((string)$request->request->get('label')) ?: 'Клінінг';

        if (PhoneKey::of($phone) === '') {
            $this->addFlash('error', 'Номер занадто короткий.');

            return $this->redirectToRoute('app_admin_staff');
        }

        $by = (string)$this->getUser()?->getUserIdentifier();
        $row = $staff->add($phone, $label, $by);
        $logger->info('service staff added in the panel', ['id' => $row->getId(), 'by' => $by]);
        $this->addFlash('success', sprintf('Додано: %s («%s»). Нехай відкриє бот і поділиться номером.', $row->getPhone(), $row->getLabel()));

        return $this->redirectToRoute('app_admin_staff');
    }

    #[Route('/admin/staff/toggle', name: 'app_admin_staff_toggle', methods: [Request::METHOD_POST])]
    public function toggle(Request $request, ServiceStaffRepository $repo, CleaningStaffService $staff, LoggerInterface $logger): Response
    {
        $row = $repo->find((int)$request->request->get('id'));

        if ($row === null) {
            $this->addFlash('error', 'Запис не знайдено.');

            return $this->redirectToRoute('app_admin_staff');
        }

        $by = (string)$this->getUser()?->getUserIdentifier();

        if ($row->isActive()) {
            $staff->deactivate($row->getPhone());
            $this->addFlash('success', sprintf('Вимкнено: %s.', $row->getPhone()));
        } else {
            $staff->add($row->getPhone(), $row->getLabel(), $by);
            $this->addFlash('success', sprintf('Увімкнено знову: %s.', $row->getPhone()));
        }
        $logger->info('service staff toggled in the panel', ['id' => $row->getId(), 'active' => $row->isActive(), 'by' => $by]);

        return $this->redirectToRoute('app_admin_staff');
    }
}
