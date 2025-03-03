<?php

namespace Stepanenko3\NovaFilemanager\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class DefaultNamingStrategy extends AbstractNamingStrategy
{
    /**
     * @param string $currentFolder
     * @param UploadedFile $file
     * @return mixed
     */
    public function name(string $currentFolder, UploadedFile $file): string
    {
        $filename = $file->getClientOriginalName();

        while ($this->storage->exists($currentFolder . '/' . $filename)) {
            $filename = sprintf(
                '%s_%s.%s',
                pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                Str::random(7),
                $file->getClientOriginalExtension()
            );
        }

        return $filename;
    }
}
