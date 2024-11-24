<?php

declare(strict_types=1);

namespace Elabftw\Models\Notifications;

use Elabftw\Enums\Notifications;

class MathJsFailed extends WebOnlyNotifications
{
    protected Notifications $category = Notifications::MathJsFailed;

    public function __construct(private int $entityId, private string $entityPage)
    {
        parent::__construct();
    }

    protected function getBody(): array
    {
        return array(
            'entity_id' => $this->entityId,
            'entity_page' => $this->entityPage,
        );
    }
}
