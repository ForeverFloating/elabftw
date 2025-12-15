<?php

declare(strict_types=1);

namespace Elabftw\Models\Notifications;

use Elabftw\Enums\Notifications;
use Override;

/**
 * When there was an error during pdf generation because of math.js
 */
final class MathJsFailed extends WebOnlyNotifications
{
    protected Notifications $category = Notifications::MathJsFailed;

    public function __construct(private int $entityId, private string $entityPage)
    {
        parent::__construct();
    }

    #[Override]
    /**
     * @psalm-suppress MissingOverrideAttribute
     */
    protected function getBody(): array
    {
        return array(
            'entity_id' => $this->entityId,
            'entity_page' => $this->entityPage,
        );
    }
}
