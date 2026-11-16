<?php

namespace App\LocalUpdate;

final class SyncResult
{
    /** @var list<string> */
    public array $added = [];
    /** @var list<string> */
    public array $modified = [];
    /** @var list<string> */
    public array $deleted = [];
    /** @var list<string> */
    public array $preserved = [];
    public int $unchanged = 0;
    /** @var list<string> Fichiers présents dans l'archive (manifeste du déploiement) */
    public array $deployedFiles = [];
    public ?string $backupDirectory = null;

    public function toSummary(): array
    {
        return [
            'added' => \count($this->added),
            'modified' => \count($this->modified),
            'deleted' => \count($this->deleted),
            'unchanged' => $this->unchanged,
            'preserved' => \count($this->preserved),
            'backup' => $this->backupDirectory,
        ];
    }
}
