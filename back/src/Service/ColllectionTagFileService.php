<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Exception\FilesystemCannotWriteException;
use App\Exception\TagAlreadyExistsException;
use App\Model\Tag;
use App\Service\FilesystemAdapter\EnhancedFilesystem\EnhancedFilesystemInterface;
use App\Service\FilesystemAdapter\FilesystemAdapterManager;
use App\Util\ColllectionPath;
use Exception;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToWriteFile;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Security;

class ColllectionTagFileService
{
    private const TAGS_FILE = '.tags.colllect';

    private EnhancedFilesystemInterface $filesystem;

    /** @var array<string, array<Tag>> */
    private array $tagsFilesCache = [];

    /**
     * ColllectionTagFileService constructor.
     *
     * @throws Exception
     */
    public function __construct(
        Security $security,
        FilesystemAdapterManager $flysystemAdapters,
    ) {
        $user = $security->getUser();

        if (!$user instanceof User) {
            throw new Exception('$user must be instance of ' . User::class);
        }

        $this->filesystem = $flysystemAdapters->getFilesystem($user);
    }

    /**
     * Get all colllection tags.
     *
     * @param string $encodedColllectionPath Base 64 encoded colllection path
     *
     * @return Tag[]
     *
     * @throws FilesystemException
     */
    public function getAll(string $encodedColllectionPath): array
    {
        if (\array_key_exists($encodedColllectionPath, $this->tagsFilesCache)) {
            return $this->tagsFilesCache[$encodedColllectionPath];
        }

        $tagsFilePath = $this->getTagsFilePath($encodedColllectionPath);

        // If tags file does not exists, return an empty array
        if (!$this->filesystem->fileExists($tagsFilePath)) {
            $this->tagsFilesCache[$encodedColllectionPath] = [];

            return [];
        }

        $tagsFileContent = $this->filesystem->read($tagsFilePath);

        try {
            /** @var array<string, array<string, string>> $flatTags */
            $flatTags = \GuzzleHttp\Utils::jsonDecode($tagsFileContent, true);
        } catch (Exception) {
            return [];
        }

        $tags = [];
        foreach ($flatTags as $flatTag) {
            $tags[] = new Tag($flatTag);
        }

        $this->tagsFilesCache[$encodedColllectionPath] = $tags;

        return $tags;
    }

    /**
     * Get a colllection tag.
     *
     * @param string $encodedColllectionPath Base 64 encoded colllection path
     * @param string $tagName                The searched tag name
     *
     * @throws FilesystemException
     */
    public function get(string $encodedColllectionPath, string $tagName): Tag
    {
        $tags = $this->getAll($encodedColllectionPath);

        $filteredTags = array_filter(
            $tags,
            fn (Tag $tag): bool => $tag->getName() === $tagName
        );

        if ($filteredTags === []) {
            throw new NotFoundHttpException('error.tag_not_found');
        }

        // We don't want to keep reference
        /** @var Tag|null $tag */
        $tag = clone array_shift($filteredTags);

        if (!$tag instanceof Tag) {
            throw new NotFoundHttpException('error.tag_not_found');
        }

        return $tag;
    }

    /**
     * @throws TagAlreadyExistsException
     * @throws FilesystemException
     */
    public function add(string $encodedColllectionPath, Tag $tag): void
    {
        // Check if tag does not already exists
        if ($this->has($encodedColllectionPath, $tag)) {
            throw new TagAlreadyExistsException();
        }

        // Add the tag to tag list
        $this->tagsFilesCache[$encodedColllectionPath][] = $tag;
    }

    /**
     * @throws FilesystemException
     */
    public function remove(string $encodedColllectionPath, Tag $tag): void
    {
        // Check if tag does not already exists
        if (!$this->has($encodedColllectionPath, $tag)) {
            throw new NotFoundHttpException('error.tag_not_found');
        }

        // remove the tag from the list
        $this->tagsFilesCache[$encodedColllectionPath] = array_filter(
            $this->tagsFilesCache[$encodedColllectionPath],
            fn (Tag $existingTag): bool => $existingTag->getName() !== $tag->getName()
        );
    }

    /**
     * @throws FilesystemCannotWriteException
     * @throws FilesystemException
     */
    public function save(string $encodedColllectionPath): void
    {
        // Remap tag objects to flat array
        $flatTags = array_map(
            fn (Tag $tag): array => [
                'name' => $tag->getName(),
            ],
            $this->getall($encodedColllectionPath)
        );
        $tagsFileContent = \GuzzleHttp\Utils::jsonEncode($flatTags);

        $tagsFilePath = $this->getTagsFilePath($encodedColllectionPath);

        // Put new tag list info Colllection tags file
        try {
            $this->filesystem->write($tagsFilePath, $tagsFileContent);
        } catch (UnableToWriteFile|FilesystemException) {
            throw new FilesystemCannotWriteException();
        }
    }

    /**
     * Colllection has tagName.
     *
     * @param string $encodedColllectionPath Base 64 encoded colllection path
     * @param Tag    $tag                    Colllection tag to find
     *
     * @throws FilesystemException
     */
    public function has(string $encodedColllectionPath, Tag $tag): bool
    {
        $tags = $this->getAll($encodedColllectionPath);

        $existingTagNames = array_map(
            fn (Tag $tag): string => $tag->getName(),
            $tags
        );

        $hasTag = \in_array($tag->getName(), $existingTagNames, true);

        return $hasTag;
    }

    /**
     * Get tags file path from a colllection.
     *
     * @param string $encodedColllectionPath Base 64 encoded colllection path
     *
     * @return string Colllection tags file path
     */
    private function getTagsFilePath(string $encodedColllectionPath): string
    {
        $colllectionPath = ColllectionPath::decode($encodedColllectionPath);
        $tagsFilePath = $colllectionPath . '/' . self::TAGS_FILE;

        return $tagsFilePath;
    }
}
