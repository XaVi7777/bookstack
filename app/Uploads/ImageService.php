<?php namespace BookStack\Uploads;

use BookStack\Auth\User;
use BookStack\Exceptions\HttpFetchException;
use BookStack\Exceptions\ImageUploadException;
use DB;
use ErrorException;
use Exception;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Filesystem\Factory as FileSystem;
use Illuminate\Contracts\Filesystem\Filesystem as FileSystemInstance;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Str;
use Intervention\Image\Exception\NotSupportedException;
use Intervention\Image\ImageManager;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ImageService
{

    protected $imageTool;
    protected $cache;
    protected $storageUrl;
    protected $image;
    protected $http;
    protected $fileSystem;

    /**
     * ImageService constructor.
     */
    public function __construct(Image $image, ImageManager $imageTool, FileSystem $fileSystem, Cache $cache, HttpFetcher $http)
    {
        $this->image = $image;
        $this->imageTool = $imageTool;
        $this->fileSystem = $fileSystem;
        $this->cache = $cache;
        $this->http = $http;
    }

    /**
     * Get the storage that will be used for storing images.
     */
    protected function getStorage(string $type = ''): FileSystemInstance
    {
        $storageType = config('filesystems.images');

        // Ensure system images (App logo) are uploaded to a public space
        if ($type === 'system' && $storageType === 'local_secure') {
            $storageType = 'local';
        }

        return $this->fileSystem->disk($storageType);
    }

    /**
     * Saves a new image from an upload.
     * @return mixed
     * @throws ImageUploadException
     */
    public function saveNewFromUpload(
        UploadedFile $uploadedFile,
        string $type,
        int $uploadedTo = 0,
        int $resizeWidth = null,
        int $resizeHeight = null,
        bool $keepRatio = true
    ) {
        $imageName = $uploadedFile->getClientOriginalName();
        $imageData = file_get_contents($uploadedFile->getRealPath());

        if ($resizeWidth !== null || $resizeHeight !== null) {
            $imageData = $this->resizeImage($imageData, $resizeWidth, $resizeHeight, $keepRatio);
        }

        return $this->saveNew($imageName, $imageData, $type, $uploadedTo);
    }

    /**
     * Save a new image from a uri-encoded base64 string of data.
     * @param string $base64Uri
     * @param string $name
     * @param string $type
     * @param int $uploadedTo
     * @return Image
     * @throws ImageUploadException
     */
    public function saveNewFromBase64Uri(string $base64Uri, string $name, string $type, $uploadedTo = 0)
    {
        $splitData = explode(';base64,', $base64Uri);
        if (count($splitData) < 2) {
            throw new ImageUploadException("Invalid base64 image data provided");
        }
        $data = base64_decode($splitData[1]);
        return $this->saveNew($name, $data, $type, $uploadedTo);
    }

    /**
     * Gets an image from url and saves it to the database.
     * @param             $url
     * @param string $type
     * @param bool|string $imageName
     * @return mixed
     * @throws Exception
     */
    private function saveNewFromUrl($url, $type, $imageName = false)
    {
        $imageName = $imageName ? $imageName : basename($url);
        try {
            $imageData = $this->http->fetch($url);
        } catch (HttpFetchException $exception) {
            throw new Exception(trans('errors.cannot_get_image_from_url', ['url' => $url]));
        }
        return $this->saveNew($imageName, $imageData, $type);
    }

    /**
     * Save a new image into storage.
     * @throws ImageUploadException
     */
    private function saveNew(string $imageName, string $imageData, string $type, int $uploadedTo = 0): Image
    {
        $storage = $this->getStorage($type);
        $secureUploads = setting('app-secure-images');
        $fileName = $this->cleanImageFileName($imageName);

        $imagePath = '/uploads/images/' . $type . '/' . Date('Y-m') . '/';

        while ($storage->exists($imagePath . $fileName)) {
            $fileName = Str::random(3) . $fileName;
        }

        $fullPath = $imagePath . $fileName;
        if ($secureUploads) {
            $fullPath = $imagePath . Str::random(16) . '-' . $fileName;
        }

        try {
            $storage->put($fullPath, $imageData);
            $storage->setVisibility($fullPath, 'public');
        } catch (Exception $e) {
            throw new ImageUploadException(trans('errors.path_not_writable', ['filePath' => $fullPath]));
        }

        $imageDetails = [
            'name' => $imageName,
            'path' => $fullPath,
            'url' => $this->getPublicUrl($fullPath),
            'type' => $type,
            'uploaded_to' => $uploadedTo
        ];

        if (user()->id !== 0) {
            $userId = user()->id;
            $imageDetails['created_by'] = $userId;
            $imageDetails['updated_by'] = $userId;
        }

        $image = $this->image->newInstance();
        $image->forceFill($imageDetails)->save();
        return $image;
    }

    /**
     * Clean up an image file name to be both URL and storage safe.
     */
    protected function cleanImageFileName(string $name): string
    {
        $name = str_replace(' ', '-', $name);
        $nameParts = explode('.', $name);
        $extension = array_pop($nameParts);
        $name = implode('.', $nameParts);
        $name = Str::slug($name);

        if (strlen($name) === 0) {
            $name = Str::random(10);
        }

        return $name . '.' . $extension;
    }

    /**
     * Checks if the image is a gif. Returns true if it is, else false.
     */
    protected function isGif(Image $image): bool
    {
        return strtolower(pathinfo($image->path, PATHINFO_EXTENSION)) === 'gif';
    }

    /**
     * Get the thumbnail for an image.
     * If $keepRatio is true only the width will be used.
     * Checks the cache then storage to avoid creating / accessing the filesystem on every check.
     * @param Image $image
     * @param int $width
     * @param int $height
     * @param bool $keepRatio
     * @return string
     * @throws Exception
     * @throws ImageUploadException
     */
    public function getThumbnail(Image $image, $width = 220, $height = 220, $keepRatio = false)
    {
        if ($keepRatio && $this->isGif($image)) {
            return $this->getPublicUrl($image->path);
        }

        $thumbDirName = '/' . ($keepRatio ? 'scaled-' : 'thumbs-') . $width . '-' . $height . '/';
        $imagePath = $image->path;
        $thumbFilePath = dirname($imagePath) . $thumbDirName . basename($imagePath);

        if ($this->cache->has('images-' . $image->id . '-' . $thumbFilePath) && $this->cache->get('images-' . $thumbFilePath)) {
            return $this->getPublicUrl($thumbFilePath);
        }

        $storage = $this->getStorage($image->type);
        if ($storage->exists($thumbFilePath)) {
            return $this->getPublicUrl($thumbFilePath);
        }

        $thumbData = $this->resizeImage($storage->get($imagePath), $width, $height, $keepRatio);

        $storage->put($thumbFilePath, $thumbData);
        $storage->setVisibility($thumbFilePath, 'public');
        $this->cache->put('images-' . $image->id . '-' . $thumbFilePath, $thumbFilePath, 60 * 60 * 72);


        return $this->getPublicUrl($thumbFilePath);
    }

    /**
     * Resize image data.
     * @param string $imageData
     * @param int $width
     * @param int $height
     * @param bool $keepRatio
     * @return string
     * @throws ImageUploadException
     */
    protected function resizeImage(string $imageData, $width = 220, $height = null, bool $keepRatio = true)
    {
        try {
            $thumb = $this->imageTool->make($imageData);
        } catch (Exception $e) {
            if ($e instanceof ErrorException || $e instanceof NotSupportedException) {
                throw new ImageUploadException(trans('errors.cannot_create_thumbs'));
            }
            throw $e;
        }

        if ($keepRatio) {
            $thumb->resize($width, $height, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
        } else {
            $thumb->fit($width, $height);
        }

        $thumbData = (string)$thumb->encode();

        // Use original image data if we're keeping the ratio
        // and the resizing does not save any space.
        if ($keepRatio && strlen($thumbData) > strlen($imageData)) {
            return $imageData;
        }

        return $thumbData;
    }

    /**
     * Get the raw data content from an image.
     * @throws FileNotFoundException
     */
    public function getImageData(Image $image): string
    {
        $imagePath = $image->path;
        $storage = $this->getStorage();
        return $storage->get($imagePath);
    }

    /**
     * Destroy an image along with its revisions, thumbnails and remaining folders.
     * @throws Exception
     */
    public function destroy(Image $image)
    {
        $this->destroyImagesFromPath($image->path);
        $image->delete();
    }

    /**
     * Destroys an image at the given path.
     * Searches for image thumbnails in addition to main provided path.
     */
    protected function destroyImagesFromPath(string $path): bool
    {
        $storage = $this->getStorage();

        $imageFolder = dirname($path);
        $imageFileName = basename($path);
        $allImages = collect($storage->allFiles($imageFolder));

        // Delete image files
        $imagesToDelete = $allImages->filter(function ($imagePath) use ($imageFileName) {
            return basename($imagePath) === $imageFileName;
        });
        $storage->delete($imagesToDelete->all());

        // Cleanup of empty folders
        $foldersInvolved = array_merge([$imageFolder], $storage->directories($imageFolder));
        foreach ($foldersInvolved as $directory) {
            if ($this->isFolderEmpty($storage, $directory)) {
                $storage->deleteDirectory($directory);
            }
        }

        return true;
    }

    /**
     * Check whether or not a folder is empty.
     */
    protected function isFolderEmpty(FileSystemInstance $storage, string $path): bool
    {
        $files = $storage->files($path);
        $folders = $storage->directories($path);
        return (count($files) === 0 && count($folders) === 0);
    }

    /**
     * Save an avatar image from an external service.
     * @throws Exception
     */
    public function saveUserAvatar(User $user, int $size = 500): Image
    {
        $avatarUrl = $this->getAvatarUrl();
        $email = strtolower(trim($user->email));

        $replacements = [
            '${hash}' => md5($email),
            '${size}' => $size,
            '${email}' => urlencode($email),
        ];

        $userAvatarUrl = strtr($avatarUrl, $replacements);
        $imageName = str_replace(' ', '-', $user->name . '-avatar.png');
        $image = $this->saveNewFromUrl($userAvatarUrl, 'user', $imageName);
        $image->created_by = $user->id;
        $image->updated_by = $user->id;
        $image->uploaded_to = $user->id;
        $image->save();

        return $image;
    }

    /**
     * Check if fetching external avatars is enabled.
     */
    public function avatarFetchEnabled(): bool
    {
        $fetchUrl = $this->getAvatarUrl();
        return is_string($fetchUrl) && strpos($fetchUrl, 'http') === 0;
    }

    /**
     * Get the URL to fetch avatars from.
     * @return string|mixed
     */
    protected function getAvatarUrl()
    {
        $url = trim(config('services.avatar_url'));

        if (empty($url) && !config('services.disable_services')) {
            $url = 'https://www.gravatar.com/avatar/${hash}?s=${size}&d=identicon';
        }

        return $url;
    }

    /**
     * Delete gallery and drawings that are not within HTML content of pages or page revisions.
     * Checks based off of only the image name.
     * Could be much improved to be more specific but kept it generic for now to be safe.
     *
     * Returns the path of the images that would be/have been deleted.
     * @param bool $checkRevisions
     * @param bool $dryRun
     * @param array $types
     * @return array
     */
    public function deleteUnusedImages($checkRevisions = true, $dryRun = true, $types = ['gallery', 'drawio'])
    {
        $types = array_intersect($types, ['gallery', 'drawio']);
        $deletedPaths = [];

        $this->image->newQuery()->whereIn('type', $types)
            ->chunk(1000, function ($images) use ($types, $checkRevisions, &$deletedPaths, $dryRun) {
                foreach ($images as $image) {
                    $searchQuery = '%' . basename($image->path) . '%';
                    $inPage = DB::table('pages')
                            ->where('html', 'like', $searchQuery)->count() > 0;
                    $inRevision = false;
                    if ($checkRevisions) {
                        $inRevision = DB::table('page_revisions')
                                ->where('html', 'like', $searchQuery)->count() > 0;
                    }

                    if (!$inPage && !$inRevision) {
                        $deletedPaths[] = $image->path;
                        if (!$dryRun) {
                            $this->destroy($image);
                        }
                    }
                }
            });
        return $deletedPaths;
    }

    /**
     * Convert a image URI to a Base64 encoded string.
     * Attempts to convert the URL to a system storage url then
     * fetch the data from the disk or storage location.
     * Returns null if the image data cannot be fetched from storage.
     * @throws FileNotFoundException
     */
    public function imageUriToBase64(string $uri): ?string
    {
        $storagePath = $this->imageUrlToStoragePath($uri);
        if (empty($uri) || is_null($storagePath)) {
            return null;
        }

        $storage = $this->getStorage();
        $imageData = null;
        if ($storage->exists($storagePath)) {
            $imageData = $storage->get($storagePath);
        }

        if (is_null($imageData)) {
            return null;
        }

        $extension = pathinfo($uri, PATHINFO_EXTENSION);
        if ($extension === 'svg') {
            $extension = 'svg+xml';
        }

        return 'data:image/' . $extension . ';base64,' . base64_encode($imageData);
    }

    /**
     * Get a storage path for the given image URL.
     * Ensures the path will start with "uploads/images".
     * Returns null if the url cannot be resolved to a local URL.
     */
    private function imageUrlToStoragePath(string $url): ?string
    {
        $url = ltrim(trim($url), '/');

        // Handle potential relative paths
        $isRelative = strpos($url, 'http') !== 0;
        if ($isRelative) {
            if (strpos(strtolower($url), 'uploads/images') === 0) {
                return trim($url, '/');
            }
            return null;
        }

        // Handle local images based on paths on the same domain
        $potentialHostPaths = [
            url('uploads/images/'),
            $this->getPublicUrl('/uploads/images/'),
        ];

        foreach ($potentialHostPaths as $potentialBasePath) {
            $potentialBasePath = strtolower($potentialBasePath);
            if (strpos(strtolower($url), $potentialBasePath) === 0) {
                return 'uploads/images/' . trim(substr($url, strlen($potentialBasePath)), '/');
            }
        }

        return null;
    }

    /**
     * Gets a public facing url for an image by checking relevant environment variables.
     * If s3-style store is in use it will default to guessing a public bucket URL.
     */
    private function getPublicUrl(string $filePath): string
    {
        if ($this->storageUrl === null) {
            $storageUrl = config('filesystems.url');

            // Get the standard public s3 url if s3 is set as storage type
            // Uses the nice, short URL if bucket name has no periods in otherwise the longer
            // region-based url will be used to prevent http issues.
            if ($storageUrl == false && config('filesystems.images') === 's3') {
                $storageDetails = config('filesystems.disks.s3');
                if (strpos($storageDetails['bucket'], '.') === false) {
                    $storageUrl = 'https://' . $storageDetails['bucket'] . '.s3.amazonaws.com';
                } else {
                    $storageUrl = 'https://s3-' . $storageDetails['region'] . '.amazonaws.com/' . $storageDetails['bucket'];
                }
            }
            $this->storageUrl = $storageUrl;
        }

        $basePath = ($this->storageUrl == false) ? url('/') : $this->storageUrl;
        return rtrim($basePath, '/') . $filePath;
    }
}
