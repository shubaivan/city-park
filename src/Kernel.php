<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * The whole application runs in Kyiv, because that is what the database holds.
     *
     * Every datetime in this schema is a Kyiv **wall clock** reading: the code writes
     * `new \DateTime('now', new \DateTimeZone('Europe/Kyiv'))` (or converts into it), and
     * Doctrine's `datetime` type stores `Y-m-d H:i:s` with no offset. Reading it back
     * builds a `\DateTime` in PHP's default zone — which on this server is **UTC** — so
     * `11:22` came back meaning 11:22 UTC, i.e. 14:22 Kyiv. Every comparison of a stored
     * moment against «now» was then three hours out.
     *
     * It showed on 09.09.2026 as a builder's pass that had expired at 11:22 still reading
     * «✅ Діє до 11:22» at 14:00 — a guard would have let the crew in for three hours after
     * the flat closed the window, which is the one thing that feature exists to prevent.
     * The same arithmetic sits under `Account::isUnderVoteBlock()`, the 30-day expiry of
     * every advert and each vote deadline; nothing failed anywhere, they were simply all
     * late by the offset.
     *
     * Set here rather than in php.ini so it travels with the code and holds in tests,
     * and in the constructor rather than at boot because Doctrine's hydration is not the
     * only thing that reads it. It makes a bare `new \DateTime()` mean Kyiv too, which is
     * what the rest of this codebase already writes by hand.
     */
    public function __construct(string $environment, bool $debug)
    {
        date_default_timezone_set('Europe/Kyiv');

        parent::__construct($environment, $debug);
    }
}
