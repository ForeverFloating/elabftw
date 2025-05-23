<?php

/**
 * @author Nicolas CARPi <nico-git@deltablot.email>
 * @copyright 2024 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */

declare(strict_types=1);

namespace Elabftw\Import;

use Elabftw\AuditEvent\Import as AuditEventImport;
use Elabftw\Enums\Action;
use Elabftw\Enums\BasePermissions;
use Elabftw\Enums\EntityType;
use Elabftw\Enums\Storage;
use Elabftw\Exceptions\ImproperActionException;
use Elabftw\Interfaces\ImportInterface;
use Elabftw\Interfaces\QueryParamsInterface;
use Elabftw\Models\AbstractRest;
use Elabftw\Models\AuditLogs;
use Elabftw\Models\Config;
use Elabftw\Models\Users;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Handle import request
 */
final class Handler extends AbstractRest
{
    private const array ALLOWED_EXTENSIONS = array('.eln', '.csv');

    private const int AUDIT_THRESHOLD = 12;

    public function __construct(private Users $requester, private LoggerInterface $logger) {}

    #[Override]
    public function readAll(?QueryParamsInterface $queryParams = null): array
    {
        return array(
            'allowed_extensions' => self::ALLOWED_EXTENSIONS,
            'max_filesize' => UploadedFile::getMaxFilesize(),
            'max_upload_size' => Config::fromEnv('MAX_UPLOAD_SIZE'),
            'max_upload_time' => (int) Config::fromEnv('MAX_UPLOAD_TIME'),
        );
    }

    #[Override]
    public function postAction(Action $action, array $reqBody): int
    {
        $Importer = $this->getImporter($reqBody);
        $Importer->import();
        $inserted = $Importer->getInserted();
        if ($inserted > self::AUDIT_THRESHOLD) {
            /** @psalm-suppress RedundantCast had an error during eln import where userid was a string for some reason... */
            AuditLogs::create(new AuditEventImport((int) ($this->requester->userid ?? 0), $inserted));
        }
        return $inserted;
    }

    #[Override]
    public function getApiPath(): string
    {
        return 'api/v2/import/';
    }

    private function getImporter(array $reqBody): ImportInterface
    {
        $owner = (int) ($reqBody['owner'] ?? $this->requester->userid);
        if ($owner !== $this->requester->userid && $this->requester->isAdminOf($owner)) {
            $this->requester = new Users($owner, $this->requester->team);
        }
        $canread = $reqBody['canread'] ?? BasePermissions::Team->toJson();
        $canwrite = $reqBody['canwrite'] ?? BasePermissions::User->toJson();
        switch ($reqBody['file']->getClientOriginalExtension()) {
            case 'eln':
                return new Eln(
                    $this->requester,
                    $canread,
                    $canwrite,
                    $reqBody['file'],
                    Storage::CACHE->getStorage()->getFs(),
                    $this->logger,
                    EntityType::tryFrom((string) $reqBody['entity_type']), // can be null
                    category: (int) $reqBody['category'],
                );
            case 'csv':
                return new Csv(
                    $this->requester,
                    $canread,
                    $canwrite,
                    $reqBody['file'],
                    $this->logger,
                    EntityType::tryFrom((string) $reqBody['entity_type']) ?? EntityType::Items,
                    category: (int) $reqBody['category'],
                );
            default:
                throw new ImproperActionException(sprintf(
                    _('Error: invalid file extension for import. Allowed extensions: %s.'),
                    implode(', ', self::ALLOWED_EXTENSIONS)
                ));
        }
    }
}
