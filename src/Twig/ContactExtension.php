<?php

namespace App\Twig;

use App\Entity\TelegramUser;
use App\Service\GuardService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * The bot's way of writing a person to ring, for the panel's templates: the panel and the
 * bot's «Контакт власника» must never write the same person two ways (21.11.2026), so the
 * templates call GuardService rather than retyping its rules in Twig.
 */
final class ContactExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            // ПІБ from the registry, else the Telegram name; «—» when there is neither.
            new TwigFilter('contact_name', static fn (?TelegramUser $person): string => GuardService::contactName($person)),
            // «+380…» with every non-digit stripped; «—» when the bot never got one.
            new TwigFilter('contact_phone', static fn (?TelegramUser $person): string => GuardService::contactPhone($person)),
        ];
    }
}
