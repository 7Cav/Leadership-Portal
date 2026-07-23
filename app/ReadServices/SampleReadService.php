<?php

namespace App\ReadServices;

/**
 * Shapes the props for the throwaway skeleton page (Story 1.1's stack proof),
 * refactored to be layer-legal in Story 1.2: the Controller now delegates prop
 * shaping to a ReadService (Controller → ReadService is a legal AD-1 edge) instead
 * of assembling data inline. It carries no domain logic — it just demonstrates the
 * read seam every later screen builds on, and is removed once a real page replaces it.
 */
class SampleReadService
{
    /**
     * @return array{appName: string, stack: list<string>}
     */
    public function props(): array
    {
        return [
            'appName' => config('app.name'),
            'stack' => ['Laravel 13', 'Inertia 3', 'Vue 3.5', 'Tailwind 4', 'MySQL 8.4'],
        ];
    }
}
