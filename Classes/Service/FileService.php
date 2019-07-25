<?php
namespace Innologi\TYPO3ExtUpdate\Service;

use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use Innologi\TYPO3FalApi\FileReferenceRepository;

/**
 * Ext Update File Service
 *
 * Provides several file methods for common use-cases in ext-update context.
 * Note that it must be instantiated with the ObjectManager!
 *
 * @package TYPO3ExtUpdate
 * @author Frenck Lutke
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 2 or later
 */
class FileService implements SingletonInterface
{

    /**
     *
     * @var ResourceFactory
     */
    protected $resourceFactory;

    /**
     *
     * @var FileReferenceRepository
     */
    protected $fileReferenceRepository;

    /**
     *
     * @param ResourceFactory $resourceFactory
     * @return void
     */
    public function injectResourceFactory(ResourceFactory $resourceFactory)
    {
        $this->resourceFactory = $resourceFactory;
    }

    /**
     *
     * @param FileReferenceRepository $fileReferenceRepository
     * @return void
     */
    public function injectFileReferenceRepository(FileReferenceRepository $fileReferenceRepository)
    {
        $this->fileReferenceRepository = $fileReferenceRepository;
    }

    /**
     * Retrieve an existing FAL file object, or create a new one if
     * it doesn't exist and return it.
     *
     * @param string $path
     * @return \TYPO3\CMS\Core\Resource\File
     * @throws Exception\FileDoesNotExist
     * @throws Exception\NotInDocumentRoot
     */
    public function retrieveFileObjectByPath(string $path): File
    {
        try {
            if (! is_file($path) || ! file_exists($path)) {
                throw new ResourceDoesNotExistException();
            }
            if (strpos($path, PATH_site) !== 0) {
                throw new Exception\NotInDocumentRoot(1448613689, [
                    $path,
                    PATH_site
                ]);
            }
            // this method creates the record if one does not yet exist
            return $this->resourceFactory->retrieveFileOrFolderObject($path);
        } catch (ResourceDoesNotExistException $e) {
            throw new Exception\FileDoesNotExist(1448614638, [
                $path
            ]);
        }
    }

    /**
     * Sets a new file reference
     *
     * @param integer $fileUid
     * @param string $foreignTable
     * @param integer $foreignUid
     * @param string $foreignField
     * @param integer $pid
     * @return void
     */
    public function setFileReference(int $fileUid, string $foreignTable, int $foreignUid, string $foreignField, int $pid): void
    {
        $this->fileReferenceRepository->setStoragePid($pid);
        $this->fileReferenceRepository->addRecord($fileUid, $foreignTable, $foreignUid, $foreignField);
    }

    /**
     * Returns a File Object.
     *
     * @param integer $uid
     * @return \TYPO3\CMS\Core\Resource\File
     */
    public function getFileObjectByUid(int $uid): File
    {
        return $this->resourceFactory->getFileObject($uid);
    }

    /**
     * Returns default FAL storage object.
     *
     * @return \TYPO3\CMS\Core\Resource\ResourceStorage
     */
    public function getDefaultStorage(): ResourceStorage
    {
        return $this->resourceFactory->getDefaultStorage();
    }
}
