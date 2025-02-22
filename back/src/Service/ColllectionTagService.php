<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\FilesystemCannotWriteException;
use App\Exception\TagAlreadyExistsException;
use App\Form\TagType;
use App\Model\Element\AbstractElement;
use App\Model\ElementFile;
use App\Model\Tag;
use App\Util\Base64;
use League\Flysystem\FilesystemException;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class ColllectionTagService
{
    public function __construct(
        private ColllectionElementService $colllectionElementService,
        private ColllectionTagFileService $colllectionTagFileService,
        private FormFactoryInterface $formFactory
    ) {
    }

    /**
     * Get an array of tags from a colllection.
     *
     * @param string $encodedColllectionPath Base 64 encoded colllection path
     *
     * @return Tag[]
     *
     * @throws \Exception
     */
    public function list(string $encodedColllectionPath): array
    {
        return $this->colllectionTagFileService->getAll($encodedColllectionPath);
    }

    /**
     * Add a tag to a colllection.
     *
     * @param string $encodedColllectionPath Base 64 encoded colllection path
     *
     * @throws FilesystemCannotWriteException
     * @throws TagAlreadyExistsException
     */
    public function create(string $encodedColllectionPath, Request $request): Tag|FormInterface
    {
        $tag = new Tag();
        $form = $this->formFactory->create(TagType::class, $tag);
        $requestContent = $request->request->all();
        $form->submit($requestContent, false);

        if (!$form->isValid()) {
            return $form;
        }

        $this->colllectionTagFileService->add($encodedColllectionPath, $tag);
        $this->colllectionTagFileService->save($encodedColllectionPath);

        return $tag;
    }

    /**
     * Get a tag from a colllection.
     *
     * @param string $encodedColllectionPath Base 64 encoded colllection path
     * @param string $encodedTagName         Base 64 encoded tag name
     *
     * @throws \Exception
     */
    public function get(string $encodedColllectionPath, string $encodedTagName): Tag
    {
        if (!Base64::isValidBase64($encodedTagName)) {
            throw new BadRequestHttpException('request.badly_encoded_tag_name');
        }
        $tagName = Base64::decode($encodedTagName);

        $tag = $this->colllectionTagFileService->get($encodedColllectionPath, $tagName);

        return $tag;
    }

    /**
     * Update a tag from a colllection.
     *
     * @param string $encodedColllectionPath Base 64 encoded colllection path
     * @param string $encodedTagName         Base 64 encoded tag name
     *
     * @throws FilesystemCannotWriteException
     * @throws FilesystemException
     * @throws TagAlreadyExistsException
     * @throws \Exception
     */
    public function update(string $encodedColllectionPath, string $encodedTagName, Request $request): Tag|FormInterface
    {
        $tag = $this->get($encodedColllectionPath, $encodedTagName);
        $oldTag = clone $tag;

        $form = $this->formFactory->create(TagType::class, $tag);
        $requestContent = $request->request->all();
        $form->submit($requestContent, false);

        if (!$form->isValid()) {
            return $form;
        }

        // If tag has not changed, just return the old one
        if ($oldTag->getName() === $tag->getName()) {
            return $oldTag;
        }

        // Add the new tag (throws if tag name already exists)
        $this->colllectionTagFileService->add($encodedColllectionPath, $tag);

        // Rename all elements which has this tag
        $this->colllectionElementService->batchRename(
            $encodedColllectionPath,
            fn (AbstractElement $element): bool => \in_array($oldTag->getName(), $element->getTags(), true),
            function (ElementFile $elementFile) use ($oldTag, $tag): void {
                $elementFile
                    ->removeTag($oldTag->getName())
                    ->addTag($tag->getName())
                ;
            }
        );

        // Remove the old one and save the tag file
        $this->colllectionTagFileService->remove($encodedColllectionPath, $oldTag);
        $this->colllectionTagFileService->save($encodedColllectionPath);

        return $tag;
    }

    /**
     * Delete a tag from a colllection.
     *
     * @param string $encodedColllectionPath Base 64 encoded colllection path
     * @param string $encodedTagName         Base 64 encoded tag name
     *
     * @throws FilesystemCannotWriteException
     * @throws FilesystemException
     * @throws \Exception
     */
    public function delete(string $encodedColllectionPath, string $encodedTagName): void
    {
        $tag = $this->get($encodedColllectionPath, $encodedTagName);

        // Add the new tag (throws if tag name already exists)
        $this->colllectionTagFileService->remove($encodedColllectionPath, $tag);

        // Rename all elements which has this tag
        $this->colllectionElementService->batchRename(
            $encodedColllectionPath,
            fn (AbstractElement $element): bool => \in_array($tag->getName(), $element->getTags(), true),
            function (ElementFile $elementFile) use ($tag): void {
                $elementFile->removeTag($tag->getName());
            }
        );

        // Save the tag file
        $this->colllectionTagFileService->save($encodedColllectionPath);
    }
}
