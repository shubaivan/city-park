<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Complaint;
use App\Entity\TelegramUser;
use App\Repository\ComplaintRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The house's problem register: who may file, who may move a status, and the photo
 * plumbing behind both.
 *
 * Filing is open to everyone the ОСББ recognises — see the Complaint class comment for
 * why neither `is_active` nor `isNonResidential()` is consulted. Moving a status is the
 * opposite: only the head of the ОСББ, because "виконано" is a statement about what the
 * ОСББ did, and a register anyone can mark done records nothing.
 *
 * Managers are configured as Telegram ids in `.env.local` (COMPLAINT_MANAGER_TELEGRAM_IDS),
 * the same shape as RESIDENT_CHAT_ID: the set is one or two people and changes about never,
 * so a column and an admin checkbox would be machinery for nothing.
 */
class ComplaintService
{
    public const PHOTO_DIR = 'uploads/complaint-photos';

    public const PAGE_SIZE = 8;

    public function __construct(
        private ComplaintRepository $complaints,
        private ImageStore $images,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private string $managerIds = '',
    ) {}

    /**
     * Anyone with a confirmed особовий рахунок. The register is house business, so an
     * unlinked visitor is out — but a debtor, a photo-blocked resident and the owner of a
     * parking space or a storage room are all in.
     */
    public function mayFile(?Account $account): bool
    {
        return $account instanceof Account;
    }

    public function isManager(?TelegramUser $user): bool
    {
        $id = $user?->getTelegramId();

        if ($id === null || $id === '') {
            return false;
        }

        return in_array((string)$id, $this->managerTelegramIds(), true);
    }

    /** @return string[] */
    public function managerTelegramIds(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', $this->managerIds),
        )));
    }

    public function create(Account $account, ?TelegramUser $author, string $text): Complaint
    {
        $complaint = (new Complaint())
            ->setAccount($account)
            ->setAuthor($author)
            ->setText($this->trimText($text));

        $this->em->persist($complaint);
        $this->em->flush();

        $this->logger->info('complaint filed', [
            'complaint_id' => $complaint->getId(),
            'account_id' => $account->getId(),
            'apartment' => $account->getApartmentNumber(),
        ]);

        return $complaint;
    }

    public function changeStatus(Complaint $complaint, string $status, string $by): void
    {
        $from = $complaint->getStatus();
        $complaint->setStatus($status, $by);
        $this->em->flush();

        $this->logger->info('complaint status changed', [
            'complaint_id' => $complaint->getId(),
            'from' => $from,
            'to' => $status,
            'by' => $by,
        ]);
    }

    public function trimText(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_substr($text, 0, Complaint::TEXT_MAX);
    }

    /**
     * Short label for the list button. The register's list is buttons, not a wall of text
     * — the same call as the rental noticeboard, for the same reason: a screen of
     * descriptions pushes the buttons below the fold.
     */
    public function label(Complaint $complaint): string
    {
        $text = $complaint->getText();

        if (mb_strlen($text) > Complaint::LABEL_MAX) {
            $text = mb_substr($text, 0, Complaint::LABEL_MAX - 1) . '…';
        }

        return $text;
    }

    /**
     * Issue (or refresh) the photo-upload link. Regenerated on every request, so a link
     * forwarded yesterday stops working as soon as a new one is asked for.
     */
    public function issuePhotoToken(Complaint $complaint): string
    {
        $token = bin2hex(random_bytes(16));

        $complaint->setPhotoToken(
            $token,
            new \DateTimeImmutable(sprintf('+%d hours', Complaint::PHOTO_TOKEN_TTL_HOURS)),
        );
        $this->em->flush();

        return $token;
    }

    public function findByToken(?string $token): ?Complaint
    {
        if ($token === null || $token === '') {
            return null;
        }

        $complaint = $this->complaints->findByToken($token);

        if (!$complaint instanceof Complaint) {
            return null;
        }

        $expires = $complaint->getPhotoTokenExpiresAt();

        if (!$expires instanceof \DateTimeImmutable || $expires < new \DateTimeImmutable()) {
            return null;
        }

        return $complaint;
    }

    public function storePhoto(Complaint $complaint, UploadedFile $file, ?string &$error = null): ?string
    {
        if (count($complaint->getPhotos()) >= Complaint::PHOTOS_MAX) {
            $error = 'Більше ' . Complaint::PHOTOS_MAX . ' фото не можна.';

            return null;
        }

        $path = $this->images->store($file, self::PHOTO_DIR, $error);

        if ($path === null) {
            return null;
        }

        $complaint->setPhotos([...$complaint->getPhotos(), $path]);
        $this->em->flush();

        $this->logger->info('complaint photo stored', [
            'complaint_id' => $complaint->getId(),
            'path' => $path,
        ]);

        return $path;
    }

    public function removePhoto(Complaint $complaint, string $publicPath): void
    {
        $complaint->setPhotos(array_filter(
            $complaint->getPhotos(),
            static fn (string $p): bool => $p !== $publicPath,
        ));
        $this->em->flush();

        $this->images->delete($publicPath, self::PHOTO_DIR);
    }

    /**
     * The author withdrawing their own report.
     *
     * Allowed at any status, deliberately. The obvious alternative — letting them delete
     * only while it is still 🆕 — protects a record the ОСББ has started working on, but
     * the common reasons to withdraw (a typo, a duplicate, the thing fixed itself) do not
     * politely stop happening the moment Людмила taps «в роботі», and a resident who
     * cannot remove their own mistaken entry just files a second one saying "ignore the
     * previous". Retention would delete it within the month anyway.
     *
     * Photos go with it: nothing else references them, and orphaned files under
     * public/uploads are how a disk fills up quietly.
     */
    public function delete(Complaint $complaint): void
    {
        $id = $complaint->getId();

        foreach ($complaint->getPhotos() as $path) {
            $this->images->delete($path, self::PHOTO_DIR);
        }

        $this->em->remove($complaint);
        $this->em->flush();

        $this->logger->info('complaint deleted by author', ['complaint_id' => $id]);
    }

    public function updateText(Complaint $complaint, string $text): void
    {
        $complaint->setText($this->trimText($text));
        $this->em->flush();

        $this->logger->info('complaint text edited', ['complaint_id' => $complaint->getId()]);
    }

    public function burnPhotoToken(Complaint $complaint): void
    {
        $complaint->setPhotoToken(null, null);
        $this->em->flush();
    }

    /**
     * Photos stay when a complaint is finished — the picture of the repaired thing, and
     * of what it looked like before, is the point of keeping the entry at all.
     */
    public function statusLabel(string $status): string
    {
        return match ($status) {
            Complaint::STATUS_NEW => '🆕 Нова',
            Complaint::STATUS_IN_PROGRESS => '🔧 В роботі',
            Complaint::STATUS_DONE => '✅ Виконано',
            default => $status,
        };
    }
}
