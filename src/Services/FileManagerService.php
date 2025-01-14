<?php

namespace Stepanenko3\NovaFilemanager\Services;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Stepanenko3\NovaFilemanager\Events\FileRemoved;
use Stepanenko3\NovaFilemanager\Events\FileUploaded;
use Stepanenko3\NovaFilemanager\Events\FolderRemoved;
use Stepanenko3\NovaFilemanager\Events\FolderUploaded;
use Stepanenko3\NovaFilemanager\Http\Exceptions\InvalidConfig;
use InvalidArgumentException;

class FileManagerService
{
    use GetFiles;

    /**
     * @var mixed
     */
    protected $storage;

    /**
     * @var mixed
     */
    protected $disk;

    /**
     * @var mixed
     */
    protected $currentPath;

    /**
     * @var mixed
     */
    protected $exceptFiles;

    /**
     * @var mixed
     */
    protected $exceptFolders;

    /**
     * @var mixed
     */
    protected $exceptExtensions;

    /**
     * @var AbstractNamingStrategy
     */
    protected $namingStrategy;

    /**
     * @param Storage $storage
     */
    public function __construct()
    {
        $this->disk = config('filemanager.disk', 'public');

        $this->exceptFiles = collect([
            '.DS_Store',
        ]);
        $this->exceptFolders = collect([]);
        $this->exceptExtensions = collect([]);
        $this->globalFilter = null;

        try {
            $this->storage = Storage::disk($this->disk);
        } catch (InvalidArgumentException $e) {
            throw InvalidConfig::driverNotSupported();
        }

        $this->namingStrategy = app()->makeWith(
            config('filemanager.naming', DefaultNamingStrategy::class),
            ['storage' => $this->storage]
        );
    }

    /**
     * Get ajax request to load files and folders.
     *
     * @param Request $request
     *
     * @return json
     */
    public function ajaxGetFilesAndFolders(Request $request)
    {
        $folder = $this->cleanSlashes($request->get('folder'));

        if (!$this->folderExists($folder)) {
            $folder = '/';
        }

        $this->setRelativePath($folder);

        $order = $request->get('sort');

        if (!$order) {
            $order = config('filemanager.order', 'mime');
        }

        $filter = $request->get('filter', config('filemanager.filter', false));

        $files = $this->getFiles($folder, $order, $filter);

        $filters = $this->getAvailableFilters($files);

        $parent = (object) [];

        if ($files->count() > 0) {
            if ($folder !== '/') {
                $parent = $this->generateParent($folder);
            }
        }

        return response()->json([
            'files' => $files,
            'path' => $this->getPaths($folder),
            'filters' => $filters,
            'buttons' => $this->getButtons(),
            'parent' => $parent,
        ]);
    }

    /**
     *  Create a folder on current path.
     *
     * @param $folder
     * @param $path
     *
     * @return  json
     */
    public function createFolderOnPath($folder, $currentFolder)
    {
        $folder = $this->fixDirname($this->fixFilename($folder));

        $path = $currentFolder . '/' . $folder;

        if ($this->storage->has($path)) {
            return response()->json(['error' => __('The folder exist in current path')]);
        }

        if ($this->storage->makeDirectory($path)) {
            return response()->json(true);
        } else {
            return response()->json(false);
        }
    }

    /**
     * Removes a directory.
     *
     * @param string $path
     *
     * @return  json
     */
    public function deleteDirectory($path)
    {
        if ($this->storage->deleteDirectory($path)) {
            event(new FolderRemoved($this->disk, $path));

            return response()->json(true);
        } else {
            return response()->json(false);
        }
    }

    /**
     * Upload a file on current folder.
     *
     * @param $file
     * @param $currentFolder
     *
     * @return  json
     */
    public function uploadFile($file, $currentFolder, $visibility, $uploadingFolder = false, array $rules = [])
    {
        if (count($rules) > 0) {
            $pases = Validator::make(['file' => $file], [
                'file' => $rules,
            ])->validate();
        }

        $fileName = $this->namingStrategy->name($currentFolder, $file);

        if ($this->storage->putFileAs($currentFolder, $file, $fileName)) {
            $this->setVisibility($currentFolder, $fileName, $visibility);

            if (!$uploadingFolder) {
                $this->checkJobs($this->disk, $currentFolder . $fileName);
                event(new FileUploaded($this->disk, $currentFolder . $fileName));
            }

            return response()->json(['success' => true, 'name' => $fileName]);
        } else {
            return response()->json(['success' => false]);
        }
    }

    /**
     * @param $file
     * @return mixed
     */
    public function downloadFile($file)
    {
        if (!config('filemanager.buttons.download_file')) {
            return response()->json(['success' => false, 'message' => 'File not available for Download'], 403);
        }

        if ($this->storage->has($file)) {
            return $this->storage->download($file);
        } else {
            return response()->json(['success' => false, 'message' => 'File not found'], 404);
        }
    }

    /**
     * Get info of file normalized.
     *
     * @param $file
     *
     * @return  json
     */
    public function getFileInfo($file)
    {
        try {
            $info = new NormalizeFile(
                storage: $this->storage,
                path: $file,
                withExtras: true,
            );

            return response()->json($info->toArray());
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Get info of file as Array.
     *
     * @param $file
     *
     * @return  json
     */
    public function getFileInfoAsArray($file)
    {
        if (!$this->storage->exists($file)) {
            return [];
        }

        $info = new NormalizeFile(
            storage: $this->storage,
            path: $file,
        );

        return $info->toArray();
    }

    /**
     * Remove a file from storage.
     *
     * @param string $file
     * @param string $type
     *
     * @return  json
     */
    public function removeFile($file)
    {
        if ($this->storage->delete($file)) {
            event(new FileRemoved($this->disk, $file));

            return response()->json(true);
        } else {
            return response()->json(false);
        }
    }

    /**
     * @param $file
     */
    public function duplicateFile($file)
    {
        if ($this->storage->exists($file)) {
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            $basename = pathinfo($file, PATHINFO_BASENAME);
            $path = str_replace($basename, '', $file);

            if ($this->storage->directoryExists($file)) {
                // TODO: Make
            } else {
                if (preg_match('/(^.*?)+(?:\((\d+)\))?(\.(?:\w){0,3}$)/si', $basename, $match)) {
                    $matchName = $match[1];
                    $offset = (int) $match[2];
                    $newName = $matchName . '.' . $ext;

                    while ($this->storage->fileExists($path . $newName)) {
                        $offset = $offset + 1;
                        $newName = $matchName . '(' . $offset . ').' . $ext;
                    }
                } else {
                    $newName = $basename;
                }

                if ($this->storage->copy($file, $path . $newName)) {
                    $info = new NormalizeFile(
                        storage: $this->storage,
                        path: $path . $newName,
                    );

                    return response()->json(['success' => true, 'data' => $info->toArray()]);
                }

                return response()->json(false);
            }
        }
    }

    /**
     * @param $file
     */
    public function renameFile($file, $newName)
    {
        $path = str_replace(basename($file), '', $file);

        try {
            if ($this->storage->move($file, $path . $newName)) {
                $info = new NormalizeFile(
                    storage: $this->storage,
                    path: $path . $newName,
                );

                return response()->json(['success' => true, 'data' => $info->toArray()]);
            }

            return response()->json(false);
        } catch (\Exception $e) {
            $directories = $this->storage->directories($path);

            if (in_array($file, $directories)) {
                return $this->renameDirectory($file, $newName);
            }

            return response()->json(false);
        }
    }

    protected function renameDirectory($dir, $newName)
    {
        $path = str_replace(basename($dir), '', $dir);
        $newDir = $path . $newName;

        if ($this->storage->exists($newDir)) {
            return response()->json(false);
        }

        $this->storage->makeDirectory($newDir);

        $files = $this->storage->files($dir);
        $directories = $this->storage->directories($dir);

        $dirNameLength = strlen($dir);

        foreach ($directories as $subDir) {
            $subDirName = substr($dir, $dirNameLength);
            array_push($files, ...$this->storage->files($subDir));

            if (!Storage::exists($newDir . $subDirName)) {
                $this->storage->makeDirectory($newDir . $subDirName);
            }
        }

        $copiedFileCount = 0;

        foreach ($files as $file) {
            $filename = substr($file, $dirNameLength);
            $this->storage->copy($file, $newDir . $filename) === true ? $copiedFileCount++ : null;
        }

        if ($copiedFileCount === count($files)) {
            $this->storage->deleteDirectory($dir);
        }

        $info = new NormalizeFile(
            storage: $this->storage,
            path: $newDir,
        );

        return response()->json(['success' => true, 'data' => $info->toArray()]);
    }

    /**
     * Move file.
     *
     * @param   string  $oldPath
     * @param   string  $newPath
     *
     * @return  json
     */
    public function moveFile($oldPath, $newPath)
    {
        if ($this->storage->move($oldPath, $newPath)) {
            return response()->json(['success' => true]);
        }

        return response()->json(false);
    }

    /**
     * Folder uploaded event.
     *
     * @param   string  $path
     *
     * @return  json
     */
    public function folderUploadedEvent($path)
    {
        event(new FolderUploaded($this->disk, $path));

        return response()->json(['success' => true]);
    }

    /**
     * @param $folder
     */
    private function folderExists($folder)
    {
        $directories = $this->storage->directories(dirname($folder));

        $directories = collect($directories)->map(function ($folder) {
            return basename($folder);
        });

        return in_array(basename($folder), $directories->toArray());
    }

    /**
     * Set visibility to public.
     *
     * @param $folder
     * @param $file
     */
    private function setVisibility($folder, $file, $visibility)
    {
        if ($folder != '/') {
            $folder .= '/';
        }
        $this->storage->setVisibility($folder . $file, $visibility);
    }

    /**
     * @param $files
     */
    private function getAvailableFilters($files)
    {
        $filters = config('filemanager.filters', []);

        if (count($filters) > 0) {
            return collect($filters)
                ->filter(function ($extensions) use ($files) {
                    foreach ($files as $file) {
                        if (in_array($file['ext'], $extensions)) {
                            return true;
                        }
                    }

                    return false;
                })
                ->toArray();
        }

        return [];
    }

    private function getButtons()
    {
        return config('filemanager.buttons', [
            'create_folder' => true,
            'upload_button' => true,
            'select_multiple' => true,
            'upload_drag' => true,
            'rename_folder' => true,
            'delete_folder' => true,
            'rename_file' => true,
            'delete_file' => true,
        ]);
    }

    /**
     * @param $currentPath
     * @param $fileName
     */
    private function checkJobs($disk, $filePath)
    {
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);

        //$availableJobs
        $availableJobs = collect(config('filemanager.jobs', []));

        if (count($availableJobs)) {
            // Filters
            $filters = config('filemanager.filters', []);
            $filters = array_change_key_case($filters);

            $find = collect($filters)->filter(function ($extensions, $key) use ($ext) {
                if (in_array($ext, $extensions)) {
                    return true;
                }
            });

            $filterFind = array_key_first($find->toArray());

            if ($jobClass = $availableJobs->get($filterFind)) {
                $job = new $jobClass($disk, $filePath);

                if ($customQueue = config('filemanager.queue_name')) {
                    $job->onQueue($customQueue);
                }

                app(Dispatcher::class)->dispatch($job);
            }
        }
    }
}
