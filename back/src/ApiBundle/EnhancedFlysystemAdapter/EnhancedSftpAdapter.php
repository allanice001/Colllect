<?php

namespace ApiBundle\EnhancedFlysystemAdapter;

use League\Flysystem\Sftp\SftpAdapter;

class EnhancedSftpAdapter extends SftpAdapter implements EnhancedFlysystemAdapterInterface
{
    /**
     * {@inheritdoc}
     */
    public function renameDir(string $path, string $newPath): bool
    {
        return $this->rename($path, $newPath);
    }
}
