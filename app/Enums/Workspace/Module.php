<?php

declare(strict_types=1);

namespace App\Enums\Workspace;

/**
 * The optional parts of the app a workspace can switch on or off. A module is
 * available while its config switch is on (`trypost.<module>.enabled`, the
 * global kill switch) and active in a workspace unless that workspace turned
 * it off (`workspaces.modules`).
 */
enum Module: string
{
    case Inbox = 'inbox';
    case Trends = 'trends';

    public function isAvailable(): bool
    {
        return (bool) config("trypost.{$this->value}.enabled");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $module): string => $module->value, self::cases());
    }
}
