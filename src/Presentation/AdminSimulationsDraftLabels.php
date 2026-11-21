<?php

declare(strict_types=1);

namespace App\Presentation;

/**
 * Safe labels for the SuperAdmin simulations draft list.
 */
final class AdminSimulationsDraftLabels
{
    public static function updatedAt(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('d.m.Y H:i');
    }
}
