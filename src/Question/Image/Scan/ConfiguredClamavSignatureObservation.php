<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

/**
 * Disk versions must match the published TXT fields, and VERSION's loaded daily
 * must match that daily number. Loaded main and bytecode are not part of this result.
 */
final class ConfiguredClamavSignatureObservation implements ClamavSignatureObservation
{
    public function __construct(
        private readonly ClamavLocalDatabaseVersions $files,
        private readonly ClamavPublishedTxtSource $dns,
        private readonly ClamdLoadedDailyVersion $loadedDaily,
    ) {
    }

    public function capture(): ?ClamavSignatureSnapshot
    {
        $disk = $this->files->read();
        $published = ClamavPublishedDatabaseVersion::published($this->dns->records());
        $loaded = $this->loadedDaily->read();
        if (null === $disk || 'ok' !== $published['status'] || !\is_array($published['versions']) || null === $loaded) {
            return null;
        }
        if ($disk !== $published['versions'] || $loaded !== $disk['daily']) {
            return null;
        }

        return new ClamavSignatureSnapshot($disk['main'], $disk['daily'], $disk['bytecode'], $loaded);
    }
}
